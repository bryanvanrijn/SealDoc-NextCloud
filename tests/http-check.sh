#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Exercises the admin settings endpoints the way a browser does: a real login,
# a real session cookie, a real request token, over HTTP.
#
# WHY THIS EXISTS. tests/registration-check.php runs inside PHP and proves the
# server half. It passed every single time while the settings screen was
# completely dead, because all three faults lived on the other side of the
# request:
#
#   1. the script called axios, which is not a global in Nextcloud 30, so no
#      request ever left the browser;
#   2. #[AuthorizedAdminSetting] pointed at a class that did not implement
#      IDelegatedSettings, which type-checks and then rejects at runtime;
#   3. the app version was not bumped, so Nextcloud kept serving the old file.
#
# Each of those is invisible to PHP-side checks and to `occ app:enable`.
#
# Usage:
#   NC_URL=http://localhost:8091 NC_USER=... NC_PASS=... bash tests/http-check.sh
#
# Read-mostly: the only write is a PUT with an empty body, which stores the
# values already stored, so running it against a configured instance is a no-op.
set -uo pipefail

NC_URL="${NC_URL:-http://localhost:8080}"
NC_USER="${NC_USER:-}"
NC_PASS="${NC_PASS:-}"

if [ -z "$NC_USER" ] || [ -z "$NC_PASS" ]; then
	echo "Set NC_USER and NC_PASS. Credentials are never read from the repo." >&2
	exit 2
fi

JAR="$(mktemp)"
trap 'rm -f "$JAR"' EXIT

pass=0
fail=0
check() {
	if [ "$2" = "1" ]; then
		echo "  ok    $1"
		pass=$((pass + 1))
	else
		echo "  FAIL  $1"
		fail=$((fail + 1))
	fi
}

# Nextcloud puts the request token in a data attribute on <head>.
#
# The substitution is anchored at the start on purpose. A request token is
# base64 and therefore usually ENDS in '=', so a greedy 's/.*="//' matches all
# the way to that final '=" ' and returns an empty string.
token_from() {
	grep -o 'data-requesttoken="[^"]*"' <<<"$1" | head -1 | sed 's/^data-requesttoken="//;s/"$//'
}

echo "HTTP check against $NC_URL"
echo

login_page="$(curl -sS -c "$JAR" "$NC_URL/login")"
token="$(token_from "$login_page")"
check "login page returns a request token" "$([ -n "$token" ] && echo 1 || echo 0)"

# --data-urlencode, not -d.
#
# A request token is base64 and regularly contains '+', which in a form body
# means a space. With plain -d the server receives a corrupted token, refuses
# the session, and every later check reports "not logged in" while the login
# page itself fetched perfectly. It only fails when the random token happens to
# contain a '+', so it passes roughly half the time, which is the worst kind of
# test failure to inherit. The password gets the same treatment for the same
# reason.
login_status="$(curl -sS -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code}' \
	--data-urlencode "user=$NC_USER" \
	--data-urlencode "password=$NC_PASS" \
	--data-urlencode "requesttoken=$token" \
	"$NC_URL/login")"
check "login POST creates a session (303 = redirect to the dashboard)" \
	"$([ "$login_status" = "303" ] && echo 1 || echo 0)"

settings="$(curl -sS -b "$JAR" -c "$JAR" "$NC_URL/settings/admin/sealdoc")"
token="$(token_from "$settings")"
check "the SealDoc settings section loads for an admin" "$([ -n "$token" ] && echo 1 || echo 0)"

# A section that loads but renders nothing is the shape of a broken template,
# and it looks perfectly healthy in any status page.
check "settings page renders the server URL field" \
	"$(grep -q 'sealdoc-base-url' <<<"$settings" && echo 1 || echo 0)"
check "settings page renders the evidence fields" \
	"$(grep -q 'sealdoc-evidence-folder' <<<"$settings" && echo 1 || echo 0)"

# If a browser is served the axios build the screen is dead again, and nothing
# else reports it.
js="$(curl -sS -b "$JAR" "$NC_URL/custom_apps/sealdoc/js/admin.js" 2>/dev/null)"
if ! grep -q 'OC.requestToken' <<<"$js"; then
	js="$(curl -sS -b "$JAR" "$NC_URL/apps/sealdoc/js/admin.js" 2>/dev/null)"
fi
check "admin.js is served and uses fetch, not a global axios" \
	"$(grep -q 'OC.requestToken' <<<"$js" && echo 1 || echo 0)"

test_json="$(curl -sS -b "$JAR" -H "requesttoken: $token" \
	"$NC_URL/apps/sealdoc/config/test")"
check "GET config/test answers JSON instead of an auth error" \
	"$(grep -q '"ok"' <<<"$test_json" && echo 1 || echo 0)"
echo "        -> $test_json"

put_json="$(curl -sS -b "$JAR" -X PUT -H "requesttoken: $token" \
	-H 'Content-Type: application/json' -d '{}' \
	"$NC_URL/apps/sealdoc/config")"
check "PUT config is accepted and echoes the stored state" \
	"$(grep -q '"hasApiKey"' <<<"$put_json" && echo 1 || echo 0)"
echo "        -> $put_json"

echo
echo "$pass passed, $fail failed"
exit $((fail > 0 ? 1 : 0))

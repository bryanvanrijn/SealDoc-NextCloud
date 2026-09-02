/* SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Drives a real browser against a real Nextcloud and checks the things only a
 * browser can see.
 *
 * WHY THIS EXISTS. Every client-side fault this app has had was invisible to
 * the two checks that came before it. tests/registration-check.php runs inside
 * PHP; tests/http-check.sh speaks HTTP. Both were green while:
 *
 *   1. the settings screen called axios, which is not a global in Nextcloud
 *      30, so no request ever left the browser;
 *   2. "Seal with SealDoc" was hidden on every row by an instanceof check that
 *      quietly returned false;
 *   3. the shield never drew after a refresh, because the Files app had
 *      already asked for its listing before our registerDavProperty() ran.
 *
 * The third one is the reason for the first check below. It was intermittent
 * in the cruellest way: navigating around inside the Files app made the shield
 * appear, and reloading the page made it vanish, so "it worked yesterday" and
 * "it is broken now" were both true statements about the same code.
 *
 * Usage:
 *
 *   NC_URL=https://cloud.example.test \
 *   NC_USER=... NC_PASS=... \
 *   NC_SEALED_FILE=invoice-sealed.pdf \
 *   node tests/browser-check.cjs
 *
 * NC_URL must be the URL the instance considers its own. Nextcloud sets Secure
 * cookies when overwriteprotocol is https, and a browser then stores none of
 * them over plain http: the login form posts, succeeds, and lands back on the
 * login page with no session and no error. curl does not care, which is what
 * makes that an hour of confusion.
 *
 * NC_SEALED_FILE is optional. Without it the shield assertions are reported as
 * skipped rather than passed, because a check that cannot fail is not a check.
 *
 * Playwright is not a dependency of this app. Point PLAYWRIGHT to an existing
 * install, or npm i -D playwright first.
 */
'use strict'

const BASE = process.env.NC_URL || ''
const USER = process.env.NC_USER || ''
const PASS = process.env.NC_PASS || ''
const SEALED = process.env.NC_SEALED_FILE || ''
const UNSEALED = process.env.NC_UNSEALED_FILE || 'Readme.md'

if (!BASE || !USER || !PASS) {
	console.error('Set NC_URL, NC_USER and NC_PASS. Credentials are never read from the repo.')
	process.exit(2)
}

let playwright
try {
	playwright = require(process.env.PLAYWRIGHT || 'playwright')
} catch (e) {
	console.error('Playwright not found. npm i -D playwright, or set PLAYWRIGHT to an install.')
	process.exit(2)
}

let pass = 0
let fail = 0
let skip = 0

const check = (what, ok) => {
	console.log((ok ? '  ok    ' : '  FAIL  ') + what)
	ok ? pass++ : fail++
}
const skipped = (what, why) => {
	console.log('  skip  ' + what + ' (' + why + ')')
	skip++
}

;(async () => {
	const browser = await playwright.chromium.launch()
	const context = await browser.newContext({ ignoreHTTPSErrors: true })
	const page = await context.newPage()

	const propfinds = []
	const crashes = []
	page.on('request', (r) => {
		if (r.method() === 'PROPFIND') {
			propfinds.push({ url: r.url(), asked: (r.postData() || '').includes('sealdoc:sealed') })
		}
	})
	page.on('pageerror', (e) => crashes.push(String(e.message).slice(0, 200)))

	console.log('SealDoc browser check against ' + BASE + '\n')

	await page.goto(BASE + '/login', { waitUntil: 'domcontentloaded', timeout: 60000 })
	await page.fill('#user', USER)
	await page.fill('#password', PASS)
	await page.click('button[type=submit]')
	await page.waitForLoadState('networkidle', { timeout: 60000 }).catch(() => {})
	check('logs in', !/\/login/.test(page.url()))
	if (/\/login/.test(page.url())) {
		console.log('\n  Still on the login page. If NC_URL is http and the instance sets')
		console.log('  overwriteprotocol=https, the session cookie is dropped as insecure.')
		await browser.close()
		process.exit(1)
	}

	propfinds.length = 0
	await page.goto(BASE + '/index.php/apps/files/files', { waitUntil: 'networkidle', timeout: 60000 })
	await page.waitForTimeout(3000)

	// The one that matters. The listing is what fills the rows, and if it does
	// not carry our properties every node arrives without them and the shield
	// has nothing to decide on.
	const listing = propfinds.find((p) => /\/remote\.php\/dav\/files\/[^/]+\/?$/.test(p.url))
	check('the Files listing asks for the SealDoc properties', !!listing && listing.asked)

	const state = await page.evaluate(() => ({
		props: (window._nc_dav_properties || []).filter((p) => String(p).startsWith('sealdoc:')),
		ns: (window._nc_dav_namespaces || {}).sealdoc || null,
		actions: (window._nc_fileactions || []).map((a) => a.id).filter((id) => String(id).startsWith('sealdoc-')),
		tabs: (window.OCA && window.OCA.Files && window.OCA.Files.Sidebar
			&& window.OCA.Files.Sidebar._state && window.OCA.Files.Sidebar._state.tabs || []).map((t) => t.id),
	}))

	check('both DAV properties are registered', state.props.length === 2)
	check('the namespace is registered', state.ns === 'http://sealdoc.eu/ns')
	check('the seal action is registered', state.actions.includes('sealdoc-seal'))
	check('the shield action is registered', state.actions.includes('sealdoc-evidence'))
	check('the Seal sidebar tab is registered', state.tabs.includes('sealdoc'))

	const shieldOn = (name) => page.evaluate((n) => {
		const row = document.querySelector('[data-cy-files-list-row-name="' + n.replace(/"/g, '\\"') + '"]')
		if (!row) {
			return null
		}
		return [...row.querySelectorAll('button')]
			.some((b) => /sealed/i.test((b.getAttribute('aria-label') || b.title || '')))
	}, name)

	if (SEALED) {
		const on = await shieldOn(SEALED)
		check('the shield is drawn on ' + SEALED, on === true)
	} else {
		skipped('the shield is drawn on a sealed file', 'set NC_SEALED_FILE')
	}

	const off = await shieldOn(UNSEALED)
	if (off === null) {
		skipped('no shield on an unsealed file', UNSEALED + ' not in the listing')
	} else {
		check('no shield on ' + UNSEALED, off === false)
	}

	check('no uncaught errors on the page', crashes.length === 0)
	if (crashes.length) {
		crashes.forEach((c) => console.log('        ' + c))
	}

	await browser.close()
	console.log('\n' + pass + ' passed, ' + fail + ' failed, ' + skip + ' skipped')
	process.exit(fail === 0 ? 0 : 1)
})().catch((e) => {
	console.error('\nThe check itself broke: ' + e.message)
	process.exit(2)
})

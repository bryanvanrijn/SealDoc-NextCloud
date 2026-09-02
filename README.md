# SealDoc for Nextcloud

Nextcloud stores your documents. It does not, on its own, prove that a document has not changed
since you filed it, or that it existed on the date you claim.

For invoices, contracts and personnel records that have to survive a statutory retention period,
that difference only becomes visible at the moment somebody asks, which is usually years later and
often during a dispute.

This app connects Nextcloud to [SealDoc](https://www.sealdoc.eu). Every document it processes comes
back as:

- a **PDF/A-3B** file validated against ISO 19005-3,
- carrying an **RFC 3161 timestamp** from a trusted authority,
- a **SHA-384 hash chain**,
- and a **chain-of-custody** record,

exported as a single evidence pack that a third party can verify without access to the system that
produced it.

## How it works

Sealing runs as a **Flow** action. Under *Administration settings → Flow* you write a rule such as
*when a file is created in /Invoices, seal it*. The sealed file is written back next to the
original, so it keeps the folder and the sharing you already set up.

Sealing is **not instant**. Converting a document, obtaining a timestamp and downloading the result
takes seconds at best and can take a minute for a large scan. Doing that inside the upload request
would hold the upload open for that whole time, so the work is handed to a background job and the
sealed file appears shortly after the original.

## What it does not do

Stated up front, because arriving here for the wrong thing wastes your afternoon:

- **No electronic signatures.** This proves a document has not changed. It does not prove who
  agreed to it. Those are different questions, and signing tools answer the second one.
- **The timestamps are RFC 3161 timestamps from a trusted authority.** They are *not* qualified
  timestamps under eIDAS. If a rule in your jurisdiction names a qualified trust service provider,
  this does not substitute for one.
- **It is not an archive.** It produces evidence designed to outlive the service. The file stays in
  your Nextcloud, where your retention obligations already apply.

## Where the processing happens

You choose. Point the app at the hosted service in the EU, or at your own self-hosted SealDoc
instance. The app talks to nothing else: no analytics, no telemetry, no third host.

There is deliberately **no default server URL**. An administrator installing this app is expected
to say where their SealDoc lives, and for a good part of this audience that will be their own
machine. A hard-coded vendor URL would quietly turn a self-hosted deployment into one that phones
home.

## Installation

Until this is published in the app store:

```bash
cd /path/to/nextcloud/custom_apps
git clone https://github.com/bryanvanrijn/SealDoc-NextCloud.git sealdoc
chown -R www-data:www-data sealdoc
sudo -u www-data php occ app:enable sealdoc
```

The directory **must** be named `sealdoc`, because that is the app id.

Then open *Administration settings → SealDoc*, fill in the server URL and an API key, and press
*Test connection*.

## Verifying the install

`app:enable` succeeding proves very little. The first version of this app enabled cleanly, showed
as active in the app list, and logged `Call to undefined method registerWorkflowOperation` on every
single request while the Flow action never appeared at all.

So there is a check that runs inside a real Nextcloud and asserts the things that actually matter,
including that the operation lands in the list the Flow settings page renders:

```bash
sudo -u www-data php custom_apps/sealdoc/tests/registration-check.php
```

```
  ok    SealOperation resolves from the container
  ok    implements ISpecificOperation
  ok    bound to the File entity
  ok    is admin scope only
  ok    has a display name
  ok    has an icon path
  ok    operator list builds
  ok    SealOperation appears in the engine operator list
  ok    admin settings resolve
  ok    admin settings point at our section
  ok    client resolves
  ok    client reports unconfigured before any setup
  ok    ping answers without throwing

all checks passed
```

There is a second check for the half that only a browser can see. Every client-side fault this app
has had was invisible to the PHP and HTTP checks: a settings screen that called `axios`, which is
not a global in Nextcloud 30, so no request ever left the page; a menu entry hidden on every row by
an `instanceof` that quietly returned false; and a shield that stopped drawing after a refresh
because the Files app had already asked for its listing before the app registered its WebDAV
properties.

```bash
npm i -D playwright
NC_URL=https://cloud.example.test NC_USER=admin NC_PASS=... NC_SEALED_FILE=invoice-sealed.pdf node tests/browser-check.cjs
```

```
  ok    logs in
  ok    the Files listing asks for the SealDoc properties
  ok    both DAV properties are registered
  ok    the namespace is registered
  ok    the seal action is registered
  ok    the shield action is registered
  ok    the Seal sidebar tab is registered
  ok    the shield is drawn on invoice-sealed.pdf
  ok    no shield on Readme.md
  ok    no uncaught errors on the page
```

`NC_URL` has to be the URL the instance considers its own. If `overwriteprotocol` is `https`,
Nextcloud marks the session cookie Secure and a browser stores nothing over plain http: the login
form posts, succeeds, and lands you back on the login page with no session and no error.

There is a third check for the translations, because those rot silently and in one direction: the
English source changes, the other seven files keep the old key, and every reader outside English
gets English back with no error anywhere.

```bash
node tests/l10n-check.mjs
```

It compares the strings the code asks for against the strings each language has, in both
directions, checks `l10n/x.js` against `l10n/x.json`, and checks every plural array against the
`nplurals` that file declares.

## Background jobs

**Sealing only happens when Nextcloud runs background jobs.** Nextcloud's default is AJAX, which
advances at most one job per page load, competing with everything else in the instance. Two
documents queued during testing sat untouched for twenty-five minutes with the file list open the
whole time.

From the outside that is indistinguishable from a broken app. The click reports success, the file
never appears, and nothing is logged because nothing ran. So the app says so: the admin settings
report which mode this server uses and how many documents are waiting, the Seal panel says
"Queued for sealing" instead of "not sealed", and both warn when the mode is not Cron.

Set **Cron** under Administration settings, Basic settings, and give it something to run. In Docker
that is a second container on the same volumes:

```yaml
  cron:
    image: nextcloud:30
    restart: always
    volumes:
      - nextcloud:/var/www/html
    entrypoint: /cron.sh
    depends_on:
      - db
```

## Requirements

- Nextcloud 29 to 31
- PHP 8.1 or newer
- A reachable SealDoc instance and an API key
- Background jobs on Cron. See above; on AJAX the app queues work that may never run

## Licence

AGPL-3.0-or-later. See [LICENSE](LICENSE).

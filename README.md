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

## Requirements

- Nextcloud 29 to 31
- PHP 8.1 or newer
- A reachable SealDoc instance and an API key

## Licence

AGPL-3.0-or-later. See [LICENSE](LICENSE).

# Changelog

All notable changes to this app are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [0.2.0] - 2026-09-02

### Added

- **A shield in the Files list.** A sealed document now carries a shield on its row, and
  clicking it opens the evidence pack. A folder of invoices says at a glance which documents
  carry proof and which do not, which is the difference between a converter and a compliance
  tool.
- A WebDAV property (`{http://sealdoc.eu/ns}sealed` and `evidence-file-id`) served by a Sabre
  plugin, so the Files list gets the answer inside the PROPFIND it already makes. An endpoint
  would have meant an extra request per folder and a flash of missing shields, because the
  file-action API decides per row synchronously and cannot await anything.
- Four more checks in `tests/http-check.sh`, covering both halves of the shield. The server
  contributing the property and the client asking for it are registered in different places and
  neither complains when the other is missing; without a check, a half-registered shield just
  never appears.

### Notes on the build

This release introduces the first build step. Nextcloud removed the global
`OCA.Files.fileActions` API and the replacement lives in `@nextcloud/files`, which has to be
bundled, so there is no way to draw a per-row action without one.

It is deliberately small: a hand-written 30-line webpack config rather than
`@nextcloud/webpack-vue-config`. That shared config is built for apps with Vue components and
pulls in vue-loader, a node polyfill plugin and terser as undeclared peers; this app has one
plain JavaScript entry and no Vue, so it bought three dependency conflicts and a broken build in
exchange for nothing. Two Node built-ins are polyfilled explicitly (`string_decoder`, `buffer`)
rather than with a blanket plugin, so the bundle carries only what it uses and a third one shows
up as a build failure instead of silent growth.

The built bundle in `js/` is committed. The README promises "clone into custom_apps and enable",
and the app store ships whatever is in the release; ignoring the build output would mean the
shield silently does nothing until somebody runs npm.

## [0.1.1] - 2026-09-02

Fixes found by running the app rather than reading it. All three broke the admin screen and none
of them showed up in the app list, the PHP syntax check or the server-side registration checks.

### Fixed

- **The settings screen did nothing at all.** It called `axios` directly, assuming Nextcloud
  exposes it globally. It does not in Nextcloud 30, so the click handler threw straight after
  setting the "Saving..." label and no request ever left the browser. The tell was the access log:
  not a single request to `/apps/sealdoc/config`. Replaced with `fetch` plus the request token,
  which needs nothing that has to be bundled.
- **The settings endpoints rejected every request.** `#[AuthorizedAdminSetting]` is typed
  `class-string<IDelegatedSettings>` and pointed at a class that only implemented `ISettings`.
  That passes a type check and fails at runtime, and it was hidden behind the `axios` bug.
  `Admin` now implements `IDelegatedSettings`.
- **Browsers kept serving the old script.** Nextcloud derives the asset cache-buster from the app
  version, so a fix without a version bump is a fix nobody receives. Hence 0.1.1.

### Added

- `tests/http-check.sh`: an HTTP-level check that logs in, loads the settings section and calls
  both endpoints the way a browser does. The PHP-side check passed throughout the outage above,
  because all three faults lived on the browser side of the request. This is the cheapest thing
  that would have caught them.

### Verified end to end

Against a real Nextcloud 30.0.17 and the live SealDoc API:

    /Facturen/proef-factuur.txt                          81 bytes  text/plain
    /Facturen/proef-factuur-sealed.pdf               25.274 bytes  application/pdf
    /SealDoc evidence/2026/proef-factuur-evidence.zip 23.252 bytes  application/zip

    seal record: fileId 620 -> sealed 621, evidence 624
    reverse lookup on the sealed file: found

The output is genuinely PDF/A-3B (`<pdfaid:part>3`, `<pdfaid:conformance>B`), and the pack
contains `chain_of_custody.json`, `compliance_passport.json`, `ledger.json`, `MANIFEST.json`,
`manifest.sha256` and `public-keys.jwk`, so a third party can verify it without either system.

## [0.1.0] - 2026-09-01

First working version. Verified against Nextcloud 30.0.17 with PHP 8.3.

### Added

- **Evidence pack storage**, off by default. When switched on, the pack is filed in a separate
  folder (`/SealDoc evidence` by default) with a subfolder per year, so working folders do not
  double in size and a decade of packs stays navigable.
- A `sealdoc_seals` table linking a source file to its job, its sealed output and its evidence
  pack. This is what lets the app answer "is this file sealed, and where is its proof" without
  guessing from a filename, which would break the first time somebody renames a file.
- Flow action **Seal document with SealDoc**: converts a matching file to PDF/A-3B with an
  RFC 3161 timestamp, a SHA-384 hash chain and a chain-of-custody record, and writes the result
  back next to the original as `<name>-sealed.pdf`.
- Administration settings for the server URL and API key, with a reachability test that
  distinguishes "cannot reach this host" from "host rejects the key".
- `tests/registration-check.php`, which runs inside a real Nextcloud and asserts that the
  operation actually reaches the workflow engine's operator list.

### Notes on the design

- The seal runs in a **background job**, not inside the request that wrote the file. Conversion
  and timestamping take seconds to a minute; doing that inline would hold the upload open and a
  client timeout would look like a failed upload of a file that actually arrived.
- The job **polls** rather than receiving a webhook. A webhook would be cheaper but needs the
  Nextcloud instance to be reachable from the internet, and a large part of this audience runs
  Nextcloud behind a firewall on purpose.
- The API key is stored through `ICrypto`, not in plain appconfig.
- There is **no default server URL**, so a self-hosted install never silently phones home.
- Files ending in `-sealed.pdf` are skipped, otherwise a folder rule would seal its own output
  and keep going.

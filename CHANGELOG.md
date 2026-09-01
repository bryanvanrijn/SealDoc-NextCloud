# Changelog

All notable changes to this app are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [0.1.0] - 2026-09-01

First working version. Verified against Nextcloud 30.0.17 with PHP 8.3.

### Added

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

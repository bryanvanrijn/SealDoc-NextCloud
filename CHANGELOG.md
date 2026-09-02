# Changelog

All notable changes to this app are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [0.5.2] - 2026-09-02

Thirteen findings from a full audit of the app, ranked by how badly each one misleads somebody
who has to rely on the evidence later. Everything below was measured against a running Nextcloud
30.0.17 and the live SealDoc API, not read off the code.

### Fixed: things the app asserted that were not true

- **A seal outlived the files it pointed at.** Nothing ever deleted a row. Remove the sealed PDF
  and the panel kept saying "Sealed on ...", kept ticking four guarantees and kept offering a link
  to evidence, all about a file that no longer existed. Worse, the original became permanently
  unsealable: the row still answered "already sealed" and the file list still hid "Seal with
  SealDoc", with no route back. The likeliest way in is the obvious one, deleting a bad output in
  order to retry.

  There is a deletion listener now, and it treats the three files differently because they are
  different: the sealed output going means the seal is gone, the pack going means the pack is
  gone, and the original going changes nothing. The panel also resolves the ids before asserting
  them, so it cannot lie even if the listener misses a case.

- **The WebDAV shield told a share recipient what the panel deliberately refuses to.** The
  controller has always declined to confirm a seal to a non-owner, with a comment saying the
  evidence is the owner's to hand over. The DAV plugin had no ownership check at all and published
  sealed, complete, role and two private file ids to anyone who could see the file. The two
  surfaces then contradicted each other on screen: a shield in the row, "this document has not
  been sealed" in the panel. Verified after the fix: the recipient gets `sealed=false` and no ids,
  the owner still gets `true`.

- **A name collision aborted the job silently and forever.** `report.docx` and `report.pdf` both
  target `report-sealed.pdf`, and the second one hit a bare `return` with no log and no row. Every
  retry showed a green "queued" toast, the file never appeared, and the administrator asked to
  investigate found an empty log. The same file already handled the identical collision correctly
  for the evidence pack. It now does for both: `botsing-sealed.pdf` and `botsing-2-sealed.pdf`.

- **"Test connection" never sent the API key**, while its own docblock advertised that it
  separated "I cannot reach this host" from "the host rejects my key". It could not: the endpoint
  it used is public and answers 200 with no key and 200 with a wrong one. Measured:

      /api/public/plans   no key   -> 200      /api/jobs?limit=1   no key   -> 401
      /api/public/plans   bad key  -> 200      /api/jobs?limit=1   bad key  -> 401
                                               /api/jobs?limit=1   real key -> 200

  So a truncated or revoked key produced a green "Server reachable", after which nothing ever
  sealed and nothing said why. It now probes twice and has five answers, including the one the
  button was always sold as giving.

- **The panel blamed a setting that was already on.** "No evidence pack was stored. An
  administrator can switch that on" was shown whenever the pack id was zero, and the pack id
  reaches zero three ways: the download failed, the write failed, or storage is off. The one cause
  it named is the only one that was impossible in the common case. It now knows which.

- **The seal moment was formatted in the browser's locale**, next to a label in the server's
  language. A Dutch user on an en-US browser read "Verzegeld op 9/2/2026" for a seal made on
  2 September, and two users of one instance read a different day from the same row, on the single
  most audit-relevant value in the app.

- **The Flow description and the README promised an RFC 3161 timestamp unconditionally**, at the
  two screens a stranger and an administrator actually read. The correct wording already existed
  in `appinfo/info.xml`; it had landed there and nowhere else.

- **`composer.json` was not valid JSON.** `"OCA\SealDoc\"` needs both backslashes escaped.
  Silent at runtime, because Nextcloud registers its own autoloader and never reads the file, and
  a parse error for anyone running `composer` anything.

### Added

- **A failed seal is now recorded and shown.** There was no failure state at all: six bare returns
  and a catch-all that logged and dropped. "Attempted and lost" therefore rendered as "This
  document has not been sealed", which is the sentence a file nobody ever touched gets. The widest
  funnel is a wrong or revoked API key, which passed the click-time check and the settings test
  button and then failed inside every job forever.

  The panel now says **Sealing failed**, names the reason in words, and says nothing was written
  and it can be tried again. A failed row is cleared by the next attempt, so a retry is possible.

- **A size guard before reading a file into memory.** `getContent()` loads the whole thing;
  exhausting `memory_limit` is a fatal, not a `Throwable`, so the catch never saw it and the cron
  worker died mid-run with the log blaming core. The controller already guarded the identical
  hazard on the cheaper path.

- **The client waits for a complete evidence pack.** SealDoc now answers 409 while its ledger is
  still being written rather than handing over a pack that is missing its chain and its keys. This
  client polls until completed and downloads immediately, which is exactly the window, so it
  retries on 409 with the server's `Retry-After`.

### Changed

- `tests/registration-check.php` had a line that could not fail: `!(A && B) || A` is true for all
  four assignments, so it printed a green tick under a sentence that was false about the machine
  it ran on, and the README published that line as evidence. Relabelled to what it actually
  asserts rather than deleted, because it still catches a regression.
- The README's install block showed 13 of the 35 lines the script prints. It now shows the shape
  and says the list grows.
- Eleven new strings, all seven languages, 87 in total.

## [0.4.2] - 2026-09-02

### Fixed

- **This app never asked SealDoc for a timestamp.** `POST /api/jobs` takes a `timestampRfc3161`
  form field that defaults to false, and this client sent only the file. Every document it sealed
  therefore came back without the one guarantee the whole promise leans on, and the panel spent a
  release honestly reporting a gap this app had created itself.

  Proven by sending the field and nothing else: `timestampedAt: 2026-09-02T08:42:30Z`, a real TSA
  certificate thumbprint, `timestamp_token.tsr` in the pack, and SealDoc's own ledger verdict
  moving from `VALID-WITH-LOWER-ASSURANCE` to **`VALID`**, note *"Time asserted by an independent
  RFC 3161 authority."* Four red rows in the panel turned green without a line of panel code
  changing.

- **The original wore the sealed document's shield.** A seal matches all three of its files, so
  the unconverted `.txt` sitting next to the PDF/A drew the same shield, the same label and the
  same tooltip as the file that actually carries the evidence, and clicking it opened the other
  file's pack. The original now has its own outline shield and says what it is; both it and the
  pack lead to the sealed document.

## [0.4.0] - 2026-09-02

### Fixed

- **The evidence pack reported "This document has not been sealed."** One seal produces three
  files: the original, the sealed output written next to it, and the pack filed elsewhere. The
  lookup matched the first two and quietly not the third, so the one file whose entire purpose
  is to prove the seal denied that a seal existed. It now matches all three, the pack carries a
  shield of its own, and its shield and panel link to the document rather than back to itself.

- **The panel printed a reassuring line next to a red cross.** The note under the archival format
  row said "validated against ISO 19005-3" whenever a verdict existed at all, without reading it.
  On a document the validator had called `non-compliant`, that was the opposite of the truth. It
  now repeats the verdict itself.

- **A seal with no stored passport is repaired instead of shrugged at.** Documents sealed before
  the passport column existed rendered as four question marks forever, while the answers sat in
  a zip the app had written itself two folders away. The panel now recovers the passport from the
  stored pack, once, and says so in the log. Recovering the four seals on the test instance
  turned four rows of "unknown" into real answers, one of which was a failure nobody had seen.

### Added

- **Three shields instead of one.** A tick where every guarantee is present, an exclamation mark
  where one is missing, a question mark where the seal could not be read back. A document whose
  PDF/A conversion came back non-compliant used to draw exactly the same shield as one that got
  everything, which is the worst thing this app could do: the shield is the whole claim, and a
  claim that cannot be wrong is not worth reading.

- **A verdict line at the top of the panel**, so somebody about to rely on a document learns in
  the first line whether they can, instead of scanning four rows for a red cross. Every gap now
  has its consequence written out underneath, not just the missing PDF/A and timestamp.

- **A role banner**: whether you are looking at the original, the sealed version or the pack.

- `lib/Service/SealFacts.php`, the one place that reads a passport. Three surfaces derived those
  answers separately before, and the one that disagreed was the one nobody looked at.

- `tests/l10n-check.mjs`, which compares the strings the code asks for against the strings each
  language file has, in both directions, and checks the `.js` and `.json` halves against each
  other and the plural arrays against the declared `nplurals`. Its first run found 39 untranslated
  strings and 2 dead keys across all seven languages, several of them from long before this
  release.

- **The panel now quotes SealDoc rather than paraphrasing it.** Every evidence pack carries a
  `ledger.json` whose `assurance` block states, in one sentence, what the evidence is worth. On the
  test instance that read `VALID-WITH-LOWER-ASSURANCE`, with the note *"No independent time anchor.
  The chain is intact, but the time it happened rests on SealDoc's own clock only."* That is the most
  useful sentence in the whole archive and the app was showing none of it.

- **The fingerprint is now checked, not just printed.** The panel hashes the sealed file as it is
  stored right now and says whether it still matches, naming the algorithm it found. A fingerprint
  nobody checks is decoration; this turns the panel from a report about the past into a statement
  about the file in front of you, and it is what would catch a sealed PDF edited in place. Files
  over 64 MB report "not checked" rather than being read into memory to answer a sidebar.

- **A pack that arrives without its ledger is now named as such.** See below.

### Measured

Every document sealed through this app on the test instance came back **without an RFC 3161
timestamp**, seven for seven. One also failed PDF/A-3B conversion while still being written to disk
as `<name>-sealed.pdf`.

And **one pack in seven arrived without `ledger.json` and `public-keys.jwk`**: a valid zip, with a
valid compliance passport inside, carrying no hash chain and no verification keys. Its passport also
reported `immutableAuditTrail: false`, so the two agree. Nothing in the response, the passport or
the manifest said anything was missing; the only way to find out was to list the archive. Neighbouring
seals two minutes either side were complete, so this is intermittent rather than a setting.

The app now says all of it, per document, in red. That is the point of the panel, and it is why the
app description no longer promises a timestamp unconditionally.

## [0.3.3] - 2026-09-02

### Fixed

- **The shield stopped drawing after a page refresh.** The Files app builds the PROPFIND for a
  directory listing before scripts added through `LoadAdditionalScriptsEvent` run, so
  `registerDavProperty` arrived too late and every node came back without the property. Registration
  moved to `js/dav-init.js`, loaded through `Util::addInitScript`, which runs ahead of the Files
  bundles. See the comment in that file before moving it back.

  This was intermittent in the cruellest way. Navigating inside the Files app asked for the
  properties again and the shield appeared; a refresh put it back to the frozen listing and it
  vanished. "It worked yesterday" and "it is broken now" were both true statements about the same
  code, and nothing on the server side was wrong: the Sabre plugin was registered, the property was
  computed, and a PROPFIND issued by hand returned `sealed=true` for exactly the right files.

- **The retention policy field accepted input and threw it away.** The form sent the value, the
  controller never read it, and the field came back empty on the next load. The page load and the
  save response also assembled their payload separately, which is how they drifted apart in the
  first place; both now go through one `ConfigState`.

### Added

- **A background jobs section in the admin settings.** It reports which mode this server runs, how
  many documents are waiting, and warns in red when the mode is not Cron.

  Sealing is a background job, and Nextcloud's default is AJAX, which advances at most one job per
  page load. Two documents queued during testing sat untouched for twenty-five minutes with the file
  list open the whole time. From the outside that is exactly what a broken app looks like: the click
  reports success, the file never appears, and nothing is logged because nothing ran. This app
  cannot fix an administrator's cron. It can refuse to let it be a mystery.

- **A queued state in the Seal panel.** "Waiting" and "never sealed" are different answers and the
  panel now tells them apart, with the same warning when the server has no schedule to run them on.

- `tests/browser-check.cjs`, which drives a real browser and asserts the things only a browser can
  see: that the listing asks for the properties, that both file actions and the sidebar tab are
  registered, and that the shield is drawn on a sealed file and not on an unsealed one. Every
  client-side fault this app has had was green in the PHP and HTTP checks.

- Seven translations for the new strings, including plurals with the right number of forms for
  Polish and Czech.

### Changed

- The toast no longer promises the sealed document "shortly". On a default instance that turned out
  to mean twenty-five minutes and counting.

- `tests/registration-check.php` no longer asserts that evidence storage is switched off. That
  failed on any instance where an administrator had switched it on, and a check that goes red on a
  correctly configured server teaches people to ignore it. It now tests the default instead, and
  gained checks for the settings payload and the job queue.

## [0.3.0] - 2026-09-02

### Added

- **A Seal panel in the file sidebar**, showing what the seal actually contains: the archival
  format, the trusted timestamp, the chain of custody and the audit trail, plus the fingerprint
  of the sealed document and a link to its evidence pack.
- The panel is built on **three states, not two**: yes, no, and unknown. A guarantee that is
  missing is rendered in red and named; one that could not be read back is rendered as unknown.
  Neither is quietly rounded up to a tick.
- An optional **retention policy** field. See below.
- **Seven translations** (nl, de, fr, es, it, pl, cs) covering all 26 strings in the app.
- The compliance passport is now stored with the seal, so the panel reports what SealDoc said
  rather than what this app assumed. The pack is fetched on every seal for that reason; writing
  the zip to disk stays optional.

### Why the panel is not a row of green ticks

Because the first document sealed through this app came back a valid PDF/A-3B, with a complete
chain of custody, and **no timestamp at all**. That path fails open on the server, and nothing
anywhere said so: the only record was a field in a JSON file inside a zip nobody opens.

A panel that assumed the promise would have shown a tick over a missing guarantee, which is
worse than having no panel. So a missing timestamp is red, named, and followed by a sentence
saying what it costs: the document proves its own integrity but not the moment it existed.

### Why there is no statutory retention period

SealDoc cannot know how long a document must be kept. That depends on what the document is, in
which country, under which rule, and none of those are visible to a sealing service. Printing
"statutory retention: 7 years" next to a file it knows nothing about is the one kind of claim a
compliance product must never make.

So the field is free text written by an administrator, and the panel labels it as the
organisation's own policy with a line saying SealDoc does not determine statutory periods. Empty
by default, because an empty row is honest and a guessed one is not.

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

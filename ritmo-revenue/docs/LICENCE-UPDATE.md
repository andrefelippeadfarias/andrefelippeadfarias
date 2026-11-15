# Ritmo Revenue: licence and updates (milestone M5)

The licence decides whether the properties may leave shadow mode and whether the AI assistant
works. The update channel lets WordPress install new versions of the plugin, but only versions
signed by the publisher. Neither ever runs inside a pricing run, and neither ever touches a price.

No licensing vendor has been chosen yet. The plugin speaks a small JSON protocol (section 2.5)
that a licensing platform (directly or through a thin adapter) or a shop of one's own can answer,
and ships a test provider for development. Until a server is configured, a site has no licence:
the engine runs in shadow mode and shows what it would do.

## 1. Where each piece is

| Folder | Classes | What they do |
|---|---|---|
| `src/Domain/Licence` | `LicenceKey`, `LicenceRecord`, `LicenceEvaluator`, `LicenceVerdict`, `LicenceStanding`, `LicenceReason`, `GracePolicy`, `CheckDue` | Pure rules: the key and its fingerprint, the record of the provider's answers, the standing at an instant, the grace period, when the scheduled check is due. |
| `src/Connectors/Licence` | `LicenceServer` (extends `Contracts\LicenceProvider` with activate and deactivate), `HttpLicenceServer`, `StubLicenceProvider`, `NoLicenceServer`, `ServerUrl` | The providers: the HTTP protocol, the test provider, "no server configured"; the server address rules (https, no credentials, query or fragment; plain http only for a loopback host in development mode). |
| `src/Application/Licence` | `LicenceService`, `LicenceSettings`, `ProviderChoice`, `LicenceRefused`, `LicenceVerdictSource`, `LicenceEnforcer`, `ModeLowering`, `RepositoryModeLowering` | Saving, checking and removing the key; the settings from the constants; which provider answers a key; what the licence means for the runs, the mode changes and the assistant. |
| `src/Application/Update` | `UpdateChecker`, `UpdateSettings`, `ManifestVerifier`, `ReleaseManifest`, `PackageVerifier`, `TrustedKeys`, `UpdateRefused`, `ReleaseSigner` | The update channel: fetching, verifying and keeping the signed metadata; verifying a downloaded package; signing a release (for `bin/sign-release.php`). |
| `src/Infrastructure/Update` | `UpdateHooks`, `UpdateConstants` | The WordPress filters of the updater; the channel's settings from the plugin and the constants. |
| `src/Admin/Licence` | `LicenceScreen`, `LicenceActions`, `LicenceText`, `LicenceNotice`, `LicenceServices`, `LicenceHooks` | Settings > Licence, its four form actions, the words of every code, the notice on the plugin's screens, the objects of a request, the hooks (notice, scheduled check, update filters). |
| `templates/admin/licence` | `frame`, `screen` | Markup only; every value escaped. |
| `bin/sign-release.php` | | Key pair, signing and verifying a release. |
| `tests/fake-apis/LicenceUpdateApi.php` | | The local licence and update server for the tests. |

## 2. Licence

### 2.1 Standing

`LicenceEvaluator::evaluate(record, now, devMode)` gives a `LicenceVerdict`: a standing, a reason
code, the end of the grace period and the end of the licence.

| Standing | When | Writing modes (approval, autonomous) | AI assistant |
|---|---|---|---|
| `active` | the last answer was "valid", the last attempt reached the server and the licence has not ended since | yes | yes |
| `grace` | something went wrong and the grace period runs (below) | yes | yes |
| `lapsed` | the grace period is over | no: shadow mode only | off |
| `unlicensed` | no key; a key never answered "valid"; a key confirmed only by the test provider outside development mode | no: shadow mode only | off |

The grace period (`GracePolicy`, 30 days; `RITMO_LICENCE_GRACE_DAYS` sets 1 to 90, anything else
gives the default) starts:

- **server out of reach** (`unreachable`): at the last "valid" answer. The last valid state is kept
  for the grace period; an answer after the outage ends it. No successful check for the whole grace
  period (a site whose scheduled events stopped) counts the same.
- **expired** (`expired`): at the end of the licence, never before the last "valid" answer (a server
  that says "valid" today and "ended a year ago" tomorrow gives the full grace period from today).
  An end date already past when the server said "valid" does not count: the server is the authority
  at the moment it answers.
- **revoked or moved** (`invalid` after a valid answer): at the first such answer.

A key that was never valid gets no grace period. Reasons: `none`, `no_key`, `not_checked`,
`test_provider`, `unreachable`, `expired`, `invalid` (`LicenceReason`).

### 2.2 What the standing does

- **Runs.** `LicenceEnforcer::modeAtRunStart(property)` gives the mode a run starts in: the
  property's mode while the licence allows writing; otherwise shadow, and a property in approval or
  autonomous mode is put back in shadow mode for good (`ModeLowering`: the mode, the event in the
  change log with the actor `licence`, and the end of every approval not written yet, like any
  lowering). It reads the stored verdict only. A run already writing is never asked again, so
  nothing is cut in the middle of a write; the next run starts in shadow mode.
- **Mode changes.** `LicenceEnforcer::unmetToLeaveShadow()` is the condition (code `licence`) the
  mode change adds to leaving shadow mode.
- **Assistant.** `LicenceEnforcer::allowsAi()` (or `LicenceVerdict::allowsAi()`).
- **Shadow mode always works.** The engine reads, decides and shows what it would do with or
  without a licence.

Wiring (integration of M5): `WordPress\Services::licence()` builds the one `LicenceService` of a
request (its provider over `Infrastructure\Http\HostBoundHttpClient`, bound to the host of
the licence server of `LicenceSettings`, and `GuardedHttpClient`, whose read list holds only that server's
`activate`, `validate` and `deactivate`); `RunStack` builds the `LicenceEnforcer` over it and
`RepositoryModeLowering`; `PipelineRunExecutor::start` opens every run in `modeAtRunStart()` (unless
the emergency switch already forces shadow mode); `ModePreconditions` adds `unmetToLeaveShadow()` to
both raises; `AiAssistant` gets `allowsAi()`. `Plugin::boot()` calls `LicenceHooks::register()`;
deactivation and uninstall call `LicenceHooks::unschedule()`; the uninstaller with "delete the data"
frees the key on its provider first (one attempt). `LicenceEndToEndTest` (PHP, over the simulator)
and `m5-licence.js` (stage 8 of `bin/e2e.sh`) show a lapsed licence putting a property in approval
or autonomous mode back in shadow mode at its next run with nothing written.

### 2.3 Checks

The provider is asked only: when a key is saved (`activate`), from the licence screen ("Check now",
at most every 30 seconds, `LicenceService::COOLDOWN_SECONDS`), by the scheduled check and when the
key is removed (`deactivate`). The scheduled check is its own WP-Cron event (`ritmo_licence_check`,
twice a day; `LicenceHooks`), and asks only when `CheckDue` says so: 20 hours after the last
attempt, 6 hours after an attempt that did not reach the server. A pricing run never asks the
provider: it reads `LicenceService::verdict()`, which evaluates the stored record.

### 2.4 The key

- Typed on Settings > Licence (a password field, never filled back), checked by `LicenceKey`
  (8 to 128 letters, digits, `.`, `:`, `-`, `_`), stored encrypted in the secrets vault as
  `licence_key`. Saving another key frees the earlier one on its provider first.
- The record (`LicenceRecord`, setting `licence_state`) holds the provider's answers and a
  16-character fingerprint of the key, never the key. A damaged record is the empty one and grants
  nothing.
- The key travels only in the body of a POST to the licence server; it is never in a URL, a log
  line, a notice or the support file. The provider's message is kept as plain text (no markup, no
  control characters, 200 characters at most) and escaped where shown.

### 2.5 The HTTP protocol (`HttpLicenceServer`)

The server and the Ed25519 public key(s) that sign its answers are embedded in the plugin
(`LicenceSettings::EMBEDDED_SERVER_URL` and `EMBEDDED_PUBLIC_KEYS`, empty until a licensing
platform is chosen; with no key there is no server and every key stays "not checked"). Nothing in
the database can send the key elsewhere. The address is https, without credentials, query or
fragment.

```
POST <url>/activate | <url>/validate | <url>/deactivate
Content-Type: application/json
{"licence_key": "...", "site_url": "https://example.com", "product": "ritmo-revenue", "plugin_version": "1.0.0", "nonce": "<32 hex, new for each request>"}

200 {"status": "valid" | "expired" | "invalid", "expires_at": "2027-01-31T00:00:00Z" | null, "message": "...",
     "checked_at": "2026-11-15T10:00:00Z", "nonce": "<as sent>", "signature": "<base64>"}
200 {"status": "deactivated"}                       (deactivate)
```

A `valid` answer counts only when `signature` is the Ed25519 detached signature, by the server's
secret key, of these lines joined by `\n` (`LicenceSignature`):

```
ritmo-licence-v1
<sha256 of the licence key, hex>
<site_url as sent>
valid
<expires_at as answered, or empty>
<checked_at as answered>
<nonce as sent>
```

An unsigned or wrongly signed `valid` (another key, another site, another nonce, a changed end
date) is treated as a server that was not reached: the last answer is kept. A server that merely
answers `{"status":"valid"}` therefore licenses nothing. `expired` and `invalid` need no signature
(they grant nothing).

| Answer | Means |
|---|---|
| 200 with a known status | that status (`expires_at` in ISO 8601 with a zone; a date that is not real makes the answer not understood) |
| 401, 403, 404, 410 | invalid |
| anything else: no response, timeout (15 s), 429, 5xx, a redirect, a body that is not that JSON | not reached: the last answer is kept |

`activate` binds the key to the site the first time (a server may refuse a second site with 403);
`validate` is the check of an activated key; `deactivate` frees the seat. A platform with its own
API answers this protocol through an adapter of a few lines on the shop's side; the plugin does not
change.

### 2.6 Development mode and the test provider

Only on a development site (its address on 127.0.0.1, localhost or ::1) and with `RITMO_DEV` or
`RITMO_LICENCE_DEV` exactly `true`, the constants `RITMO_LICENCE_SERVER_URL` (another server; plain
http allowed on a loopback host, the local simulator) and `RITMO_LICENCE_PUBLIC_KEY` (its public
key) replace the embedded ones. On any other site they are ignored and the Licence tab says so.

With both `RITMO_DEV` and `RITMO_LICENCE_DEV` exactly `true` on such a site, `StubLicenceProvider`
answers keys starting with `TEST-`, without any network: `TEST-VALID-...` (valid one year),
`TEST-EXPIRED-...` (ended yesterday), `TEST-DOWN-...` (not reached), anything else invalid. Its
answers count only while that holds: a record made by the test provider elsewhere is `unlicensed`
(`test_provider`), and a `TEST-` key typed there goes to the real server like any key. So neither
the test provider nor a server of one's own can license a live site by configuration alone.

### 2.7 Screen, actions and notice

Settings > Licence (`LicenceScreen`, capability `ritmo_manage_connections`): the standing and what
it means, the end of the licence, the last answer and the last attempt, the end of the grace period,
the grace period, who checks (licence server and its host, test provider, or no server), the
provider's message, warnings for an invalid server address and for development mode, the key field,
"Check now", "Remove the key" (a ticked confirmation) and the update channel (installed version,
version on offer, last problem, "Check for updates").

| Action | Capability | Notes |
|---|---|---|
| `ritmo_licence_save` | manage connections | saves and activates; a bad key is refused in words, nothing sent |
| `ritmo_licence_check` | manage connections | "Check now"; 30-second cooldown |
| `ritmo_licence_remove` | manage connections | box `confirm` ticked; frees the key on the server, forgets it here |
| `ritmo_update_check` | manage connections | asks the update server now, then lets WordPress rebuild its list of updates |

All go through `Admin\Screens\ScreenActions` (POST, capability, nonce, then the handler).
`LicenceNotice` (admin_notices) shows on the plugin's screens, except the licence tab, to everyone
with `ritmo_view`: a warning during the grace period (with its end date), an error once lapsed, a
note without a licence; a link to the licence tab for those who manage the connections.

## 3. Updates

### 3.1 The channel

`UpdateConstants` reads the address of the signed metadata and the trusted public keys: the ones
embedded in the plugin (`EMBEDDED_URL`, `EMBEDDED_PUBLIC_KEY`, empty until the update server
exists), replaced by the constants `RITMO_UPDATE_URL` and `RITMO_UPDATE_PUBLIC_KEY` (base64 Ed25519
keys separated by commas or spaces, so a key can be rotated). Without an address or a key the
channel is off. A key that cannot be read turns the channel off; nothing ever falls back to
accepting unsigned updates.

### 3.2 Signed metadata

```json
{"format": "ritmo-update/1", "payload": "<base64 of the release JSON>", "signature": "<base64 Ed25519 of the payload bytes>"}
```

The release JSON (`ReleaseManifest`):

| Field | Rule |
|---|---|
| `slug` | `ritmo-revenue` |
| `version` | `1.2.3` or `1.2.3-beta.1` |
| `package` | the zip's URL, on the same scheme, host and port as the metadata URL |
| `sha256`, `size` | of the zip (size at most 30 MB) |
| `package_signature` | base64 Ed25519 signature of the zip's bytes |
| `name`, `requires`, `requires_php`, `tested`, `released`, `changelog` | optional; `changelog` a list of at most 50 plain text lines |

`ManifestVerifier` checks, in this order: the envelope's format; a signature is present; a trusted
key verifies it over the exact payload bytes (before anything of the payload is read); every field;
the slug; the package's origin; the version newer than the installed one. `PackageVerifier` checks
the downloaded bytes: the size, the SHA-256 (`hash_equals`) and the package's own signature
(`sodium_crypto_sign_verify_detached`; WordPress ships sodium_compat for hosts without the
extension).

### 3.3 Through WordPress (`UpdateHooks`)

- `pre_set_site_transient_update_plugins`: offers the verified release (`UpdateChecker::latest()`:
  the server is asked at most every 12 hours, every hour after a failure; a refused answer never
  replaces a verified one; the kept envelope is verified again on every use). Any other offer for
  this plugin (a plugin of the same name elsewhere) is removed.
- `plugins_api`: the "View details" window of the verified release; the changelog lines escaped.
- `upgrader_pre_download`: for this plugin, or for any package on the update server, WordPress gets
  a temporary file only after `UpdateChecker::download()` verified the package of the release on
  offer (size, hash, signature); anything else for this plugin is refused with an error in words
  (`WP_Error` `ritmo_update_refused`). Nothing downloaded is unpacked, included or run before
  WordPress installs it. A zip uploaded by hand on Plugins > Add is not affected.

Refusal codes (`UpdateRefused`): `not_configured`, `unreachable`, `malformed`, `unsigned`,
`bad_signature`, `wrong_slug`, `other_origin`, `not_newer`, `size`, `hash`, `package_signature`,
`unknown_package`.

### 3.4 Publishing a release (`bin/sign-release.php`)

```
php bin/sign-release.php keygen --out ~/keys/ritmo-update.key        # prints the public key
RITMO_SIGNING_KEY="$(cat ~/keys/ritmo-update.key)" php bin/sign-release.php sign \
    --zip dist/ritmo-revenue-1.2.0.zip --version 1.2.0 \
    --package-url https://updates.example.com/ritmo-revenue/ritmo-revenue-1.2.0.zip \
    --requires 6.5 --requires-php 8.1 --changelog CHANGES.txt --out latest.json
php bin/sign-release.php verify --metadata latest.json --zip dist/ritmo-revenue-1.2.0.zip \
    --public-key <base64> --metadata-url https://updates.example.com/ritmo-revenue/latest.json
```

The private key comes from `RITMO_SIGNING_KEY` or `--key-file`; a key file inside the repository is
refused, `keygen` refuses to write inside the repository or over an existing file (mode 0600), and
the key is never printed or written by the script. Upload the zip and `latest.json` to the update
server; put the public key in the plugin (`EMBEDDED_PUBLIC_KEY`) or in `RITMO_UPDATE_PUBLIC_KEY`.
To rotate: trust the new key next to the old one in a release, then sign with the new key.

## 4. Tests

- `tests/Unit/Domain/Licence`: standings and grace periods, the record and its stored form, keys,
  the grace policy, when the check is due.
- `tests/Unit/Connectors/Licence`: every answer of the HTTP protocol, the key only in the POST body,
  the test provider, no server, the address rules.
- `tests/Unit/Application/Licence`: the service (save, check, cooldown, daily check through an
  outage, expiry, replacing and removing the key, development mode, the vault), the enforcer and
  the lowering over SQLite, the settings from constants (separate processes).
- `tests/Unit/Application/Update`: the verifier (unsigned, another key, payload changed, older or
  equal version, other origin, other plugin, malformed fields, rotation), the checker (cache,
  retries, a stored envelope swapped, downloads refused), with a key pair made by each test.
- `tests/Integration/Update`: the providers and the channel over real HTTP against
  `tests/fake-apis/LicenceUpdateApi.php` (every tampering), the WordPress filters
  (`wp-stubs.php`), `bin/sign-release.php` as a process.
- `tests/e2e/m5-licence.js` (`RITMO_E2E_WP=<wordpress folder>`, on a fresh throwaway site): the
  licence tab through every standing (the grace period is waited out by `tests/e2e/licence-age.php`,
  which moves the stored record back in time), the notices, the cooldown, removal, a form without
  nonce, 390 px; then a package changed on the server refused by the WordPress updater, a package on
  another server never offered, and the signed release installed by the updater on a copy of the
  plugin (the site's link to the working folder is put back).

The simulator: `RITMO_FAKE_LICENCE_STATE=<file> RITMO_FAKE_UPDATE_SECRET_KEY=<base64> php -S
127.0.0.1:8093 tests/fake-apis/LicenceUpdateApi.php`; control plane `POST /__licence`,
`POST /__mode` (`up`, `down`, `garbage`, `timeout`), `POST /__release` (with `tamper`: `unsigned`,
`bad_signature`, `payload`, `other_origin`, `wrong_slug`, `zip`, `package_signature`),
`GET /__public-key`, `GET /__log` (the key itself is never logged), `POST /__reset`.

## 5. Open points

- No vendor or update server yet: the embedded address and key are empty, so a site without the
  constants has no licence (shadow mode only) and no update channel. Choosing the vendor means an
  adapter to the protocol of 2.5 (or a new `LicenceServer`) and filling `EMBEDDED_URL` and
  `EMBEDDED_PUBLIC_KEY` at build time.
- The update requests send no licence key: a server that serves updates to customers only must
  protect the package URLs itself.
- Undo of earlier changes needs a writing mode; after the licence lapses the change log keeps the
  "how to undo" of every change for a person to apply by hand. Between the lapse and the next run
  of a property (at most a few hours) an undo asked by a person still goes through, like an
  approval already given; the next run lowers the mode.
- The update channel's own HTTP client is bound to the host of `RITMO_UPDATE_URL`; the package is
  downloaded by WordPress itself and installed only after `UpdateChecker::download()` verified it
  (same origin, size, hash, signature).

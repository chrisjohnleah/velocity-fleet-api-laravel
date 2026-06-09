# Velocity Fleet bridge — adversarial review (2026-06-09, read-only snapshot)

> **Read-only snapshot.** This report consolidates an adversarial review of the
> Velocity Fleet Laravel bridge (commit `ad31c37` + uncommitted WIP). Only
> findings that survived adversarial verification (`verdict.isReal !== false`)
> are included; **12 candidate findings were refuted and dropped**. Severities
> use `verdict.correctedSeverity` where the verifier overrode the original.
>
> **IMPORTANT — re-checked against current working tree before publishing.**
> The uncommitted WIP has moved since the candidate findings were authored.
> During this consolidation pass I re-read the live code and **several findings
> that the candidate set marked `critical`/`high`/`medium`/`low` have already
> been fixed in the working tree** (commands registered, snapshot store bound,
> APP_KEY guard added, scheduler wired, SECURITY.md GDPR section written). Those
> are listed in **"Already fixed in the working tree"** below and excluded from
> the live action tables. Re-verify everything here against the exact code state
> before acting — line numbers may continue to drift.

## Verdict at a glance

**What's solid**
- Security primitives are genuinely present and correct: token columns + history PII columns use Laravel `encrypted` casts (default ON), history is opt-in/default-OFF, `Device::$private` is honoured in `PersistDevicePositions::mapDevice()`, tokens are never logged (`StatusCommand` masks; refresh token rendered as present/missing), and the package exposes **no HTTP routes/endpoints** (deny-by-default).
- Packaging is to spec: granular `illuminate/*` deps (not `laravel/framework`), no wrongful `saloonphp/laravel-plugin` runtime dep, core resolves from Packagist (`v0.1.1`), correct two-level `@mixin` chain, CI matrix L11/12/13 × testbench 9/10/11 × prefer-lowest/stable, `fake()` uses `MockClient::global()` only from an explicit test call (no register-time Octane leak).
- The idempotent history upsert keyed on `(customer_id, device_id, recorded_at)` is correct for at-least-once safety (except the timestamp-less edge case below).

**What's risky**
- **Observability is inert.** The four B.9 lifecycle events (`PositionsFetched`, `TokenRefreshed`, `PollSkipped`, `RequestFailed`) are subscribed but **never dispatched in production code** — the B.9 counters/logs are dead. Tests stay green because they hand-build the events.
- **Binding lifetime contradicts the approved Octane-safe decision** — connector/client/`FleetManager` are `singleton`, not the spec-mandated `scoped`.
- **`history.store_raw=true` writes unencrypted PII** (driver name, registration, phone) to a plaintext `raw` column even when `encrypt_pii=true`.
- **`CacheSnapshotStore` keeps full PII snapshots in cache for 7 days** in the clear, ignoring `$private` and `encrypt_pii` — a PII-at-rest path outside the GDPR controls.
- **Speeding heuristic ignores units** — compares `Device::$speed` to an mph threshold with no `speedMeasureText` normalisation.

**What's missing**
- Device **disappearance** fires no event (B.3 presence: appears/disappears — only "appears" handled).
- `EloquentSnapshotStore` (B.3, durable diff baseline for history-on) is not built.
- DoctorCommand omits the **stale-env-secret** warning (stored row wins over rotated `.env`) and the routes-without-auth note.
- Single-flight under real concurrency, geofence dwell, the default-OFF geofencing gate, `EncryptTokensCommand`, and several false-branches are **untested**.

---

## Critical & High findings

> No findings remain at **critical** severity against the **current working
> tree**: the two candidate criticals (commands unregistered; snapshot store
> unbound) and several highs have been fixed in WIP — see "Already fixed" below.
> The items below are confirmed High against the live code.

| Title | Severity | File | What | Fix |
|---|---|---|---|---|
| B.9 lifecycle events never dispatched | High | `src/Listeners/LogVelocityFleetActivity.php:38-44` (subscribe) vs all of `src/` (no dispatch) | `PositionsFetched` / `TokenRefreshed` / `PollSkipped` / `RequestFailed` are subscribed but **constructed nowhere** in production. Only `PositionHistoryPruned` (B.6) is dispatched (`src/Jobs/PrunePositions.php:41`). Real code uses ad-hoc counter keys (`positions.fetched`, `poll.run`) that don't match the B.9 counters. `ObservabilityTest` hand-builds events, so the suite is green while the feature is inert. | Dispatch at real sites: `PositionsFetched` in `CachedPositions::refresh()`/cache-hit (`fromCache`), `TokenRefreshed` when the core rotates a token (wrap `FleetManager::refresh()` / listen on store put), `RequestFailed` in poll/command catch blocks, `PollSkipped` when a poll is served fresh. Add an end-to-end test that polls via the faked client and asserts the events fire. |
| Connector/client/FleetManager bound `singleton`, not spec-mandated `scoped` | High | `src/VelocityFleetServiceProvider.php:51,58,86` | Spec §6/§11(#2)/B.0 mandate `scoped` for connector + client + `FleetManager` (the connector carries the mutable `TokenAuthenticator`; `scoped` is the Octane-safe lifetime), reserving `singleton` for the stateless `TokenStore`. All are bound `singleton`; zero `scoped(` in `src/`. Octane token-bleed across workers is the documented risk. | Change `VelocityFleetConnector`, `VelocityFleet`, `FleetManager` (and `PositionsCache`) to `scoped()`; keep `TokenStore`/`MetricsRecorder` `singleton`. If `singleton` parity is intended, amend & re-approve the spec. (Verifier note: §6 itself sanctions a `singleton` fallback and v0.1 is single-token, so the practical bleed is theoretical — but it is a thrice-restated spec violation.) |
| Single-flight/SWR never exercised under concurrency | High | `tests/Feature/FleetTest.php` vs `src/Cache/CachedPositions.php:55-89` | The only cache test makes two **sequential** calls and asserts `assertSentCount(1)` — that proves TTL caching (second read is fresh), not single-flight. The contended branches — `served_stale` (`:74-78`) and the `$lock->block()` wait-then-read (`:80-88`) — are never entered. `grep served_stale|->block|LockProvider tests/` → nothing. | Pre-seed a stale snapshot, manually acquire the positions lock via the store's `LockProvider`, assert the stale copy is served + `positions.served_stale` incremented with no extra POST. Add a second test for the no-stale-copy `block()`-then-read path. |

---

## Security-defaults assessment (present vs spec)

| Control | Spec | Status in code | Notes |
|---|---|---|---|
| Encrypted token columns | B.7 — `encrypted` cast, default on | **Present** | `VelocityFleetToken::casts()` adds `encrypted` casts unless `encrypt_tokens === false` (default true). |
| Encrypted history PII columns | B.4 — PII encrypted at rest | **Present (discrete columns)** | `PersistDevicePositions` routes `driver_name`/`vehicle_registration`/`mobile_phone` through `encrypter->encrypt($v, false)`; privacy-by-default. **But `raw` is NOT encrypted** — see finding below. |
| `$private` suppression | B.4 / cross-cutting #1 | **Present** | `PersistDevicePositions::mapDevice()` returns null for `$device->private === true`. **Not honoured by `CacheSnapshotStore`** — see finding below. |
| History opt-in / default-OFF | B.4 / B.18 | **Present** | `history.enabled` default false; two opt-ins needed (`enabled` + `store_raw`) for the raw leak. |
| `raw` column encrypted JSON | B.4 — "(encrypted JSON, optional)" | **MISSING** | `encodeRaw()` is plain `json_encode($device)`; `raw` column is `longText`, no `encrypted` cast, and `IngestPositionsJob` upserts past Eloquent casts anyway. With `encrypt_pii=true + store_raw=true`, discrete columns are ciphertext but the same PII sits cleartext in `raw`. **Medium.** |
| Snapshot store PII handling | cross-cutting #1 / B.3 | **MISSING** | `CacheSnapshotStore` puts the full `PositionsSnapshot` (all-device PII + lat/lon, incl. `$private`) into cache for **604,800s (7 days)**, unencrypted, no `$private` filter, no pruning. Materialises only when `polling.enabled` (default off) + shared persistent cache. **Medium.** |
| Token never logged | B.8 | **Present** | `StatusCommand` masks access token, reports refresh as present/missing; structured logger emits counts/ids only. |
| Redaction regression test (end-to-end) | B.8 / B.17 | **MISSING (test) + dead scrubber** | Only `RedactionTest` exercises `VelocityFleetLogSanitizer::scrub()/mask()` in isolation. No test runs a command/log and asserts a seeded token is absent. `RedactsSecrets::redact()` (which calls `scrub()`) has **zero callers** on a live path (`StatusCommand` uses only `maskToken()`); no HTTP logging middleware exists. **Medium.** |
| Provider APP_KEY guard | B.7 | **Present (fixed in WIP)** | `guardEncryptionKey()` (`VelocityFleetServiceProvider.php:164-178`) now throws a descriptive `RuntimeException`. Candidate finding (High, missing guard) is **obsolete**. |
| DoctorCommand self-checks | B.10 | **Partial** | Implements APP_KEY, ciphertext, retention, cache-driver checks. **Omits the stale-env-secret warning** (stored row wins over a rotated `.env`) and the routes-without-auth note. **Low** (one missing check is moot — no routes; command is registered). |
| GDPR/DPIA in `SECURITY.md` | B.19 | **Present (fixed in WIP)** | `SECURITY.md:23-31` now has a "Data protection (GDPR)" section (PII encrypted, retention enforced, `$private` suppressed, DPIA pointer, v0.2 erasure note). Candidate finding (Low) is **obsolete**. |

---

## Scope coverage matrix (approved item → built?)

| Approved item | Built? | Notes |
|---|---|---|
| B.1 `DeviceCollection->keyByDeviceId()` | Partial | Exists, but `$device->id ?? 0` collapses all null-id devices onto key `0` (last-write-wins). |
| B.2 SWR + single-flight cache | Built (gaps) | `CachedPositions` implements SWR + lock. Lock TTL can be shorter than the retrying upstream fetch; wait-branch refreshes without re-holding the lock. Untested under concurrency. |
| B.3 diff rules (presence/freshness/ignition/movement) | Partial | Appearance, stale↔fresh, ignition, movement handled. **Disappearance not handled** (no second loop over `$before` keys absent from `$current`). New-device reuses `DeviceCameBackOnline`. |
| B.3 `FleetSnapshotStore` + `CacheSnapshotStore` | Built (fixed in WIP) | Now bound in the provider (`:91`). `CacheSnapshotStore` present. |
| B.3 `EloquentSnapshotStore` (history-on) | **Missing** | Only `CacheSnapshotStore` exists; no descope note. Diff baseline lives in volatile cache even with history on. |
| B.4 idempotent history upsert | Built (edge gap) | Correct for timestamped devices; **non-idempotent for timestamp-less devices** (falls back to `now()`). `raw` column unencrypted. |
| B.5 PollCommand + scheduled polling | Built (fixed in WIP) | `PollCommand` registered; `registerSchedule()` now wires `velocity-fleet:poll` gated on `polling.enabled` with `withoutOverlapping()->onOneServer()`. Candidate "polling not implemented" (High) is **obsolete**. |
| B.6 PrunePositions + command | Built | `PrunePositionsCommand` registered; prune scheduled daily when history on; dispatches `PositionHistoryPruned`. |
| B.7 EncryptTokensCommand | Built (untested) | Registered; no tests for its plaintext→ciphertext migration / idempotency / `encrypt_tokens=false` no-op. |
| B.8 log redaction | Built (dead path) | Sanitizer exists; `scrub()`/`redact()` not wired to any live path; no HTTP logging middleware. |
| B.9 structured logging + 4 counters | **Dead** | Listener subscribes; events never dispatched in production. |
| B.10 DoctorCommand | Partial | Registered; missing stale-env + routes checks. |
| B.12/B.13 geofencing + heuristic alerts | Built (gaps) | Geofence matcher + alerts built. Speeding has no unit normalisation; dwell + default-OFF gate untested. |
| B.16 commands (`:poll/:prune-positions/:encrypt-tokens/:doctor`) | Built (fixed in WIP) | All four now in the `commands([...])` array (`:107-115`). Candidate "commands unregistered" (Critical) is **obsolete**. |

---

## Medium / Low findings

| Title | Severity | File | What | Fix |
|---|---|---|---|---|
| `store_raw=true` writes unencrypted PII to `raw` | Medium | `src/Listeners/PersistDevicePositions.php:86,101-104`; migration `longText('raw')` | `encodeRaw()` = `json_encode($device)` with no encryption; `raw` not cast `encrypted`; upsert bypasses casts. PII leaks cleartext when `encrypt_pii=true + store_raw=true`. | Encrypt the raw payload with the same encrypter when `encrypt_pii` is on, or strip PII keys before encoding. Document store_raw's PII implications. |
| `CacheSnapshotStore` persists 7-day PII in plaintext | Medium | `src/Snapshot/CacheSnapshotStore.php:23,36` | Full `PositionsSnapshot` (all-device PII + lat/lon incl. `$private`) cached 604,800s, unencrypted, no `$private` filter, no pruning. PII-at-rest path outside GDPR controls. | Encrypt the serialized snapshot (or store only diff-relevant fields), shorten TTL toward the refresh window, exclude `$private` devices, document it as in PII scope. |
| Device disappearance fires no event | Medium | `src/Polling/FleetPoller.php:78-104` | `diff()` only iterates `$current->devices`; keys present in `$before` but absent from `$current` are never visited. A vehicle that drops off the feed entirely never alerts. B.3 names "appears/disappears". | After the current-device loop, walk `$before` keys not seen in `$current` and emit a stale/offline (or dedicated `DeviceDisappeared`) event. |
| Speeding heuristic ignores units | Medium | `src/Listeners/SendFleetAlerts.php:138-144`; `src/Notifications/SpeedingNotification.php:46` | `detectSpeeding` compares `$device->speed` directly to `speeding_mph` with no `speedMeasureText` normalisation; a km/h fleet at 90 km/h (~56 mph) trips an 80-"mph" threshold. Mail copy mixes units: `{$speed} {$unit}, above the {$thresholdMph} mph threshold`. | Convert to mph via `speedMeasureText` before comparing, or compare in native unit and label the threshold dynamically; guard when `speed_measure_text` disagrees with the configured unit. |
| `EloquentSnapshotStore` not built | Medium | `src/Snapshot/` (only `CacheSnapshotStore`) | B.3 specifies both implementations; only the cache one exists, no descope note. With history on, the diff baseline still lives in volatile cache → cache flush/worker restart → missed transitions / spurious re-seed. | Build `EloquentSnapshotStore` and bind it when `history.enabled`, or explicitly descope it in the spec/CHANGELOG with the durability caveat documented. |
| DoctorCommand missing stale-env + routes checks | Low | `src/Commands/DoctorCommand.php:34-49` | Implements APP_KEY/ciphertext/retention/cache checks but no warning when stored token diverges from a rotated `.env` value (the gotcha B.10 calls "behind most real breaches"), and no routes audit. | Add a stored-row-vs-config divergence warning; add a no-op route-audit pass note (package ships no routes). |
| History upsert non-idempotent for timestamp-less devices | Low | `src/Listeners/PersistDevicePositions.php:67-70`; `src/Jobs/IngestPositionsJob.php:60-77` | `recorded_at` falls back to `now()` when `occurredAt()` is null; `now()` differs across polls so the conflict target never matches → duplicate rows for the same logical position. | Derive a deterministic `recorded_at` (e.g. snapshot `fetchedAt` through the event), or skip persistence for timestamp-less devices. |
| SWR lock TTL can be shorter than a retrying fetch | Low | `src/Cache/CachedPositions.php:36,82-88,121` | Lock TTL default 10s; core retries `tries=3, retryInterval=1000ms, exponential` can exceed 10s → lock auto-expires → a second worker POSTs. Also the no-stale-copy wait branch `release()`s then `refresh()`s **without re-acquiring the lock**. | Size the lock TTL to bound worst-case retry (or renew during a long fetch); re-acquire the lock before the fallback `refresh()`. |
| `keyByDeviceId` collapses null-id devices to key `0` | Low | `src/Support/DeviceCollection.php:103` | `keyBy(fn($d) => $d->id ?? 0)` — multiple null-id devices overwrite each other (last-write-wins), silently dropping devices. The poller's `deviceKey()` avoids this; the public helper doesn't. | Skip or surface null-id devices (reject, or key on a string `'id:'.$id`). |
| Octane shared-store warning not surfaced at runtime | Low | `src/Cache/CachedPositions.php`; `src/Commands/DoctorCommand.php` | DoctorCommand `checkCacheDriver()` does WARN on `array`/`file` — but there's no boot-time log and no store-capability check; the lock silently returns null on non-`LockProvider` stores. (Degrades safely.) | Consider a soft boot-time `Log::warning` when the resolved store is `array`/`file` while polling/cache is on. |
| New-device reuses `DeviceCameBackOnline` | Info | `src/Polling/FleetPoller.php:121-125` | First sighting of a fresh device emits `DeviceCameBackOnline` ("previously stale device started reporting fresh again" per its own docblock) → false positives on fleet growth. | Add a distinct `DeviceAppeared` event (or suppress online-event for first sightings). |
| Pest dev constraint widened `^4.0` → `^3.0 || ^4.0` | Info | `composer.json:32` | Benign spec-text deviation, likely intentional for CI matrix breadth. | Pin to `^4.0` for exact parity, or document the widening. No correctness impact. |
| `/docs` not export-ignored — ships in dist | Low | `.gitattributes` | 3 internal research/spec markdown files (172K) bundle into every `composer require` dist, contradicting the file's own "keep lean" intent. | Add `/docs export-ignore`. |

---

## Test coverage gaps

| Gap | Severity | Where |
|---|---|---|
| Single-flight under concurrency never exercised (only sequential happy-path) | High | `tests/Feature/FleetTest.php` vs `CachedPositions::55-89` (`served_stale`, `block()` branches uncovered) |
| Geofencing default-OFF gate untested | Medium | `GeofenceMatcherTest` bypasses the listener; `grep geofencing.enabled tests/` → nothing; `MatchGeofences` never exercised end-to-end |
| Geofence dwell (`VehicleDwelledInGeofence`) completely untested | Medium | `GeofenceMatcher::checkDwell:114-142`; no `setTestNow`/`dwell_minutes`/`VehicleDwelledInGeofence` references in tests |
| `EncryptTokensCommand` has zero tests | Medium | `src/Commands/EncryptTokensCommand.php` — plaintext→ciphertext migration + idempotency + `encrypt_tokens=false` no-op all unverified |
| B.8 redaction has no end-to-end (command/log) regression test | Medium | `tests/Unit/RedactionTest.php` tests sanitizer in isolation only; no `Log::fake`/token-absence assertion |
| `store_raw=true` history branch + geofence-breach notifications + multi-channel fan-out uncovered | Low | `PositionHistoryTest` pins `store_raw=false`; `SendFleetAlertsTest` omits entered/exited geofence + multi-route recipients |
| `velocity-fleet:customers` success path + `keyByDeviceId` untested | Low | `CommandsTest:40-42` only asserts the not-connected failure; `DeviceCollectionTest` never calls `keyByDeviceId` |

---

## Best-practice notes

- **Positive (confirmed):** no wrongful `saloonphp/laravel-plugin` runtime dep; granular `illuminate/*` deps (not `laravel/framework`); core resolves from Packagist (`v0.1.1`) with no `repositories` block (correct end state); `extra.laravel` auto-discovery; `allow-plugins` limited to `pestphp/pest-plugin`; correct two-level `@mixin` chain; CI matrix L11/12/13 × testbench 9/10/11 × prefer-lowest/stable; `fake()` uses `MockClient::global()` only from an explicit test call (no register-time Octane global-state leak).
- **Tokens never logged; no routes/endpoints (positive confirmation):** `EloquentTokenStore` reads/writes via casts; `StatusCommand` masks the access token (`VelocityFleetLogSanitizer::mask()` = 4-char prefix + asterisks) and reports refresh as present/missing; no `loadRoutesFrom`/`Route::`/`routes/` in the package.
- **Adopt `saloonphp/laravel-plugin`** for connection-pool reuse once the scoped-binding fix lands (spec notes this as a future optimisation). No action needed today.

---

## Already fixed in the working tree (candidate findings now obsolete)

Re-checking the live code shows the following candidate findings have been addressed in the uncommitted WIP and should **not** be re-raised:

| Candidate finding | Candidate severity | Current state |
|---|---|---|
| Four commands (`doctor`/`poll`/`prune-positions`/`encrypt-tokens`) never registered | Critical | **Fixed** — all in `commands([...])` (`VelocityFleetServiceProvider.php:107-115`). |
| `FleetSnapshotStore` never bound → `FleetPoller` unconstructable | Critical→High | **Fixed** — bound at `:91` (`FleetSnapshotStore` → `CacheSnapshotStore`). |
| Scheduled polling (B.5) not implemented | High | **Fixed** — `registerSchedule()` (`:203-217`) wires `velocity-fleet:poll` gated on `polling.enabled`, `withoutOverlapping()->onOneServer()`; prune scheduled daily. |
| Provider APP_KEY guard missing (B.7) | High | **Fixed** — `guardEncryptionKey()` (`:164-178`) throws a descriptive `RuntimeException`. |
| `SECURITY.md` lacks GDPR/DPIA/retention guidance (B.19) | Low | **Fixed** — `SECURITY.md:23-31` adds the GDPR section. |

(These remain in this report only as obsolete entries for traceability. The
candidate `DoctorCommand`/Octane-warning findings also asserted the command was
unregistered — that sub-claim is now false; the residual gap is only the missing
stale-env warning.)

---

## Recommended next actions (prioritised)

1. **Dispatch the four B.9 events at their real sites** and add an end-to-end test that drives the poll/refresh/error path and asserts the counters/logs fire. Highest-leverage: an entire delivered observability spec item is currently inert while tests are green. (High)
2. **Flip connector/client/`FleetManager` (+`PositionsCache`) bindings to `scoped`**, keep `TokenStore`/`MetricsRecorder` `singleton` — or amend the spec if `singleton` is intentional. (High, spec contract)
3. **Test single-flight under concurrency** (pre-seed stale + manual lock acquisition for `served_stale`; no-stale-copy `block()` path). (High)
4. **Close the two PII-at-rest leaks:** encrypt (or PII-strip) the `raw` column when `encrypt_pii` is on; treat `CacheSnapshotStore` as PII (encrypt/shorten TTL/exclude `$private`). (Medium, GDPR)
5. **Normalise speeding units** (convert via `speedMeasureText` or compare in native unit with dynamic threshold label); and **emit a disappearance event** in `FleetPoller::diff()`. (Medium)

Then mop up: add the DoctorCommand stale-env warning, build/descope `EloquentSnapshotStore`, deterministic `recorded_at` for timestamp-less devices, lock-TTL sizing + re-acquire in the SWR wait branch, `keyByDeviceId` null-id handling, `/docs export-ignore`, and the remaining test gaps (geofence default-OFF gate, dwell, `EncryptTokensCommand`, end-to-end redaction, `customers` success path).

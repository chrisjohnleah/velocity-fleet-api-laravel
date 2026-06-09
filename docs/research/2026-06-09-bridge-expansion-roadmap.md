# Velocity Fleet Laravel bridge — expansion roadmap (2026-06-09)

> Consolidates five expert idea-sets (Fleet Ops, Laravel DX, SRE/Reliability, Data/Analytics,
> Security/Compliance) into ONE prioritized, deduped roadmap for
> `chrisjohnleah/velocity-fleet-api-laravel`.
>
> **Lens legend:** `FO` = Fleet Ops · `DX` = Laravel DX · `SRE` = Reliability/SRE ·
> `DA` = Data/Analytics · `SEC` = Security/Compliance.
>
> **Grounding facts** (from `2026-06-09-core-sdk-inspection.md`): the core SDK exposes ONLY
> `customers()->list()` and `devicePositions()->forCustomer($id)` / `->devices($id)`. There is
> **no webhook/push** — the `DevicePositions` DTO literally returns `liveMapRefreshRate` (~30s)
> and `liveMapLargeFleetRefreshRate` (~90s). Device DTO already carries `$private (?bool)`,
> `$signalStrengthColor`, `$driverGroups`, `ignitionOn(): ?bool`, `occurredAt(): ?DateTimeImmutable`.
> `ApiException` exposes `$status` and `$body` but **NOT response headers**. Core is Saloon v4
> (so `MockClient` / `saloonphp/laravel-plugin` fakes work without touching core). Bridge
> namespace root: `ChrisJohnLeah\VelocityFleet\Laravel\` → `src/`.

---

## Cross-cutting concerns (read first — these shape even the v0.1 additions)

1. **PII / UK GDPR is structural, not a feature.** Driver names, registrations, mobile numbers,
   driver IDs and continuous lat/lon traces are personal data; joined over time they reveal
   homes/routines and constitute **worker monitoring** (DPIA + lawful-basis territory). Any
   history table must ship **opt-in (default off)**, **encrypted PII columns**, **enforced
   retention/pruning**, and **erasure/subject-access** tooling. The Device DTO already exposes
   `$private` — honour it as the default suppression signal. *(SEC, DA)*
2. **Respect the provider's own 30s/90s hints — never poll faster.** Drive cache TTL, scheduler
   cadence and `wire:poll` intervals from the DTO's `liveMapRefreshRate` /
   `liveMapLargeFleetRefreshRate` (clamped by a config floor), not a hard-coded number. Coalesce
   concurrent callers to one upstream POST per refresh window (single-flight lock + cache).
   *(SRE, DX, DA)*
3. **Octane / multi-worker safety.** The core inspection already flags that a shared long-lived
   client can bleed tokens across workers — bind the `VelocityFleet` client **transient** (or
   per-request). Locks, SWR cache and `onOneServer()` polling REQUIRE a **shared atomic-lock
   store** (redis/memcached/database), not the `array`/`file` default — document and default-guard,
   or each worker polls independently and the lock is a no-op. *(SRE)*
4. **Secrets out of logs/status/exceptions.** Tokens leak via logs/stack-traces far more than DB
   theft. Encrypt token columns at rest, keep HTTP debug logging opt-in (default silent), redact
   token-shaped substrings and the refresh form-body, and lock it with a regression test. *(SEC)*

---

## Verdict scale

Effort `S` ≤ ~1 day · `M` ~2–4 days · `L` ~1 week+. Verdict = value-for-effort given the
poll-only, PII-heavy reality.

---

## Fold into v0.1 NOW

High-value, low-effort, **bridge-only (not core-gated)** wins that materially raise real-world
usefulness without bloating the package. The poller + snapshot store + cache are the architectural
spine everything else hangs off, so they earn their place despite being `M`.

| # | Title | What (one line) | Value | Effort | Core-gated | Verdict |
|---|-------|-----------------|-------|--------|-----------|---------|
| 1 | **Typed `DeviceCollection` + `VelocityFleet::fleet($id)` query sugar** `DX` | Telematics-aware Collection scopes (`online/offline/moving/idling/ignitionOn/inDriverGroup/near/byRegistration`) over existing Device fields | Turns a flat `list<Device>` into `->moving()->inDriverGroup(7)->near($lat,$lon,5)`; single highest-leverage DX win, equally great personal (`->byRegistration('AB12 CDE')`) | S–M | no | **Ship.** Pure derivation, no new endpoint, `Macroable` for app scopes |
| 2 | **Refresh-rate-aware SWR cache + single-flight lock** `SRE` `DX` `DA` | `VelocityFleet::cached()->...->forCustomer($id)`; TTL read FROM the DTO hint; `Cache::lock` dedupes concurrent polls; stale-while-revalidate | Collapses N dashboard widgets/workers into one upstream POST per window; instant renders; keeps you a polite citizen and off the rate-limiter | M | no | **Ship.** The headline reliability feature for a poll-only API |
| 3 | **`FleetPoller` + `PollFleetJob` + change-detection events** `FO` `DX` `SRE` `DA` | Queued job diffs new vs previous snapshot, fires `IgnitionTurnedOn/Off`, `VehicleStartedMoving/Stopped`, `DeviceWentStale/CameBackOnline`, `DevicePositionsUpdated`; `FleetSnapshotStore` contract | Synthesises the push layer the provider lacks — the whole reason a bridge adds value; everything downstream (notify/trip/geofence) subscribes | M | no | **Ship.** The heart of the package |
| 4 | **Opt-in snapshot/position persistence (idempotent upsert)** `FO` `SRE` `DA` `SEC` | `velocity_fleet_device_positions` + `DevicePositionRecord`; upsert keyed on `(device_id|service_id, timestamp)`; **off by default**, PII columns encrypted | System-of-record for a history-less API — hard dependency for trips/mileage/replay; idempotent upsert makes at-least-once queues safe | M | no | **Ship the table + listener; defer trip/mileage derivation to v0.2.** Off-by-default + encrypted + pruned |
| 5 | **Config-driven scheduled polling honouring the hints** `DX` `SRE` `DA` | Provider registers `PollFleetJob` on the scheduler gated by `config('velocity-fleet.polling')`; `RefreshRateResolver` picks 30s vs 90s by `deviceCount`; `withoutOverlapping()->onOneServer()`; `velocity-fleet:poll` command | "Set it and forget it" background tracking in one config line, paced by the API's own recommendation | M | no | **Ship, default OFF.** Auto-scheduling a package task is opinionated — gate behind explicit config |
| 6 | **Retention pruning + `velocity-fleet:prune-positions`** `SRE` `DA` `SEC` | Self-scheduling prune of rows older than `retention.positions_days` (conservative default ~90); fires `PositionHistoryPruned` with count | Makes the riskiest feature (history) the safest path of least resistance; documented retention = one config value, not a forgotten cron | S | no | **Ship alongside #4.** GDPR table-stakes the moment you persist |
| 7 | **Encrypt token columns at rest + APP_KEY guard** `SEC` | Cast `access_token`/`refresh_token` as `encrypted` on the token model; loud failure if `encrypt_tokens` on without APP_KEY; idempotent `velocity-fleet:encrypt-tokens` migration command | ~5 lines neutralise at-rest exposure of a live fleet credential in any DB dump/backup; safe-by-default for the naive installer | S | no | **Ship.** Highest value-for-effort hardening in the whole list |
| 8 | **Secret redaction in status/logs/exceptions (+ regression test)** `SEC` | Truncate refresh token in `status`, mask `Authorization`/refresh form-body in any HTTP logger, scrub token-shaped substrings from `ApiException::$body` context; `log_channel` default null (opt-in) | Tokens leak through logs/Sentry/tickets, not DB theft; lock it with a test so it can't regress | S | no | **Ship.** Cheap, closes the realistic leak path |
| 9 | **Structured logging + metrics events** `SRE` | `PositionsFetched`, `TokenRefreshed`, `PollSkipped(reason)`, `RequestFailed` to a dedicated channel; pluggable dependency-free `MetricsRecorder` contract (null default) | You cannot run poll-only ingestion blind; four counters answer "polite? token healthy? poller alive?"; clean host extension points | S | no | **Ship.** PII-redacting formatter by default; don't log device-level fields |
| 10 | **`velocity-fleet:doctor` security self-check** `SEC` | CI-runnable: warns on stale env secret after rotation (stored-row-wins gotcha), APP_KEY present, columns actually ciphertext, retention set if history on, routes-without-auth | Turns "use it securely" into an enforceable check; catches the config mistakes behind most real breaches before prod | S | no | **Ship.** Must never print the secrets it inspects |
| 11 | **`VelocityFleet::fake()` + Device/DevicePositions factories + scenario builder** `DX` `SRE` `DA` | MockClient-backed fake; `FakeDevice::make([...])`, `FakeDevicePositions::withDevices([...])`, a `FleetScenario` staging snapshot sequences; assertions `assertPolled`, `assertEventDispatchedForDevice` | Every feature above is only trustworthy if testable without hitting a live PII feed; the DX multiplier that makes the rest adoptable | M | no | **Ship.** Decision: adopt `saloonphp/laravel-plugin` (dev-dep at minimum); bake the awkward UPPERCASE refresh-rate keys into fixtures |

**Files/classes each NOW item adds** (namespace root `ChrisJohnLeah\VelocityFleet\Laravel\`):

- **#1** `Support\DeviceCollection`, `Support\FleetQuery`, `Support\Haversine`; facade method `VelocityFleet::fleet($customerId)`.
- **#2** `Cache\CachedPositions` (decorator) + `Contracts\PositionsCache`, `ValueObjects\PositionsSnapshot`, `Support\RefreshRateResolver`; `VelocityFleet::cached()` accessor; `config('velocity-fleet.cache')`.
- **#3** `Polling\FleetPoller`, `Jobs\PollFleetJob`, `Contracts\FleetSnapshotStore` (+ `Cache`/`Eloquent` impls), `Events\{IgnitionTurnedOn,IgnitionTurnedOff,VehicleStartedMoving,VehicleStopped,DeviceWentStale,DeviceCameBackOnline,DevicePositionsUpdated}`.
- **#4** `Models\DevicePositionRecord`, `database/migrations/..._create_velocity_fleet_device_positions_table`, `Listeners\PersistDevicePositions`, `Jobs\IngestPositionsJob`; `config('velocity-fleet.history')` (default `enabled => false`).
- **#5** scheduler hook in `VelocityFleetServiceProvider::boot()`, `Commands\PollCommand` (`velocity-fleet:poll`), `Support\RefreshRateResolver` (shared with #2); `config('velocity-fleet.polling')`.
- **#6** `Jobs\PrunePositions`, `Commands\PrunePositionsCommand`, `Events\PositionHistoryPruned`; `config('velocity-fleet.retention')`.
- **#7** `encrypted` casts on `Models\VelocityFleetToken`, migration column type → `text`, `Commands\EncryptTokensCommand`, provider APP_KEY guard; `config('velocity-fleet.encrypt_tokens')` (default true).
- **#8** `Support\RedactsSecrets` / `VelocityFleetLogSanitizer`, optional Saloon logging middleware, exception-context scrubber; assertion in `CommandsTest`; `config('velocity-fleet.log_channel')` (default null).
- **#9** `Events\{PositionsFetched,TokenRefreshed,PollSkipped,RequestFailed}`, `Listeners\LogVelocityFleetActivity`, `Contracts\MetricsRecorder` (+ null recorder); `config('velocity-fleet.logging')`.
- **#10** `Commands\DoctorCommand` (`velocity-fleet:doctor`).
- **#11** `Testing\InteractsWithVelocityFleet` trait, `Testing\FakeDevice`, `Testing\FakeDevicePositions`, `Testing\FleetScenario`, custom assertions; `VelocityFleet::fake()`.

---

## v0.2 (next)

Strong but heavier, or mildly core-adjacent. Mostly the "operational value" tier that the v0.1
spine unlocks.

| Title | What | Value | Effort | Core-gated | Verdict |
|-------|------|-------|--------|-----------|---------|
| **Geofences as Eloquent models + enter/exit/dwell events** `FO` `DX` `DA` | `Geofence` model (circle/polygon, optional `device_group_id`), `GeofenceMatcher` runs each poll tick (haversine + ray-casting, no PostGIS), durable per-device state, fires `VehicleEnteredGeofence`/`VehicleExitedGeofence` + feeds `VehicleArrived` | Converts raw positions into business meaning: depot arrival/departure stamps, site-arrival billing proof, after-hours alerts; personal "home/work" zones; #1 ask after the live map | L | no | **Yes.** The backbone for alerts/timesheets. Document the cadence-vs-fence-size miss risk |
| **Batteries-included fleet notifications + `velocity-fleet` channel** `FO` `DX` | `VehicleArrived/IdlingTooLong/DeviceOffline/Speeding/GeofenceBreached` notifications (`toMail/toSlack/toDatabase/toBroadcast`); `AlertRule` throttle; recipients routed per driver-group via config | Alerts are the #1 reason a small fleet opens a tracking product; theft tripwire on 3am movement; zero glue code | M | no | **Yes.** Wire to v0.1 events; honour throttling to avoid alert storms |
| **Behaviour/exception detection** `FO` | `BehaviourAnalyzer` over the poll stream → `VehicleIdling`, `SpeedingDetected` (per-group limit), `HarshDrivingDetected`; `incidents` table | The morning exception list; fuel waste + liability; pairs with notifications | M | no | **Yes.** Harsh-driving is approximate from poll cadence — label as heuristic |
| **Trip / journey detection from ignition transitions** `FO` `DA` | `Trip` model + `DetectTrips` job segmenting history on ignition false→true / true→false (with `trip_gap_minutes` fallback); `TripStarted`/`TripCompleted`; min-distance/duration noise filter | Trips, not pings, are the unit ops reason about — payroll, billing, coaching, personal logbook | L | no | **Yes (heavier).** Needs history table first; store driver at trip start |
| **Mileage & utilisation daily rollups** `DA` | `DailyVehicleSummary` (distance/moving/idle/trip_count/top_speed) via haversine (stock MySQL/SQLite); `velocity-fleet:rollup`; `FleetUtilisation` reporter | HMRC mileage, fleet right-sizing, idle-fuel coaching — the reports owners pay vendors for; pre-aggregated = instant dashboards | M | no | **Yes.** Document haversine over-reports vs odometer; needs a config timezone |
| **Geospatial query API (nearest / within-radius)** `DA` | `nearest($lat,$lon,$limit)`, `withinRadius()`, `boundingBox()` scopes via haversine SQL + bbox pre-filter; "latest position per device" | "Which van is closest to this breakdown?" — highest-value real-time dispatch question; saves fuel on every job | M | no | **Yes.** Reuses the haversine core; document full-scan limits past ~thousands |
| **spatie/laravel-health checks** `SRE` | `ConnectionCheck` (cheap `customers()->list()` smoke, cached), `TokenCheck` (expiry/missing window), `FreshnessCheck` (SWR snapshot younger than 2× hint = poller alive) | One green/red signal for the three things that break poll-only telematics: connectivity, token, stalled poller (the worst — stale data that looks live) | M | no | **Yes.** Optional suggest dep; no-op if absent; rate-limit the connection check |
| **Circuit breaker on repeated auth failure** `SRE` | Count consecutive `AuthenticationException`; trip OPEN for cooldown, HALF-OPEN probe; `CircuitOpened`/`Closed` events; CIRCUIT row in `status` | Stops machine-gunning the token endpoint when a stale env token 401s forever (account-lock risk); bounded, observable, alertable | M | no | **Yes.** Must NOT trip on 5xx/429; coordinate with core's reactive 401-refresh so one legit rotation isn't a "failure" |
| **Per-customer fan-out poller with backoff** `SRE` | `velocity-fleet:poll` lists customers, dispatches one `RefreshPositionsJob` per customer on a dedicated queue; `tries`/`backoff`/`retryUntil`/`WithoutOverlapping` middleware | One slow/failing customer doesn't stall others; clean Horizon per-job metrics; multi-instance safe | M | no | **Yes.** Document the two retry layers (job backoff × Saloon connector retry) so total time is bounded |
| **PII-aware encrypted history columns + erasure/DSAR tooling** `SEC` `DA` | Encrypt `driver_name/mobile/registration` by default; `velocity-fleet:forget-driver` (delete/redact, dry-run default) + `velocity-fleet:export-driver`; cascade across positions/trips/summaries/geofence-events; audit counts only | UK GDPR Art.15/17 are hard legal obligations with deadlines; the difference between a DPO sign-off and a legal veto | M | no | **Yes.** Prefer matching on stable `driver_id`; provide per-column encrypt escape hatch for analytics |
| **Access audit log for every PII read** `SEC` | `PositionsAccessed`/`CustomersAccessed` events (customer_id, counts, actor, IP) via a thin observed-client proxy → `velocity_fleet_access_logs`; in the prune sweep | "Who looked at this driver's location, when" — accountability for worker monitoring + breach investigations | M | no | **Yes.** Store counts + customer_id only, never driver PII in the audit row; prune |
| **Tamper-evident token-rotation / auth-failure event stream** `SEC` | TokenStore decorator emits `TokenRefreshed`/`TokenRotated`/`AuthenticationFailed`; `LogSecurityEvents` listener (no secrets); `on_repeated_auth_failure` notification threshold | Detect credential compromise / broken connections / token-endpoint brute force in near-real-time instead of days later | S | no | **Yes (cheap, high signal).** Debounce alerts during legitimate provider outages |
| **Test/dev data toolkit: route simulator + fixture recorder** `DA` `SRE` | `RouteSimulator` emits moving positions along waypoints; `velocity-fleet:record --replay` captures real polls to JSON and replays them; reliability fixtures (429+Retry-After, 401→200 rotation, timeout) | Stateful time-series logic (trips/geofences/mileage) is flaky against a live 30s feed; deterministic fixtures + record/replay accelerate everything and capture real edge cases | M | no | **Yes.** Keep faker in a test-only namespace; fixtures must mirror exact DTO quirks |

---

## v0.3+ / future

Big, speculative, or "data-product polish" that only pays off once the v0.1/v0.2 spine exists.

| Title | What | Value | Effort | Core-gated | Verdict |
|-------|------|-------|--------|-----------|---------|
| **Livewire live-map + status-grid components** `DX` | `<livewire:velocity-fleet-map>` (Leaflet/MapLibre, no key), `<x-velocity-fleet::status-grid>`; `wire:poll` bound to the cached refresh-rate hint; reads through the cache layer; Alpine fallback | The demo that sells the package — "install, point at a customer id, watch the fleet move"; great for personal dashboards | L | no | **Later.** High polish, but the headless event/cache spine must land first |
| **Exporters: CSV / GeoJSON / GPX** `DA` | `PositionExporter` with pluggable formatters; `velocity-fleet:export` streaming via lazy collections; LineString-per-trip GeoJSON, GPX tracks | Data leaves the silo: GPX for Strava/route-proof, GeoJSON for analysts/QGIS, CSV for the bookkeeper/insurer | M | no | **Later.** Cheap once history+trips exist; MUST stream + pair with redaction/consent |
| **Live-map snapshots + journey replay** `DA` | `ReplayService::stateAt($when)` / per-device `ReplayFrame` stream for animation; ties into GeoJSON/GPX | Settles disputes/incidents definitively; rebuild the map at any past instant; visual payoff of persisting everything | M | no | **Later.** Default: derive replay from positions table (don't store full-payload snapshots); label inter-frame gaps as estimated |
| **`velocity-fleet:make-*` scaffolders** `DX` | `make-listener --event=`, `make-notification`, `make-poll-handler` on `GeneratorCommand`; publishable stubs | Lowers activation energy from "read docs and wire by hand" to one correctly-typed command; first-party polish | S | no | **Later (nice-to-have).** Pure ergonomics; ship once the event/notification surface is stable |
| **429 / Retry-After honouring + adaptive throttle** `SRE` | Persist provider cooldown per customer, skip throttled customers across polls; adaptive back-off beyond the hint on repeated 429s | "Briefly rate-limited" vs "continuously re-throttled every 30s"; the politest-citizen behaviour for a big account | M | **partial — see below** | **Later / partly blocked.** Status-only cooldown is bridge-only; **precise** Retry-After is core-gated |
| **Signed, ability-gated HTTP routes / position policies** `SEC` | Routes default OFF; `auth` + `VelocityFleetPositionPolicy` (`viewPositions`/`viewDriver`) + signed temporary share-links with short TTL + `no-store` headers | Lets the genuinely-wanted "share my van's live location" / internal dashboard ship without becoming an unauthenticated location-leak by default | M | no | **Later.** Largest attack surface — must NOT ship before audit + encryption land; deny-by-default policy stub |
| **PII governance: redaction + private-trip handling + DPIA pointer** `DA` `SEC` | `Anonymiser`/`PiiPolicy` at persist/export time (lat/lon rounding, name hashing/omission), honour `Device::$private`, `PositionAboutToPersist` filter event, SECURITY.md DPIA guidance | Makes the package adoptable by businesses otherwise blocked by compliance review — a real differentiator | M | no | **Later.** Folds into the v0.2 erasure/encryption work; make precision configurable per-store |

---

## Blocked on core SDK

Only one idea is genuinely core-gated; everything else above is bridge-only.

| Title | Why blocked | Exact core change needed |
|-------|-------------|--------------------------|
| **Precise `Retry-After` honouring on 429** `SRE` | The bridge sees a 429 only as a generic `ApiException` carrying `$status` + `$body` — the core does **not** expose response **headers** on the exception, so the `Retry-After` value is unreachable. | `ApiException` (and/or the failed `Response` surfaced from `send()`/`dispatch()`) must expose response **headers**, e.g. `ApiException::headers(): array` or a `getResponse()`/`retryAfter(): ?int` accessor. Until then, ship the **status-only cooldown** (fixed conservative default) as a documented limitation — that part is NOT blocked. |

> Note: the adaptive-throttle, single-flight, SWR-cache and circuit-breaker reliability ideas all
> work today **without** core changes because they key off status codes and the cache, not headers.
> Only the *precise* `Retry-After` duration needs core to grow.

---

## Opinionated recommendation

**If this were my own business + personal package, the first build I'd actually ship** is a tight
headless spine — events and data, no UI — because the UI is worthless without a trustworthy feed
underneath, and because the riskiest thing (location history) has to be born compliant or never.

**Shortlist for the first build (the "Fold into v0.1 NOW" 11), in dependency order:**

1. **Cache + single-flight (#2)** and **DeviceCollection (#1)** first — instant DX win + provider
   politeness; useful even with zero background jobs.
2. **FleetPoller + events (#3)** — the one feature that justifies the package existing; everything
   personal *and* business hangs off "my van just left home" / "driver started their shift".
3. **Encrypted tokens (#7) + redaction (#8) + doctor (#10) + token-rotation events** — security is
   cheap here and a leaked live-fleet credential is unacceptable; I want safe-by-default before any
   data lands.
4. **Opt-in encrypted history table (#4) + pruning (#6)** — the system of record, born compliant.
5. **Scheduled polling (#5)** default-off, **structured logging/metrics (#9)**, and
   **`fake()` + factories (#11)** so I can actually test the transitions I'm building a business on.

**Why this and not more:** this set takes a poll-only, amnesiac, PII-heavy API and turns it into an
event-driven, observable, queryable, GDPR-defensible feed — the irreducible core that makes *every*
later feature (geofences, trips, notifications, map) possible and testable. It's deliberately
**headless**: no Livewire, no exporters, no routes yet.

**What I'd deliberately leave out of the first build (YAGNI) and why:**

- **Livewire live-map / Blade components** — seductive demo, but it's a `wire:poll` over the cache I
  can build in an afternoon *after* the feed is solid. Shipping it first inverts the value: a pretty
  map over an untested, secret-leaking, unbounded-history backend.
- **Exporters / replay / snapshots** — zero value until there's history worth exporting; pure
  derivation I can add in a day later. Building them now is speculative storage design.
- **HTTP routes / policies / signed share-links** — the largest attack surface; I refuse to ship a
  network-reachable location endpoint before the audit + encryption layers are proven. Default-off
  even when it lands.
- **Trip detection / mileage rollups in v0.1** — I'll ship the *history table* now but defer the
  ignition-segmentation and daily rollups to v0.2; getting trip boundaries right (jitter, missing
  ignition, gap fallback) is `L` of fiddly edge-cases that shouldn't block the spine.
- **`make-*` scaffolders** — ergonomics polish; the event/notification names need to settle first or
  I'll be regenerating stubs.
- **Precise Retry-After** — blocked on core; a fixed conservative cooldown is good enough until the
  feed is real and I can justify a core PR.

# Design — `chrisjohnleah/velocity-fleet-api-laravel` (Laravel bridge)

**Date:** 2026-06-09
**Status:** Approved — base bridge (Parts 1–13) approved; **expanded scope approved 2026-06-09 (see Part B): event-driven fleet platform = the 11 "fold-in-now" items + geofences + notifications.**
**Grounding:** [`docs/research/2026-06-09-core-sdk-inspection.md`](../../research/2026-06-09-core-sdk-inspection.md) (core SDK + Sage scaffold + best practice) and [`docs/research/2026-06-09-bridge-expansion-roadmap.md`](../../research/2026-06-09-bridge-expansion-roadmap.md) (expansion roadmap).

> **Reading order:** Parts 1–13 below define the **foundation** (token store, provider, facade, config, commands, tooling) — all still accurate. **Part B** (after Part 13) layers the approved platform features on top and notes where it revises the foundation (the facade now resolves a `FleetManager`; composer gains deps; more config/commands/migrations/tests).

---

## 1. Goal

Ship the Laravel bridge for the finished core SDK [`chrisjohnleah/velocity-fleet-api`](https://github.com/chrisjohnleah/velocity-fleet-api) (v0.1.0, Saloon v4) so a Laravel app can talk to the **Radius Velocity Fleet** telematics API with zero wiring. It is the second of a two-package Packagist pair, mirroring the existing Sage pair (`sage-business-cloud-accounting-api` + `…-laravel`).

The bridge **wraps** the core SDK — it never re-implements HTTP, auth, refresh, or DTOs. Its entire job is: a service provider, a facade, an Eloquent-backed `TokenStore`, config, a migration, and artisan commands.

**Non-goals (v0.1):** multi-tenant / multi-connection token storage; any change to the core SDK; a UI; OAuth authorize-code redirect (Velocity has none).

## 2. What the bridge wraps (core contract — verbatim)

Namespace `ChrisJohnLeah\VelocityFleet`. Full detail in the research doc; the load-bearing facts:

- **`VelocityFleet`** (final) — the client. Instance methods the bridge exposes: `customers(): CustomersResource`, `devicePositions(): DevicePositionsResource`, `send(Request): Response`, `refresh(StoredToken): StoredToken`, `connector(): VelocityFleetConnector`. Named constructors `withToken()` / `withRefreshToken()` are for standalone use — the bridge builds the client via the container instead.
- **`Contracts\TokenStore`** — `get(): ?StoredToken`, `put(StoredToken): void`, `forget(): void`. Docblock mandates: **`put()` MUST overwrite** (the OAuth2 endpoint rotates the refresh token).
- **`Auth\StoredToken`** (final readonly) — **exactly three** fields: `accessToken: string`, `refreshToken: ?string`, `expiresAt: ?DateTimeImmutable`. **No `businessId`** (that is Sage-only — the single most load-bearing divergence from the Sage scaffold).
- **`VelocityFleetConnector`** — config + retry only; does **not** authenticate. Ctor `(string $baseUrl = DEFAULT_BASE_URL, ?string $tokenEndpoint = DEFAULT_TOKEN_ENDPOINT, ?string $clientId = null, ?string $clientSecret = null)`. Constants: `DEFAULT_BASE_URL = 'https://www.velocityfleet.com'`, `DEFAULT_TOKEN_ENDPOINT = 'https://www.velocityfleet.com/o/token/'`. The **client** applies `TokenAuthenticator($accessToken)` (Bearer) on every `send()`.
- **Auth model** — OAuth2 **refresh-token grant** (NOT a static API key). Two flows: a UI-minted static access token (no refresh), or a customer-supplied refresh token exchanged at the token endpoint (form-encoded `grant_type=refresh_token` + `refresh_token` + conditional `client_id`/`client_secret`), with proactive refresh (60s buffer) + one reactive 401 retry, and refresh-token rotation (falls back to the old refresh token if the endpoint returns none).
- **Exceptions** — `VelocityFleetException` (abstract base) ⊃ `ApiException` ⊃ `AuthenticationException` (401/403); `NotConnectedException` extends the base directly (thrown when no token/endpoint).
- **Resources** — `CustomersResource::list(): list<Customer>`; `DevicePositionsResource::forCustomer(string $customerId): DevicePositions` and `::devices(string $customerId): list<Device>`. The customer id is `Customer::$id` (the map key), **not** `Customer::$number`.

## 3. Architecture & file layout

PSR-4 root `ChrisJohnLeah\VelocityFleet\Laravel\` → `src/`. Structure mirrors the Sage bridge minus the OAuth/Blade pieces.

```
src/
  VelocityFleetServiceProvider.php      register bindings + config; boot migrations, commands, publishes
  Facades/VelocityFleet.php             facade → the container-bound VelocityFleet client (@mixin)
  EloquentTokenStore.php                implements core TokenStore; single row; put() overwrites; 3 fields only
  Models/VelocityFleetToken.php         Eloquent model; table from config; casts expires_at → datetime
  Commands/ConnectCommand.php           velocity-fleet:connect — seed a token (static or refresh) into the store
  Commands/StatusCommand.php            velocity-fleet:status — report stored token / mode / expiry
  Commands/CustomersCommand.php         velocity-fleet:customers — live connectivity smoke-test + list
config/velocity-fleet.php               base_url, token_endpoint, client_id/secret, access_token, refresh_token, table, refresh_buffer_seconds
database/migrations/0001_01_01_000000_create_velocity_fleet_tokens_table.php
tests/
  TestCase.php                          orchestra/testbench base; sqlite :memory:; loads migrations
  Pest.php                              uses(TestCase::class)->in('Feature')
  Feature/BridgeTest.php                container resolution + token-store round-trip/overwrite/forget
  Feature/CommandsTest.php              connect/status/customers behaviour
composer.json  README.md  CHANGELOG.md  CONTRIBUTING.md  SECURITY.md  CODE_OF_CONDUCT.md
phpstan.neon  pint.json  phpunit.xml  .gitattributes  .gitignore  LICENSE  .github/workflows/ci.yml
```

No `resources/views`, no Blade component, no `redirect_uri`/`scopes`/`authorize_endpoint`/`country`, no `business_id` anywhere.

## 4. Configuration (`config/velocity-fleet.php`)

```php
return [
    // Static flow: a UI-generated API token (Account Settings > API Integrations). No refresh.
    'access_token'  => env('VELOCITY_FLEET_ACCESS_TOKEN'),

    // Refresh flow: a customer-supplied refresh token + optional OAuth client credentials.
    'refresh_token' => env('VELOCITY_FLEET_REFRESH_TOKEN'),
    'client_id'     => env('VELOCITY_FLEET_CLIENT_ID'),
    'client_secret' => env('VELOCITY_FLEET_CLIENT_SECRET'),

    // Endpoints — defaults target Radius Velocity Fleet production.
    'base_url'       => env('VELOCITY_FLEET_BASE_URL', 'https://www.velocityfleet.com'),
    'token_endpoint' => env('VELOCITY_FLEET_TOKEN_ENDPOINT', 'https://www.velocityfleet.com/o/token/'),

    // Eloquent token-store table.
    'table' => env('VELOCITY_FLEET_TOKEN_TABLE', 'velocity_fleet_tokens'),

    // Refresh the access token this many seconds before it expires.
    'refresh_buffer_seconds' => 60,
];
```

**Auth mode is decided by what is seeded, not by a flag:** a row with a `refresh_token` → refresh flow; a row with only an `access_token` → static flow (the core never refreshes a token that has no refresh token / no expiry).

## 5. Token persistence & seeding

### 5.1 `EloquentTokenStore` (single row, three fields)

Implements the core `TokenStore`. `get()` hydrates a `StoredToken` from the latest row (or null); `put()` overwrites the existing row else creates the first (guaranteeing exactly one row across refreshes — rotation safety); `forget()` deletes all rows. Maps **exactly** `access_token`, `refresh_token`, `expires_at`. **No `business_id`.**

### 5.2 Model & migration

`VelocityFleetToken extends Model`, `$guarded = []`, `getTable()` from `config('velocity-fleet.table')`, `casts()` → `['expires_at' => 'datetime']`. Migration is an anonymous class with a private `table()` helper mirroring the model; columns: `id`, `text('access_token')`, `text('refresh_token')->nullable()`, `timestamp('expires_at')->nullable()`, `timestamps()`.

### 5.3 How the first token enters the store — **zero-wiring auto-seed + explicit command**

The core's `withRefreshToken()` seeds an in-memory store; the bridge uses the Eloquent store, so the initial token must be seeded into the DB. Two complementary paths:

1. **Auto-seed from config (zero-wiring DX).** When the `VelocityFleet` client is resolved from the container, if the store is **empty** and config provides an `access_token` or `refresh_token`, the provider seeds it:
   - `refresh_token` set → `put(new StoredToken('', $refreshToken, new DateTimeImmutable('-1 second')))` (expired seed forces the first call to exchange — same trick as `withRefreshToken`).
   - else `access_token` set → `put(new StoredToken($accessToken))`.
   - The seed is **guarded** (try/catch) so resolving the client never fatals if the table is not migrated yet, and only runs when the store is empty.
2. **`velocity-fleet:connect` command** for explicit control / token rotation without env.

**Precedence rule (rotation safety):** a stored row **always wins** — auto-seed only fires when the store is empty, so a redeploy never clobbers a rotated refresh token.

## 6. Service provider & binding lifetimes

`register()`:
- `mergeConfigFrom(config/velocity-fleet.php, 'velocity-fleet')`.
- `singleton(TokenStore::class, EloquentTokenStore::class)` — stateless, safe as a singleton.
- `scoped(VelocityFleetConnector::class, …)` — built from config (`base_url`, `token_endpoint`, `client_id`, `client_secret`).
- `scoped(VelocityFleet::class, …)` — `new VelocityFleet($connector, $tokenStore, $refreshBufferSeconds)`; runs the §5.3 auto-seed before returning.
- Typed config helpers `stringConfig()` / `intConfig()` (private) to satisfy larastan `max` (`config()` returns `mixed`).

`boot()`:
- `loadMigrationsFrom(database/migrations)` (auto-registered).
- inside `runningInConsole()`: register the three commands; `publishes([... config ...], 'velocity-fleet-config')` and `publishes([... migrations ...], 'velocity-fleet-migrations')`.

**Binding-lifetime decision (deliberate, minor divergence from Sage):** the connector and client are bound **`scoped`** (fresh per request/job, singleton within a lifecycle), not `singleton`. Rationale: the connector carries the mutable `TokenAuthenticator`; `scoped` is the Laravel-recommended, Octane-safe lifetime for stateful services and behaves identically to a singleton in a normal request cycle, so it costs nothing while being correct under Octane. The `TokenStore` (stateless) stays a singleton. This is the only intentional structural change from the Sage scaffold; flip both to `singleton` for exact parity if preferred. We do **not** add `saloonphp/laravel-plugin` (shared-sender singleton) in v0.1 — documented as a future optimisation.

## 7. Facade

`Facades/VelocityFleet` extends `Illuminate\Support\Facades\Facade`; `getFacadeAccessor()` returns `ChrisJohnLeah\VelocityFleet\VelocityFleet::class`. Docblock uses **`@mixin \ChrisJohnLeah\VelocityFleet\VelocityFleet`** (best practice — IDE inherits every public method, no drifting `@method` lines). Usage: `VelocityFleet::customers()->list()`, `VelocityFleet::devicePositions()->forCustomer($id)`.

Auto-discovery via `extra.laravel`: provider `…\Laravel\VelocityFleetServiceProvider`, alias `VelocityFleet` → `…\Laravel\Facades\VelocityFleet`.

## 8. Artisan commands

All extend `Illuminate\Console\Command`, method-inject dependencies, return `self::SUCCESS`/`self::FAILURE`.

- **`velocity-fleet:connect`** — `{--token=} {--refresh-token=}`. With `--refresh-token`, persists an expired seed (forces exchange on first use). With `--token`, persists a static token. With neither, seeds from config. Reports what it stored; fails with guidance if nothing is available.
- **`velocity-fleet:status`** — inject `TokenStore`. If empty: warn + `FAILURE`. Else print a table: **Mode** (refresh / static), **Access token** (`substr(…,0,6).'…'`), **Refresh token** (present/missing), **Expires at**, **Expired** (will-refresh hint). No `Business` row.
- **`velocity-fleet:customers`** — inject `VelocityFleet`. Live connectivity smoke-test: calls `customers()->list()` and prints id / number / name / product. Catches `NotConnectedException` (→ "run velocity-fleet:connect", `FAILURE`) and `VelocityFleetException` (→ shows the typed error, `FAILURE`). Doubles as a useful operator utility.

## 9. Testing

`orchestra/testbench` `TestCase` (`getPackageProviders` → the provider; `defineDatabaseMigrations` → `loadMigrationsFrom`; `defineEnvironment` seeds the connector config keys); sqlite `:memory:` via `phpunit.xml`. Pest `uses(TestCase::class)->in('Feature')`.

- **`BridgeTest`**: `VelocityFleet`, `VelocityFleetConnector`, and `TokenStore` resolve from the container; `TokenStore` is an `EloquentTokenStore`; the facade root is the client; the store **round-trips exactly `access_token`/`refresh_token`/`expires_at`**, keeps **one row** after two `put()`s (overwrite), and `forget()` empties it; auto-seed-from-config populates an empty store (and does **not** overwrite an existing row).
- **`CommandsTest`**: `velocity-fleet:status` fails unconnected, succeeds once a token exists; `velocity-fleet:connect --refresh-token=…` and `--token=…` persist and succeed; `velocity-fleet:customers` fails cleanly when unconnected. (Optional: a mocked happy path via Saloon's global `MockClient` for `customers`.)

No bridge test asserts core SDK HTTP behaviour — that is the core's own suite.

## 10. Tooling & packaging

- **composer.json** — `name` `chrisjohnleah/velocity-fleet-api-laravel`; `require` `php: ^8.3`, `chrisjohnleah/velocity-fleet-api: ^0.1.0`, granular `illuminate/{console,contracts,database,support}: ^11.0 || ^12.0 || ^13.0`; `require-dev` `larastan/larastan: ^3.0`, `laravel/pint: ^1.18`, `orchestra/testbench: ^9.0 || ^10.0 || ^11.0`, `pestphp/pest: ^4.0`, `pestphp/pest-plugin-laravel: ^3.0 || ^4.0`; `extra.laravel` discovery; `scripts` (`test`/`analyse`/`format`/`lint`/`check`); `config.allow-plugins` = `{ pestphp/pest-plugin: true }` only (drop the unused `phpstan/extension-installer` entry — larastan is loaded via `includes:`); `sort-packages`, `minimum-stability: stable`, `prefer-stable`. Keywords include `velocity`, `velocity-fleet`, `radius`, `telematics`, `fleet`, `kinesis`, `laravel`, `oauth2`, `bridge`.
- **Local dependency wiring** — the core SDK is tagged `v0.1.0` but **not yet on Packagist**, so the bridge adds a Composer `path` **repository** to `../velocity-fleet-api` for local dev/CI until both are published. The exact repository mechanics (path vs vcs; version resolution for the un-`version`-fielded core) will be **verified empirically by running `composer install`** during implementation, and the block removed/adjusted once the core lands on Packagist.
- **phpstan.neon** — `includes: vendor/larastan/larastan/extension.neon`; `level: max` (matches both siblings; the typed config helpers exist to satisfy it); `paths: [src]`; `tmpDir: build/phpstan`; `treatPhpDocTypesAsCertain: false` (core parity); `excludePaths` migrations only if anonymous-class rules misfire.
- **pint.json** — copy byte-for-byte from the siblings (psr12 + the same rule set).
- **phpunit.xml** — single `Feature` suite; `source` = `src`; `DB_CONNECTION=testing`, `DB_DATABASE=:memory:`.
- **CI** (`.github/workflows/ci.yml`) — `quality` job (PHP 8.4: pint `--test` + phpstan) and a `tests` matrix `php: ['8.3','8.4']` × `laravel: ['11.*','12.*','13.*']` with `include` pins `testbench 9.*/10.*/11.*`, `dependency-version: [prefer-lowest, prefer-stable]`, `fail-fast: false`; "constrain then resolve" install; `pest --ci`. CI must register the core SDK source (path/vcs repository) so resolution succeeds.
- **Meta files** — ship the identical set to the siblings (README with 5-badge row + endpoint→call table + Errors table + British spelling + a non-affiliation/trademark note for Radius/Velocity Fleet; CHANGELOG Keep-a-Changelog; CONTRIBUTING; SECURITY; CODE_OF_CONDUCT; `.gitattributes` with `export-ignore`; `.gitignore`; LICENSE/MIT already present).

## 11. Decisions resolved (from the five flagged conflicts)

| # | Conflict | Decision |
|---|---|---|
| 1 | `StoredToken` has `businessId`? | **No.** Three fields only; drop `business_id` from model/migration/store/status everywhere. |
| 2 | Connector singleton vs transient | **`scoped`** connector + client, **singleton** token store (§6). |
| 3 | phpstan `level: max` vs 5 | **`level: max`** (sibling parity). Level-5+baseline only as fallback. |
| 4 | core constraint `^0.1` vs `^0.1.0` | **`^0.1.0`** (locks the minor; same resolved range). |
| 5 | `allow-plugins`/extension-installer | **Drop** the `phpstan/extension-installer` allow-plugin entry; load larastan via `includes:`. Keep only `pestphp/pest-plugin`. |

## 12. Risks / to verify during implementation

- **Composer resolution of the unpublished core** — confirm whether `../velocity-fleet-api` is reachable as a `path` repo and whether its tag/`version` resolves `^0.1.0`; otherwise fall back to a `vcs` repo or `dev-main`. Verified by running `composer install`.
- **Auto-seed DB timing** — the guarded seed must not fatal pre-migration; covered by try/catch + empty-store check, asserted in tests.
- **`customers` command faking** — happy-path test needs Saloon's global `MockClient`; if brittle, keep only the unconnected-failure assertion.
- **Single-row store ceiling** — acceptable for v0.1 (matches the core contract); multi-fleet/multi-tenant is a future keyed-store extension, not built now.
- **Live device-positions correctness** — examples must use `Customer::$id` (map key), not `$number`.

## 13. Out of scope (even after Part B)

Keyed multi-connection / multi-tenant token store; HTTP routes / policies / signed share-links; Livewire/Blade map UI; exporters (CSV/GeoJSON/GPX) & journey replay; trip detection & mileage rollups; `velocity-fleet:make-*` scaffolders; precise `Retry-After` (core-gated — needs response headers); any endpoint beyond Customers + Device Positions (core SDK gates that surface). These are the v0.2/v0.3+/blocked tiers in the expansion roadmap.

---

# Part B — Expanded scope: event-driven fleet platform (approved 2026-06-09)

The base bridge (Parts 1–13) wraps the core SDK. Part B builds the **owner-grade platform** on top: it synthesises the push layer the poll-only API lacks (events), a polite caching/poll spine (the API hands us 30s/90s hints), an opt-in GDPR-defensible history, security hardening, a testing toolkit, and the two highest-value operational features — **geofences** and **notifications**. Everything here is **bridge-only** (no core SDK change). Source of truth for per-item value/effort: the expansion roadmap.

## B.0 Shape change: the facade resolves a `FleetManager`

The core `VelocityFleet` client is `final` (not `Macroable`), so bridge sugar (`fleet()`, `cached()`, `fake()`) cannot be added to it. **Introduce `ChrisJohnLeah\VelocityFleet\Laravel\FleetManager`** — the primary bound service and the facade target. It wraps the core client and:
- delegates `customers()`, `devicePositions()`, `send()`, `refresh()`, `connector()` to the core `VelocityFleet` (explicit typed methods; `@mixin` the core client for IDE),
- adds `fleet(string $customerId): FleetQuery`, `cached(): CachedPositions`, and the static test entrypoint `fake()`.

Container: `scoped(VelocityFleet::class, …)` (core client, as Part 6) **and** `scoped(FleetManager::class, fn($app) => new FleetManager($app->make(VelocityFleet::class), …))`. Facade `getFacadeAccessor()` → `FleetManager::class`. The §11 auto-seed moves into the `FleetManager`/client resolution path unchanged.

## B.1 Query sugar — `DeviceCollection` + `fleet($id)`  *(roadmap #1, S–M)*

- `Support\DeviceCollection extends Illuminate\Support\Collection` (+ `Macroable` for host scopes). Telematics scopes over existing `Device` fields: `online()/offline()` (by `signalStrengthColor`/`occurredAt` freshness), `moving()/idling()` (`speed`, `ignitionOn()`), `ignitionOn()/ignitionOff()`, `inDeviceGroup(int)/inDriverGroup(int)` (`$deviceGroups`/`$driverGroups`), `near(float $lat, float $lon, float $km)`, `byRegistration(string)`, `withDriver(string)`. Returns `DeviceCollection` for chaining; `->keyByDeviceId()` helper.
- `Support\Haversine` — pure static distance (km/mi) helper, reused by cache/geofence/geospatial.
- `Support\FleetQuery` — wraps `VelocityFleet::devicePositions()->devices($customerId)` (or the cached snapshot) and returns a `DeviceCollection`; `positions(): DevicePositions` for the full payload.
- `FleetManager::fleet($customerId): FleetQuery`. Example: `VelocityFleet::fleet($id)->moving()->inDriverGroup(7)->near($lat,$lon,5)`.

## B.2 Refresh-rate-aware SWR cache + single-flight  *(roadmap #2, M)*

- `Support\RefreshRateResolver` — given a `DevicePositions` (or `deviceCount`), returns the poll/TTL window: `liveMapRefreshRate` (~30s) for normal fleets, `liveMapLargeFleetRefreshRate` (~90s) past a size threshold, **clamped by `config('velocity-fleet.cache.min_ttl')`** so config can never poll faster than the provider suggests.
- `Contracts\PositionsCache` + default store via Laravel `Cache`; `ValueObjects\PositionsSnapshot { DevicePositions $positions; CarbonImmutable $fetchedAt; int $ttl }`.
- `Cache\CachedPositions` — decorator over the manager: `forCustomer($id)` returns a fresh-or-stale snapshot; on miss/expiry acquires a **`Cache::lock` single-flight** so concurrent callers/workers collapse to **one upstream POST per window** (stale-while-revalidate: serve stale immediately, refresh in the lock holder). `FleetManager::cached()` exposes it.
- Config `velocity-fleet.cache` = `{ enabled, store (null=default), min_ttl, prefix, lock_seconds }`. **Octane note:** single-flight requires a shared atomic-lock store (redis/database/memcached); document and warn if `array`/`file`.

## B.3 Poller + change-detection events  *(roadmap #3, M — the heart)*

- `Contracts\FleetSnapshotStore` (`previous(customerId): ?PositionsSnapshot`, `put(customerId, PositionsSnapshot)`) with `Snapshot\CacheSnapshotStore` (default) and `Snapshot\EloquentSnapshotStore` (when history on).
- `Polling\FleetPoller` — `poll(string $customerId)`: fetch (through the cache), diff each current `Device` against the previous snapshot by `id`/`serviceId`, fire events, persist the new snapshot. Diff rules: ignition `ignitionOn()` false→true / true→false; speed 0↔>0; freshness (`occurredAt()`/`timestamp` older than `config('stale_after')`) → stale, and stale→fresh → back-online; presence (device id appears/disappears).
- `Jobs\PollFleetJob implements ShouldQueue` (`WithoutOverlapping($customerId)`), invokes `FleetPoller::poll`.
- `Events\` (each carries the `Device` + customerId + timestamps): `IgnitionTurnedOn`, `IgnitionTurnedOff`, `VehicleStartedMoving`, `VehicleStopped`, `DeviceWentStale`, `DeviceCameBackOnline`, plus the umbrella `DevicePositionsUpdated` (carries the whole `DevicePositions` + the diff). First poll (no previous snapshot) seeds state silently (no spurious events).

## B.4 Opt-in encrypted position history  *(roadmap #4, M)*

- `Models\DevicePositionRecord` (table `velocity_fleet_device_positions`); migration columns: `customer_id`, `device_id`, `service_id`, `recorded_at` (from `timestamp`), `lat`, `lon`, `speed`, `direction`, `ignition`, **encrypted** `driver_name`/`vehicle_registration`/`mobile_phone` (Laravel `encrypted` casts), `signal_strength_color`, `raw` (encrypted JSON, optional), timestamps. **Unique index `(customer_id, device_id, recorded_at)`** for idempotent upsert.
- `Listeners\PersistDevicePositions` on `DevicePositionsUpdated` → `Jobs\IngestPositionsJob` → `upsert(...)` (idempotent; safe under at-least-once queues). Honour `Device::$private` as the default suppression signal.
- Config `velocity-fleet.history` = `{ enabled: false, connection, store_raw: false, encrypt_pii: true }`. **Off by default.** v0.1 ships the table + ingest only; trips/mileage are deferred (v0.2).

## B.5 Config-driven scheduled polling  *(roadmap #5, M, default OFF)*

- Provider `boot()` registers `velocity-fleet:poll` (or `PollFleetJob` per customer) on the scheduler **only when `config('velocity-fleet.polling.enabled')`** (default false), cadence from `RefreshRateResolver`, `withoutOverlapping()->onOneServer()`.
- `Commands\PollCommand` (`velocity-fleet:poll {customer?}`) — lists customers (or one) and dispatches `PollFleetJob` each. Config `polling` = `{ enabled, customers: ['*'|ids], queue, cadence_override }`.

## B.6 Retention pruning  *(roadmap #6, S)*

- `Jobs\PrunePositions` + `Commands\PrunePositionsCommand` (`velocity-fleet:prune-positions`): delete `DevicePositionRecord` (and later trips/events) older than `config('velocity-fleet.retention.positions_days')` (default **90**); fire `Events\PositionHistoryPruned(count)`. Self-scheduled when history+polling on.

## B.7 Encrypt token columns at rest  *(roadmap #7, S — revises Part 5)*

- `Models\VelocityFleetToken`: cast `access_token`/`refresh_token` as **`encrypted`** when `config('velocity-fleet.encrypt_tokens')` (default **true**); migration column type `text` (ciphertext is longer). Provider **APP_KEY guard**: if `encrypt_tokens` on and no `APP_KEY`, throw a loud, actionable exception.
- `Commands\EncryptTokensCommand` — idempotent migrate of any plaintext rows to ciphertext (detects already-encrypted). The `EloquentTokenStore` is unchanged (casts are transparent).

## B.8 Secret redaction  *(roadmap #8, S)*

- `Support\RedactsSecrets` / `Support\VelocityFleetLogSanitizer`: truncate the refresh token in `:status`, mask `Authorization` headers + the refresh form-body in any HTTP logging middleware, scrub token-shaped substrings from `ApiException::$body` before it reaches logs/context. HTTP debug logging is **opt-in** via `config('velocity-fleet.log_channel')` (default `null` = silent). **Regression test** asserts no token material appears in command/log output.

## B.9 Structured logging + metrics  *(roadmap #9, S)*

- `Events\` `PositionsFetched`, `TokenRefreshed`, `PollSkipped(reason)`, `RequestFailed`; `Listeners\LogVelocityFleetActivity` (PII-redacting; logs counts/customer-id, never device-level fields) to `config('velocity-fleet.logging.channel')`; `Contracts\MetricsRecorder` + `Metrics\NullMetricsRecorder` (default, bound in container) so hosts can plug Prometheus/StatsD. Four counters: positions fetched, refresh count, polls skipped, errors.

## B.10 `velocity-fleet:doctor` self-check  *(roadmap #10, S)*

- `Commands\DoctorCommand` — CI-runnable checks (never prints secrets): APP_KEY present when `encrypt_tokens` on; token columns are actually ciphertext; `retention.positions_days` set when history on; warns about the **stale-env-secret gotcha** (stored row wins over a rotated `.env` value); cache/lock store is shared if polling/cache on. Exit non-zero on any failure.

## B.11 Testing toolkit  *(roadmap #11, M)*

- `Testing\InteractsWithVelocityFleet` trait + `FleetManager::fake()` built on **Saloon core's framework-agnostic `MockClient`** (already available via the core SDK's `saloonphp/saloon: ^4.0` — **no extra dependency**, and **we deliberately do NOT add `saloonphp/laravel-plugin`**: it would force every consumer to install Saloon's own Laravel integration just to use a telematics bridge). `fake()` registers a `MockClient` (global or on the resolved connector) so the core SDK's requests are intercepted; because Saloon honours a globally-registered mock, a consumer already using the plugin's `Saloon::fake()` also fakes our SDK automatically — compatible without coupling. Also: `Testing\FakeDevice::make([...])`, `Testing\FakeDevicePositions::withDevices([...])` (bakes the awkward UPPERCASE `KINESIS_LIVE_MAP_*` keys), `Testing\FleetScenario` (stage a sequence of snapshots to drive transitions); assertions `assertPolled($customerId)`, `assertEventDispatchedForDevice($event, $deviceId)`. Used by the bridge's own suite and exported for host apps.

## B.12 Geofences  *(roadmap v0.2, L — owner feature)*

- `Models\Geofence` (table `velocity_fleet_geofences`): `name`, `type` (`circle`|`polygon`), `center_lat`/`center_lon`/`radius_m` (circle) or `points` JSON (polygon), nullable `customer_id`/`device_group_id`, `active`.
- `Models\GeofenceEvent` (table `velocity_fleet_geofence_events`): durable per-device in/out state + transitions (`device_id`, `geofence_id`, `entered_at`, `exited_at`, `dwell_seconds`).
- `Geofencing\GeofenceMatcher` — runs each poll tick (listener on `DevicePositionsUpdated`): circle via `Haversine`, polygon via ray-casting (no PostGIS). Compares against durable state → fires `Events\VehicleEnteredGeofence`, `VehicleExitedGeofence`, `VehicleDwelledInGeofence`, and the semantic `VehicleArrived` (enter a depot/site fence). Config `geofencing` = `{ enabled: false, dwell_minutes }`. **Caveat to document:** poll cadence vs small fences can miss fast transits.

## B.13 Notifications + alerts  *(roadmap v0.2, M — owner feature)*

- `Notifications\` `VehicleArrivedNotification`, `DeviceOfflineNotification`, `GeofenceBreachedNotification`, `SpeedingNotification`, `IdlingTooLongNotification` — each `toMail/toSlack/toDatabase/toBroadcast` (channels gated by config; degrade gracefully if a channel package is absent).
- `Alerts\AlertRule` + `Listeners\SendFleetAlerts` map the B.3/B.12 events → notifications, with **cache-based throttling/dedupe** (no alert storms) and **recipient routing per driver-group** via `config('velocity-fleet.alerts')`. Speeding/idling use lightweight, clearly-labelled **heuristics** (speed > configured limit; ignition-on + speed 0 across N snapshots) — not the full v0.2 `BehaviourAnalyzer`/incidents table (deferred). Config `notifications` = `{ enabled: false, channels, routes }`, `alerts` = `{ speeding_mph, idling_minutes, offline_minutes, throttle_minutes }`.

## B.14 Consolidated config (`config/velocity-fleet.php` outline)

Part 4 keys plus: `encrypt_tokens` (true), `log_channel` (null), `cache {…}`, `polling {…}` (off), `history {…}` (off), `retention {…}`, `geofencing {…}` (off), `notifications {…}` (off), `alerts {…}`, `logging {…}`. **Every new subsystem defaults OFF/safe** — installing the package changes nothing until opted in.

## B.15 Composer deltas

Add to `require` (each `^11.0 || ^12.0 || ^13.0`): `illuminate/bus`, `illuminate/queue`, `illuminate/cache`, `illuminate/events`, `illuminate/encryption`, `illuminate/notifications` (alongside Part 10's console/contracts/database/support). Add `nesbot/carbon` only if not transitively guaranteed (it is via illuminate/support — skip). **No new `require-dev` for faking** — `FleetManager::fake()` uses Saloon core's `MockClient` (already present via the core SDK); **`saloonphp/laravel-plugin` is deliberately NOT required** (runtime or dev) so the bridge never imposes Saloon's own Laravel integration on consumers (B.11). Final dep list confirmed during build by scanning actual `use` statements. `spatie/laravel-health` is **not** added (its checks are v0.2).

## B.16 New artisan commands / events / migrations (summary)

- **Commands:** `:connect`, `:status`, `:customers` (Part 8) + `:poll`, `:prune-positions`, `:encrypt-tokens`, `:doctor`.
- **Events:** ignition/movement/staleness/positions-updated (B.3) + geofence enter/exit/dwell/arrived (B.12) + observability (B.9) + `PositionHistoryPruned` (B.6).
- **Migrations:** `velocity_fleet_tokens` (Part 5) + `velocity_fleet_device_positions` + `velocity_fleet_geofences` + `velocity_fleet_geofence_events`. All gated/auto-loaded; publishable under `velocity-fleet-migrations`.

## B.17 Testing scope (additions)

Beyond Part 9: `DeviceCollection` scopes; `RefreshRateResolver` TTL selection + clamp; `CachedPositions` single-flight (one upstream call under concurrency via fake); `FleetPoller` fires the right events on staged transitions (`FleetScenario`); idempotent history upsert (double-ingest → one row); token columns encrypted at rest; redaction (no secret leaks); `:doctor` pass/fail; geofence enter/exit on a moving fake device; one notification dispatched + throttled. All use `FleetManager::fake()` — **no live PII feed in tests.**

## B.18 Build phasing (dependency order — feeds the implementation plan)

1. **Foundation** (Parts 1–11): composer + repo wiring, token store, model, migration, provider, facade→`FleetManager`, config skeleton, base commands, testbench. *(Gate: `composer install` + green base tests.)*
2. **Security-by-default** (B.7, B.8): encrypted tokens + APP_KEY guard + redaction — *before any data lands.*
3. **Query + cache spine** (B.1, B.2): `DeviceCollection`/`fleet()`, `RefreshRateResolver`, `CachedPositions`.
4. **Event spine** (B.3) + **testing toolkit** (B.11, built alongside so transitions are testable).
5. **History + lifecycle** (B.4, B.6, B.5): table/ingest, pruning, scheduled polling.
6. **Observability** (B.9, B.10): logging/metrics events + `:doctor`.
7. **Owner features** (B.12 geofences, B.13 notifications).
8. **Docs + CI + adversarial review**: README (full feature docs), CHANGELOG, CI matrix green, then a multi-agent review pass.

## B.19 Risks specific to Part B

- **Octane/multi-worker:** locks + SWR + `onOneServer` need a shared store — `:doctor` warns; default-safe (degrades to per-worker polling, never breaks).
- **GDPR:** history off by default, PII encrypted, retention enforced; document DPIA/worker-monitoring in SECURITY.md. Subject-access/erasure tooling is v0.2 (note prominently).
- **Heuristic alerts:** speeding/harsh/idling from poll cadence are approximate — label as heuristics in docs and notification copy.
- **Geofence miss risk:** cadence vs fence size — documented; recommend fences sized to the 30s/90s window.
- **Scope/quality:** large surface — the build is phased (B.18) and each phase is independently testable; the final adversarial review pass guards correctness.

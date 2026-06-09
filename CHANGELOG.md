# Changelog

All notable changes to this project are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.2.0] - 2026-06-09

Part B — the event-driven fleet platform on top of the bridge. **Every new subsystem defaults OFF/safe**; installing the upgrade changes nothing until you opt in.

### Added
- **Fleet queries** — `FleetManager` is now the facade target (wrapping the core client). `VelocityFleet::fleet($customerId)` returns a chainable `DeviceCollection` with telematics scopes (`moving()`, `idling()`, `ignitionOn/Off()`, `online/offline()`, `inDeviceGroup()`, `inDriverGroup()`, `near()`, `byRegistration()`, `withDriver()`). `Support\Haversine` distance helper.
- **Refresh-rate-aware cache** — `VelocityFleet::cached()` serves positions with stale-while-revalidate + `Cache::lock` single-flight, so concurrent callers collapse to one upstream POST per window. TTL derived from the API's live-map hints, clamped by `cache.min_ttl`.
- **Change-detection events** — `FleetPoller` diffs each poll against the previous snapshot and fires `IgnitionTurnedOn/Off`, `VehicleStartedMoving`, `VehicleStopped`, `DeviceWentStale`, `DeviceCameBackOnline`, plus the umbrella `DevicePositionsUpdated`. `PollFleetJob` (queued, `WithoutOverlapping`).
- **Opt-in encrypted position history** — `DevicePositionRecord` + migration; PII columns encrypted at rest; idempotent upsert; `IngestPositionsJob`; honours `Device::$private`.
- **Scheduled polling + retention** — `velocity-fleet:poll`, `velocity-fleet:prune-positions`; auto-scheduled when enabled.
- **Security-by-default** — token columns encrypted at rest (APP_KEY guard), `velocity-fleet:encrypt-tokens`, and secret redaction (`VelocityFleetLogSanitizer`).
- **Observability** — structured activity logging, a pluggable `MetricsRecorder` (no-op by default), and a `velocity-fleet:doctor` self-check.
- **Geofences** — circle + polygon (ray-casting) matching with durable enter/exit/dwell state; `VehicleEnteredGeofence`, `VehicleExitedGeofence`, `VehicleDwelledInGeofence`, `VehicleArrived`.
- **Notifications + alerts** — arrival, offline, geofence-breach, and heuristic speeding/idling notifications with cache-based throttling and per-route recipients.
- **Testing toolkit** — `FakeDevice`, `FakeDevicePositions`, `FleetScenario`, and the `InteractsWithVelocityFleet` trait for host-app tests.

### Changed
- The `VelocityFleet` facade now resolves a `FleetManager` (which delegates to and `@mixin`s the core client) — existing `customers()` / `devicePositions()` calls are unaffected.

## [0.1.0] - 2026-06-09

### Added
- Initial Laravel bridge for the Radius Velocity Fleet API SDK (core `^0.1.0`).
- `VelocityFleetServiceProvider`: binds the `VelocityFleet` client, `VelocityFleetConnector`, and `TokenStore` from config; auto-discovered; loads the migration; publishes config/migrations; registers the artisan commands.
- `VelocityFleet` facade (`@mixin` the core client).
- `EloquentTokenStore` + `VelocityFleetToken` model: single-row, overwrite-on-`put()` token persistence (rotation-safe).
- Zero-wiring auto-seed of a token from config (refresh token → expired seed; static access token → stored as-is; a stored token always wins).
- Artisan commands: `velocity-fleet:connect`, `velocity-fleet:status`, `velocity-fleet:customers`.
- Config file with `access_token`, `refresh_token`, `client_id`/`client_secret`, `base_url`, `token_endpoint`, `table`, and `refresh_buffer_seconds`.

[Unreleased]: https://github.com/chrisjohnleah/velocity-fleet-api-laravel/compare/v0.2.0...main
[0.2.0]: https://github.com/chrisjohnleah/velocity-fleet-api-laravel/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/chrisjohnleah/velocity-fleet-api-laravel/releases/tag/v0.1.0

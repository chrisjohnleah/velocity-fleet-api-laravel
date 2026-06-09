# Changelog

All notable changes to this project are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-06-09

### Added
- Initial Laravel bridge for the Radius Velocity Fleet API SDK (core `^0.1.0`).
- `VelocityFleetServiceProvider`: binds the `VelocityFleet` client, `VelocityFleetConnector`, and `TokenStore` from config; auto-discovered; loads the migration; publishes config/migrations; registers the artisan commands.
- `VelocityFleet` facade (`@mixin` the core client).
- `EloquentTokenStore` + `VelocityFleetToken` model: single-row, overwrite-on-`put()` token persistence (rotation-safe).
- Zero-wiring auto-seed of a token from config (refresh token → expired seed; static access token → stored as-is; a stored token always wins).
- Artisan commands: `velocity-fleet:connect`, `velocity-fleet:status`, `velocity-fleet:customers`.
- Config file with `access_token`, `refresh_token`, `client_id`/`client_secret`, `base_url`, `token_endpoint`, `table`, and `refresh_buffer_seconds`.

[Unreleased]: https://github.com/chrisjohnleah/velocity-fleet-api-laravel/compare/v0.1.0...main
[0.1.0]: https://github.com/chrisjohnleah/velocity-fleet-api-laravel/releases/tag/v0.1.0

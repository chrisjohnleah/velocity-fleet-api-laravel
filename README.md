# Velocity Fleet API — Laravel Bridge

[![CI](https://github.com/chrisjohnleah/velocity-fleet-api-laravel/actions/workflows/ci.yml/badge.svg)](https://github.com/chrisjohnleah/velocity-fleet-api-laravel/actions/workflows/ci.yml)
[![Packagist Version](https://img.shields.io/packagist/v/chrisjohnleah/velocity-fleet-api-laravel.svg)](https://packagist.org/packages/chrisjohnleah/velocity-fleet-api-laravel)
[![Total Downloads](https://img.shields.io/packagist/dt/chrisjohnleah/velocity-fleet-api-laravel.svg)](https://packagist.org/packages/chrisjohnleah/velocity-fleet-api-laravel)
[![PHP Version](https://img.shields.io/packagist/php-v/chrisjohnleah/velocity-fleet-api-laravel.svg)](https://packagist.org/packages/chrisjohnleah/velocity-fleet-api-laravel)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

The Laravel bridge for [`chrisjohnleah/velocity-fleet-api`](https://github.com/chrisjohnleah/velocity-fleet-api) — the framework-agnostic [Radius Velocity Fleet](https://www.velocityfleet.com) telematics SDK. Adds a service provider, a facade, a persistent Eloquent token store, and artisan commands so a Laravel app talks to the API with zero wiring.

> This package only **wires the SDK into Laravel** — auth, refresh, HTTP, and the typed DTOs all live in the [core SDK](https://github.com/chrisjohnleah/velocity-fleet-api).

## Requirements

- PHP 8.3+
- Laravel 11, 12, or 13

## Installation

```bash
composer require chrisjohnleah/velocity-fleet-api-laravel
```

The service provider and `VelocityFleet` facade are auto-discovered. Publish the config and run the migration that backs the token store:

```bash
php artisan vendor:publish --tag=velocity-fleet-config
php artisan migrate
```

## Configuration

Set the credentials in your `.env`. There are two ways to authenticate (you only need one):

```dotenv
# Existing customers — an API token generated in the Velocity UI
# (Account Settings > API Integrations > Create API Token):
VELOCITY_FLEET_ACCESS_TOKEN=your-api-token

# OR, third-party integrations — a customer-supplied refresh token
# (plus client credentials if your OAuth client requires them):
VELOCITY_FLEET_REFRESH_TOKEN=customer-refresh-token
VELOCITY_FLEET_CLIENT_ID=
VELOCITY_FLEET_CLIENT_SECRET=
```

Whatever you set is **auto-seeded** into the token store on first use. The mode is decided by what you provide: a refresh token uses the OAuth2 refresh flow (with proactive refresh and reactive retry on a 401); a bare access token is used as a static Bearer token. A stored token always wins over config, so a redeploy never clobbers a rotated refresh token.

## Usage

Via the facade:

```php
use ChrisJohnLeah\VelocityFleet\Laravel\Facades\VelocityFleet;

foreach (VelocityFleet::customers()->list() as $customer) {
    // Use $customer->id (the unique id) for device positions — not $customer->number.
    $positions = VelocityFleet::devicePositions()->forCustomer($customer->id);

    foreach ($positions->devices as $device) {
        info("{$device->vehicleRegistration} @ {$device->lat},{$device->lon} — ignition ".
            ($device->ignitionOn() ? 'on' : 'off'));
    }
}
```

Or inject the client (type-hint the core class — the container builds it for you):

```php
use ChrisJohnLeah\VelocityFleet\VelocityFleet;

public function index(VelocityFleet $velocity)
{
    return $velocity->customers()->list();
}
```

## Artisan commands

```bash
php artisan velocity-fleet:connect   # store a token: --token=… or --refresh-token=… (defaults to config)
php artisan velocity-fleet:status    # show the stored token's mode / expiry
php artisan velocity-fleet:customers # live connectivity check — list linked customers
```

## Token persistence

Tokens live in a single `velocity_fleet_tokens` row via [`EloquentTokenStore`](src/EloquentTokenStore.php) (bound to the core's `TokenStore` contract). `put()` overwrites that row, so a rotated refresh token always replaces the previous one. Change the table name with `VELOCITY_FLEET_TOKEN_TABLE`, or bind your own `TokenStore` implementation to swap the storage entirely.

## Errors

The core SDK throws typed exceptions, all extending `ChrisJohnLeah\VelocityFleet\Exceptions\VelocityFleetException`:

| Exception | When |
|---|---|
| `NotConnectedException` | No token available — run `velocity-fleet:connect` or set the env vars |
| `AuthenticationException` | `401`/`403` after a refresh attempt — re-authorise |
| `ApiException` | Any other API error (carries `->status`, `->body`, `->headers`, `header()`, `retryAfter()`) |

## Observability (optional)

Want outgoing Velocity API calls to show up in Laravel Telescope or Nightwatch? Install the official Saloon Laravel plugin — it auto-registers recording middleware on every connector, no changes here required:

```bash
composer require saloonphp/laravel-plugin
```

## Fleet platform (v0.2)

On top of the thin bridge, the package ships an opt-in fleet platform. **Every subsystem below defaults to OFF/safe** — upgrading changes nothing until you enable it in `config/velocity-fleet.php`.

### Fleet queries

`VelocityFleet::fleet($customerId)` returns a chainable `DeviceCollection`:

```php
use ChrisJohnLeah\VelocityFleet\Laravel\Facades\VelocityFleet;

$nearby = VelocityFleet::fleet($customer->id)
    ->moving()
    ->inDriverGroup(7)
    ->near($lat, $lon, 5.0);   // within 5 km
```

Scopes: `moving()`, `idling()`, `ignitionOn()`, `ignitionOff()`, `online()`, `offline()`, `inDeviceGroup()`, `inDriverGroup()`, `near()`, `byRegistration()`, `withDriver()`.

### Caching (stale-while-revalidate)

`VelocityFleet::cached()->positions($id)` serves positions from a refresh-rate-aware cache with `Cache::lock` single-flight, so concurrent callers/workers collapse to one upstream POST per window. The TTL comes from the API's own live-map hints, clamped by `cache.min_ttl`. **For multi-server / Octane, use a shared atomic-lock store (redis/database/memcached)** — `velocity-fleet:doctor` warns otherwise.

### Change-detection events

Enable `polling`, run a queue worker and the scheduler, and the poller fires events as devices change:

`IgnitionTurnedOn` · `IgnitionTurnedOff` · `VehicleStartedMoving` · `VehicleStopped` · `DeviceWentStale` · `DeviceCameBackOnline`, plus the umbrella `DevicePositionsUpdated`. Listen for them like any Laravel event.

### Geofences & notifications

Define circle or polygon `Geofence`s; with `geofencing` on, the matcher fires `VehicleEnteredGeofence` / `VehicleExitedGeofence` / `VehicleDwelledInGeofence` / `VehicleArrived`. With `notifications` on, arrival/offline/geofence/speeding/idling notifications are sent to configured routes, throttled to avoid storms. Speeding/idling are **heuristics** derived from poll cadence.

### History (opt-in, encrypted)

With `history.enabled`, each poll ingests positions into `velocity_fleet_device_positions` (PII columns encrypted at rest, idempotent upsert). `retention.positions_days` (default 90) prunes old rows. **Off by default** — see GDPR notes in [SECURITY.md](SECURITY.md).

### Commands

```bash
php artisan velocity-fleet:poll {customer?}     # dispatch a poll (also scheduled when polling on)
php artisan velocity-fleet:prune-positions      # retention prune (scheduled daily when history on)
php artisan velocity-fleet:encrypt-tokens       # migrate any plaintext token rows to ciphertext
php artisan velocity-fleet:doctor               # CI-runnable config/security self-check
```

### Testing toolkit

```php
use ChrisJohnLeah\VelocityFleet\Laravel\Testing\InteractsWithVelocityFleet;
use ChrisJohnLeah\VelocityFleet\Laravel\Testing\FakeDevice;
// fakeFleet([...]) / FakeDevice::make([...]) / FakeDevicePositions::withDevices([...]) / FleetScenario
```

## Testing

```bash
composer test      # Pest (orchestra/testbench)
composer analyse   # Larastan (max)
composer lint      # Pint --test
composer check     # all three
```

Tests run against an in-memory SQLite database and never hit the network.

## Contributing

Issues and PRs welcome — see [CONTRIBUTING.md](CONTRIBUTING.md). Please report security issues privately per [SECURITY.md](SECURITY.md).

## Licence

MIT © [Chris John Leah](https://github.com/chrisjohnleah). See [LICENSE](LICENSE).

> Not affiliated with or endorsed by Radius or Velocity Fleet. "Radius", "Velocity" and "Kinesis" are trademarks of their respective owners.

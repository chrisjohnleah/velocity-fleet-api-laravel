# Velocity Fleet Laravel Bridge — Phase 1 (Foundation) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the installable, tested foundation of the Laravel bridge — a Laravel app can `composer require` it, run `php artisan migrate`, set a token via env or `velocity-fleet:connect`, and call `VelocityFleet::customers()->list()`.

**Architecture:** A service provider binds the core SDK's `VelocityFleet` client (built from config + an Eloquent-backed `TokenStore`) into the container behind a `FleetManager` wrapper and a `VelocityFleet` facade. Single-row token store, overwrite-on-rotation. Three artisan commands (connect/status/customers). Auto-seed the token from config when the store is empty.

**Tech Stack:** PHP 8.3+, Laravel 11/12/13 (granular `illuminate/*`), the core SDK `chrisjohnleah/velocity-fleet-api` (Saloon v4) via a local Composer `path` repo, Pest 3/4 + orchestra/testbench, Larastan (phpstan max), Pint.

**Scope of this phase:** Parts 1–11 of the spec *minus* the platform subsystems (cache/poller/events/history/geofences/notifications come in later phases). `FleetManager` ships as a thin delegating wrapper; `fleet()`/`cached()`/`fake()` are added in later phases.

**Namespace:** `ChrisJohnLeah\VelocityFleet\Laravel\` → `src/`. **Repo:** `~/Repositories/velocity-fleet-api-laravel` (currently only `LICENSE`).

---

## Task 1: Package skeleton, tooling, and dependency resolution

**Files:**
- Create: `composer.json`
- Create: `phpstan.neon`, `pint.json`, `phpunit.xml`
- Create: `.gitignore`, `.gitattributes`

- [ ] **Step 1: Write `composer.json`**

```json
{
    "name": "chrisjohnleah/velocity-fleet-api-laravel",
    "description": "Laravel bridge for the Radius Velocity Fleet API SDK — service provider, facade, Eloquent token store, and artisan commands.",
    "keywords": ["velocity", "velocity-fleet", "radius", "telematics", "fleet", "kinesis", "laravel", "api", "oauth2", "bridge"],
    "homepage": "https://github.com/chrisjohnleah/velocity-fleet-api-laravel",
    "license": "MIT",
    "type": "library",
    "authors": [
        { "name": "Chris John Leah", "email": "christopher.leah@happywebs.co.uk" }
    ],
    "repositories": [
        { "type": "path", "url": "../velocity-fleet-api", "options": { "symlink": true } }
    ],
    "require": {
        "php": "^8.3",
        "chrisjohnleah/velocity-fleet-api": "^0.1.0",
        "illuminate/console": "^11.0 || ^12.0 || ^13.0",
        "illuminate/contracts": "^11.0 || ^12.0 || ^13.0",
        "illuminate/database": "^11.0 || ^12.0 || ^13.0",
        "illuminate/support": "^11.0 || ^12.0 || ^13.0"
    },
    "require-dev": {
        "larastan/larastan": "^3.0",
        "laravel/pint": "^1.18",
        "orchestra/testbench": "^9.0 || ^10.0 || ^11.0",
        "pestphp/pest": "^3.0 || ^4.0",
        "pestphp/pest-plugin-laravel": "^3.0 || ^4.0"
    },
    "autoload": {
        "psr-4": { "ChrisJohnLeah\\VelocityFleet\\Laravel\\": "src/" }
    },
    "autoload-dev": {
        "psr-4": { "ChrisJohnLeah\\VelocityFleet\\Laravel\\Tests\\": "tests/" }
    },
    "extra": {
        "laravel": {
            "providers": ["ChrisJohnLeah\\VelocityFleet\\Laravel\\VelocityFleetServiceProvider"],
            "aliases": { "VelocityFleet": "ChrisJohnLeah\\VelocityFleet\\Laravel\\Facades\\VelocityFleet" }
        }
    },
    "scripts": {
        "test": "pest",
        "analyse": "phpstan analyse",
        "format": "pint",
        "lint": "pint --test",
        "check": ["@lint", "@analyse", "@test"]
    },
    "config": {
        "sort-packages": true,
        "allow-plugins": { "pestphp/pest-plugin": true }
    },
    "minimum-stability": "stable",
    "prefer-stable": true
}
```

- [ ] **Step 2: Write `phpstan.neon`**

```neon
includes:
    - vendor/larastan/larastan/extension.neon

parameters:
    level: max
    paths:
        - src
    tmpDir: build/phpstan
    treatPhpDocTypesAsCertain: false
```

- [ ] **Step 3: Write `pint.json`**

```json
{
    "preset": "psr12",
    "rules": {
        "declare_strict_types": true,
        "ordered_imports": { "sort_algorithm": "alpha" },
        "no_unused_imports": true,
        "not_operator_with_successor_space": false,
        "trailing_comma_in_multiline": { "elements": ["arrays", "arguments", "parameters"] },
        "fully_qualified_strict_types": true,
        "global_namespace_import": { "import_classes": true, "import_constants": true, "import_functions": true }
    }
}
```

- [ ] **Step 4: Write `phpunit.xml`**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true"
         cacheDirectory=".phpunit.cache">
    <testsuites>
        <testsuite name="Feature">
            <directory>tests/Feature</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory>src</directory>
        </include>
    </source>
    <php>
        <env name="DB_CONNECTION" value="testing"/>
        <env name="DB_DATABASE" value=":memory:"/>
    </php>
</phpunit>
```

- [ ] **Step 5: Write `.gitignore`**

```
/vendor/
/build/
composer.lock
.phpunit.result.cache
.phpunit.cache/
.pint.cache
/coverage/
.DS_Store
.idea/
.vscode/
```

- [ ] **Step 6: Write `.gitattributes`**

```
# Normalise line endings
* text=auto eol=lf

# Keep the distributed package lean
/.github            export-ignore
/tests              export-ignore
/docs               export-ignore
/.editorconfig      export-ignore
/.gitattributes     export-ignore
/.gitignore         export-ignore
/phpunit.xml        export-ignore
/phpstan.neon       export-ignore
/pint.json          export-ignore
```

- [ ] **Step 7: Install dependencies and verify the core SDK resolves**

Run: `composer install`
Expected: success; `vendor/chrisjohnleah/velocity-fleet-api` is present (symlinked).

If resolution fails with "could not find a version of chrisjohnleah/velocity-fleet-api matching ^0.1.0", the path repo did not derive the tag version — add the explicit version to the path repo and re-run:

```json
"repositories": [
    { "type": "path", "url": "../velocity-fleet-api", "options": { "symlink": true, "versions": { "chrisjohnleah/velocity-fleet-api": "0.1.1" } } }
]
```

- [ ] **Step 8: Commit**

```bash
git add composer.json phpstan.neon pint.json phpunit.xml .gitignore .gitattributes
git commit -m "chore: package skeleton, tooling, and core SDK path dependency"
```

---

## Task 2: Config, token model, and migration

**Files:**
- Create: `config/velocity-fleet.php`
- Create: `src/Models/VelocityFleetToken.php`
- Create: `database/migrations/0001_01_01_000000_create_velocity_fleet_tokens_table.php`

- [ ] **Step 1: Write `config/velocity-fleet.php`**

```php
<?php

declare(strict_types=1);

return [
    // Static flow: a UI-generated API token (Account Settings > API Integrations). No refresh.
    'access_token' => env('VELOCITY_FLEET_ACCESS_TOKEN'),

    // Refresh flow: a customer-supplied refresh token + optional OAuth client credentials.
    'refresh_token' => env('VELOCITY_FLEET_REFRESH_TOKEN'),
    'client_id' => env('VELOCITY_FLEET_CLIENT_ID'),
    'client_secret' => env('VELOCITY_FLEET_CLIENT_SECRET'),

    // Endpoints — defaults target Radius Velocity Fleet production.
    'base_url' => env('VELOCITY_FLEET_BASE_URL', 'https://www.velocityfleet.com'),
    'token_endpoint' => env('VELOCITY_FLEET_TOKEN_ENDPOINT', 'https://www.velocityfleet.com/o/token/'),

    // The table the Eloquent token store reads/writes.
    'table' => env('VELOCITY_FLEET_TOKEN_TABLE', 'velocity_fleet_tokens'),

    // Refresh the access token this many seconds before it expires.
    'refresh_buffer_seconds' => 60,
];
```

- [ ] **Step 2: Write `src/Models/VelocityFleetToken.php`**

```php
<?php

declare(strict_types=1);

namespace ChrisJohnLeah\VelocityFleet\Laravel\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The persisted Velocity Fleet connection token. A single row is maintained and
 * overwritten on every refresh, so a rotated refresh token never goes stale.
 *
 * @property int $id
 * @property string $access_token
 * @property string|null $refresh_token
 * @property Carbon|null $expires_at
 */
class VelocityFleetToken extends Model
{
    protected $guarded = [];

    public function getTable(): string
    {
        $table = config('velocity-fleet.table', 'velocity_fleet_tokens');

        return is_string($table) ? $table : 'velocity_fleet_tokens';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }
}
```

- [ ] **Step 3: Write the migration `database/migrations/0001_01_01_000000_create_velocity_fleet_tokens_table.php`**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    private function table(): string
    {
        $table = config('velocity-fleet.table', 'velocity_fleet_tokens');

        return is_string($table) ? $table : 'velocity_fleet_tokens';
    }

    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->id();
            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }
};
```

- [ ] **Step 4: Commit**

```bash
git add config/velocity-fleet.php src/Models/VelocityFleetToken.php database/migrations
git commit -m "feat: config, token model, and tokens migration"
```

---

## Task 3: Eloquent token store

**Files:**
- Create: `src/EloquentTokenStore.php`

- [ ] **Step 1: Write `src/EloquentTokenStore.php`**

```php
<?php

declare(strict_types=1);

namespace ChrisJohnLeah\VelocityFleet\Laravel;

use ChrisJohnLeah\VelocityFleet\Auth\StoredToken;
use ChrisJohnLeah\VelocityFleet\Contracts\TokenStore;
use ChrisJohnLeah\VelocityFleet\Laravel\Models\VelocityFleetToken;

/**
 * Stores the Velocity Fleet connection's token in a single Eloquent row. put()
 * overwrites that row so the OAuth2 endpoint's rotated refresh token always
 * replaces the previous one (the core TokenStore contract requires this).
 */
final class EloquentTokenStore implements TokenStore
{
    public function get(): ?StoredToken
    {
        $row = VelocityFleetToken::query()->latest('id')->first();

        if ($row === null) {
            return null;
        }

        return new StoredToken(
            accessToken: $row->access_token,
            refreshToken: $row->refresh_token,
            expiresAt: $row->expires_at?->toDateTimeImmutable(),
        );
    }

    public function put(StoredToken $token): void
    {
        $attributes = [
            'access_token' => $token->accessToken,
            'refresh_token' => $token->refreshToken,
            'expires_at' => $token->expiresAt,
        ];

        $existing = VelocityFleetToken::query()->latest('id')->first();

        if ($existing !== null) {
            $existing->update($attributes);

            return;
        }

        VelocityFleetToken::query()->create($attributes);
    }

    public function forget(): void
    {
        VelocityFleetToken::query()->delete();
    }
}
```

(Behaviour is asserted by tests in Task 6 once the provider/testbench harness exists.)

- [ ] **Step 2: Commit**

```bash
git add src/EloquentTokenStore.php
git commit -m "feat: Eloquent-backed single-row token store"
```

---

## Task 4: FleetManager wrapper and facade

**Files:**
- Create: `src/FleetManager.php`
- Create: `src/Facades/VelocityFleet.php`

- [ ] **Step 1: Write `src/FleetManager.php`** (thin delegating wrapper; `fleet()`/`cached()`/`fake()` arrive in later phases)

```php
<?php

declare(strict_types=1);

namespace ChrisJohnLeah\VelocityFleet\Laravel;

use ChrisJohnLeah\VelocityFleet\Auth\StoredToken;
use ChrisJohnLeah\VelocityFleet\Resources\CustomersResource;
use ChrisJohnLeah\VelocityFleet\Resources\DevicePositionsResource;
use ChrisJohnLeah\VelocityFleet\VelocityFleet;
use ChrisJohnLeah\VelocityFleet\VelocityFleetConnector;
use Saloon\Http\Request;
use Saloon\Http\Response;

/**
 * The bridge's primary service: wraps the core {@see VelocityFleet} client (which
 * is final, so cannot be extended) and is the target of the VelocityFleet facade.
 * Later phases add fleet()/cached()/fake() here.
 *
 * @mixin \ChrisJohnLeah\VelocityFleet\VelocityFleet
 */
final class FleetManager
{
    public function __construct(private readonly VelocityFleet $client)
    {
    }

    public function customers(): CustomersResource
    {
        return $this->client->customers();
    }

    public function devicePositions(): DevicePositionsResource
    {
        return $this->client->devicePositions();
    }

    public function send(Request $request): Response
    {
        return $this->client->send($request);
    }

    public function refresh(StoredToken $token): StoredToken
    {
        return $this->client->refresh($token);
    }

    public function connector(): VelocityFleetConnector
    {
        return $this->client->connector();
    }

    /**
     * The underlying core SDK client, for advanced use.
     */
    public function client(): VelocityFleet
    {
        return $this->client;
    }
}
```

- [ ] **Step 2: Write `src/Facades/VelocityFleet.php`**

```php
<?php

declare(strict_types=1);

namespace ChrisJohnLeah\VelocityFleet\Laravel\Facades;

use ChrisJohnLeah\VelocityFleet\Laravel\FleetManager;
use Illuminate\Support\Facades\Facade;

/**
 * @mixin \ChrisJohnLeah\VelocityFleet\Laravel\FleetManager
 */
class VelocityFleet extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return FleetManager::class;
    }
}
```

- [ ] **Step 3: Commit**

```bash
git add src/FleetManager.php src/Facades/VelocityFleet.php
git commit -m "feat: FleetManager wrapper and VelocityFleet facade"
```

---

## Task 5: Service provider, testbench harness, and container-resolution test

**Files:**
- Create: `src/VelocityFleetServiceProvider.php`
- Create: `tests/TestCase.php`
- Create: `tests/Pest.php`
- Test: `tests/Feature/BridgeTest.php`

- [ ] **Step 1: Write `src/VelocityFleetServiceProvider.php`**

```php
<?php

declare(strict_types=1);

namespace ChrisJohnLeah\VelocityFleet\Laravel;

use ChrisJohnLeah\VelocityFleet\Auth\StoredToken;
use ChrisJohnLeah\VelocityFleet\Contracts\TokenStore;
use ChrisJohnLeah\VelocityFleet\Laravel\Commands\ConnectCommand;
use ChrisJohnLeah\VelocityFleet\Laravel\Commands\CustomersCommand;
use ChrisJohnLeah\VelocityFleet\Laravel\Commands\StatusCommand;
use ChrisJohnLeah\VelocityFleet\VelocityFleet;
use ChrisJohnLeah\VelocityFleet\VelocityFleetConnector;
use DateTimeImmutable;
use Illuminate\Support\ServiceProvider;
use Throwable;

class VelocityFleetServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/velocity-fleet.php', 'velocity-fleet');

        $this->app->singleton(TokenStore::class, EloquentTokenStore::class);

        $this->app->scoped(VelocityFleetConnector::class, fn (): VelocityFleetConnector => new VelocityFleetConnector(
            baseUrl: $this->stringConfig('velocity-fleet.base_url', VelocityFleetConnector::DEFAULT_BASE_URL),
            tokenEndpoint: $this->stringConfig('velocity-fleet.token_endpoint', VelocityFleetConnector::DEFAULT_TOKEN_ENDPOINT),
            clientId: $this->nullableStringConfig('velocity-fleet.client_id'),
            clientSecret: $this->nullableStringConfig('velocity-fleet.client_secret'),
        ));

        $this->app->scoped(VelocityFleet::class, function (): VelocityFleet {
            $store = $this->app->make(TokenStore::class);
            $this->seedFromConfig($store);

            return new VelocityFleet(
                $this->app->make(VelocityFleetConnector::class),
                $store,
                $this->intConfig('velocity-fleet.refresh_buffer_seconds', 60),
            );
        });

        $this->app->scoped(FleetManager::class, fn (): FleetManager => new FleetManager(
            $this->app->make(VelocityFleet::class),
        ));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                ConnectCommand::class,
                StatusCommand::class,
                CustomersCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/velocity-fleet.php' => $this->app->configPath('velocity-fleet.php'),
            ], 'velocity-fleet-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => $this->app->databasePath('migrations'),
            ], 'velocity-fleet-migrations');
        }
    }

    /**
     * Seed the token store from config when it is empty, so setting an env token
     * "just works". A stored row always wins, so a redeploy never clobbers a
     * rotated refresh token. Best-effort: never fatal if the table is absent.
     */
    private function seedFromConfig(TokenStore $store): void
    {
        try {
            if ($store->get() !== null) {
                return;
            }

            $refresh = $this->nullableStringConfig('velocity-fleet.refresh_token');
            $access = $this->nullableStringConfig('velocity-fleet.access_token');

            if ($refresh !== null) {
                $store->put(new StoredToken('', $refresh, new DateTimeImmutable('-1 second')));
            } elseif ($access !== null) {
                $store->put(new StoredToken($access));
            }
        } catch (Throwable) {
            // Table not migrated yet / DB unavailable — seeding is best-effort.
        }
    }

    private function stringConfig(string $key, string $default = ''): string
    {
        $value = config($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    private function nullableStringConfig(string $key): ?string
    {
        $value = config($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function intConfig(string $key, int $default): int
    {
        $value = config($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }
}
```

- [ ] **Step 2: Write `tests/TestCase.php`**

```php
<?php

declare(strict_types=1);

namespace ChrisJohnLeah\VelocityFleet\Laravel\Tests;

use ChrisJohnLeah\VelocityFleet\Laravel\VelocityFleetServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [VelocityFleetServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('velocity-fleet.base_url', 'https://www.velocityfleet.com');
        $app['config']->set('velocity-fleet.token_endpoint', 'https://www.velocityfleet.com/o/token/');
        $app['config']->set('velocity-fleet.client_id', 'test-client');
        $app['config']->set('velocity-fleet.client_secret', 'test-secret');
        // Default to no auto-seed so each test starts from an empty store.
        $app['config']->set('velocity-fleet.access_token', null);
        $app['config']->set('velocity-fleet.refresh_token', null);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
```

- [ ] **Step 3: Write `tests/Pest.php`**

```php
<?php

declare(strict_types=1);

use ChrisJohnLeah\VelocityFleet\Laravel\Tests\TestCase;

uses(TestCase::class)->in('Feature');
```

- [ ] **Step 4: Write the failing test `tests/Feature/BridgeTest.php`**

```php
<?php

declare(strict_types=1);

use ChrisJohnLeah\VelocityFleet\Contracts\TokenStore;
use ChrisJohnLeah\VelocityFleet\Laravel\EloquentTokenStore;
use ChrisJohnLeah\VelocityFleet\Laravel\Facades\VelocityFleet;
use ChrisJohnLeah\VelocityFleet\Laravel\FleetManager;
use ChrisJohnLeah\VelocityFleet\VelocityFleet as VelocityFleetClient;
use ChrisJohnLeah\VelocityFleet\VelocityFleetConnector;

it('resolves the manager, client and connector from the container', function () {
    expect(app(FleetManager::class))->toBeInstanceOf(FleetManager::class)
        ->and(app(VelocityFleetClient::class))->toBeInstanceOf(VelocityFleetClient::class)
        ->and(app(VelocityFleetConnector::class))->toBeInstanceOf(VelocityFleetConnector::class);
});

it('binds an Eloquent-backed token store', function () {
    expect(app(TokenStore::class))->toBeInstanceOf(EloquentTokenStore::class);
});

it('points the facade at the FleetManager', function () {
    expect(VelocityFleet::getFacadeRoot())->toBeInstanceOf(FleetManager::class);
});
```

- [ ] **Step 5: Run to verify it fails**

Run: `vendor/bin/pest tests/Feature/BridgeTest.php`
Expected: PASS (all three) — this is the first runnable harness check. If it ERRORS on a missing class, fix the referenced file before continuing.

- [ ] **Step 6: Run the static analysers**

Run: `vendor/bin/pint --test && vendor/bin/phpstan analyse --no-progress`
Expected: both clean.

- [ ] **Step 7: Commit**

```bash
git add src/VelocityFleetServiceProvider.php tests/TestCase.php tests/Pest.php tests/Feature/BridgeTest.php
git commit -m "feat: service provider, testbench harness, container resolution test"
```

---

## Task 6: Token store behaviour + auto-seed tests

**Files:**
- Modify: `tests/Feature/BridgeTest.php` (append)

- [ ] **Step 1a: Add two imports to the TOP of `tests/Feature/BridgeTest.php`** (alongside the existing `use` statements — PHP requires imports before any other statement, so do NOT put these mid-file):

```php
use ChrisJohnLeah\VelocityFleet\Auth\StoredToken;
use ChrisJohnLeah\VelocityFleet\Laravel\Models\VelocityFleetToken;
```

- [ ] **Step 1b: Append the token-store and auto-seed test blocks to the END of `tests/Feature/BridgeTest.php`**

```php
it('round-trips a token and keeps a single row, overwriting on rotation', function () {
    $store = app(TokenStore::class);

    expect($store->get())->toBeNull();

    $store->put(new StoredToken('access-1', 'refresh-1', new DateTimeImmutable('+5 minutes')));

    expect($store->get()->accessToken)->toBe('access-1')
        ->and($store->get()->refreshToken)->toBe('refresh-1')
        ->and(VelocityFleetToken::count())->toBe(1);

    $store->put(new StoredToken('access-2', 'refresh-2', new DateTimeImmutable('+5 minutes')));

    expect($store->get()->accessToken)->toBe('access-2')
        ->and(VelocityFleetToken::count())->toBe(1);

    $store->forget();

    expect($store->get())->toBeNull();
});

it('persists only the three Velocity token fields (no business_id)', function () {
    app(TokenStore::class)->put(new StoredToken('a', 'r', new DateTimeImmutable('+1 hour')));

    $attributes = array_keys(VelocityFleetToken::query()->firstOrFail()->getAttributes());

    expect($attributes)->not->toContain('business_id');
});

it('auto-seeds the store from a configured refresh token when empty', function () {
    config()->set('velocity-fleet.refresh_token', 'seed-refresh');

    // Resolving the client triggers the provider's seedFromConfig().
    app(\ChrisJohnLeah\VelocityFleet\VelocityFleet::class);

    $token = app(TokenStore::class)->get();

    expect($token)->not->toBeNull()
        ->and($token->refreshToken)->toBe('seed-refresh')
        ->and($token->hasExpired())->toBeTrue();
});

it('does not overwrite an existing row when auto-seeding', function () {
    app(TokenStore::class)->put(new StoredToken('existing', 'existing-refresh', new DateTimeImmutable('+1 hour')));
    config()->set('velocity-fleet.refresh_token', 'seed-refresh');

    app(\ChrisJohnLeah\VelocityFleet\VelocityFleet::class);

    expect(app(TokenStore::class)->get()->accessToken)->toBe('existing');
});
```

- [ ] **Step 2: Run to verify the new tests pass**

Run: `vendor/bin/pest tests/Feature/BridgeTest.php`
Expected: PASS (all). The store already exists (Task 3), so these characterise + lock its behaviour and the provider's auto-seed.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/BridgeTest.php
git commit -m "test: token store round-trip, single-row overwrite, and config auto-seed"
```

---

## Task 7: Artisan commands + command tests

**Files:**
- Create: `src/Commands/ConnectCommand.php`, `src/Commands/StatusCommand.php`, `src/Commands/CustomersCommand.php`
- Test: `tests/Feature/CommandsTest.php`

- [ ] **Step 1: Write the failing test `tests/Feature/CommandsTest.php`**

```php
<?php

declare(strict_types=1);

use ChrisJohnLeah\VelocityFleet\Auth\StoredToken;
use ChrisJohnLeah\VelocityFleet\Contracts\TokenStore;
use ChrisJohnLeah\VelocityFleet\Requests\Customers\GetCustomers;
use ChrisJohnLeah\VelocityFleet\VelocityFleetConnector;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

it('connect stores a refresh token (expired seed forces first exchange)', function () {
    $this->artisan('velocity-fleet:connect', ['--refresh-token' => 'rt-123'])->assertSuccessful();

    $token = app(TokenStore::class)->get();

    expect($token->refreshToken)->toBe('rt-123')
        ->and($token->hasExpired())->toBeTrue();
});

it('connect stores a static access token', function () {
    $this->artisan('velocity-fleet:connect', ['--token' => 'at-123'])->assertSuccessful();

    expect(app(TokenStore::class)->get()->accessToken)->toBe('at-123');
});

it('connect fails when no token is given and none is configured', function () {
    $this->artisan('velocity-fleet:connect')->assertFailed();
});

it('status fails when not connected', function () {
    $this->artisan('velocity-fleet:status')->assertFailed();
});

it('status succeeds once a token is stored', function () {
    app(TokenStore::class)->put(new StoredToken('at', 'rt', new DateTimeImmutable('+5 minutes')));

    $this->artisan('velocity-fleet:status')->assertSuccessful();
});

it('customers reports not-connected when no token is stored', function () {
    $this->artisan('velocity-fleet:customers')->assertFailed();
});

it('customers lists customers when connected', function () {
    app(TokenStore::class)->put(new StoredToken('static-token'));

    $mock = new MockClient([
        GetCustomers::class => MockResponse::make([
            '11111' => ['name' => 'Acme Ltd', 'number' => '111', 'product' => 'Telematics'],
        ], 200),
    ]);
    app(VelocityFleetConnector::class)->withMockClient($mock);

    $this->artisan('velocity-fleet:customers')->assertSuccessful();
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/pest tests/Feature/CommandsTest.php`
Expected: FAIL — commands not registered (`Command "velocity-fleet:connect" is not defined`).

- [ ] **Step 3: Write `src/Commands/ConnectCommand.php`**

```php
<?php

declare(strict_types=1);

namespace ChrisJohnLeah\VelocityFleet\Laravel\Commands;

use ChrisJohnLeah\VelocityFleet\Auth\StoredToken;
use ChrisJohnLeah\VelocityFleet\Contracts\TokenStore;
use DateTimeImmutable;
use Illuminate\Console\Command;

class ConnectCommand extends Command
{
    protected $signature = 'velocity-fleet:connect {--token= : A ready-to-use API access token} {--refresh-token= : A customer-supplied OAuth refresh token}';

    protected $description = 'Store a Velocity Fleet access or refresh token for this application.';

    public function handle(TokenStore $store): int
    {
        $refresh = $this->stringOption('refresh-token') ?? $this->configString('velocity-fleet.refresh_token');
        $token = $this->stringOption('token') ?? $this->configString('velocity-fleet.access_token');

        if ($refresh !== null) {
            // An expired seed forces the first API call to exchange the refresh token.
            $store->put(new StoredToken('', $refresh, new DateTimeImmutable('-1 second')));
            $this->info('Stored a refresh token. The first API call will exchange it for an access token.');

            return self::SUCCESS;
        }

        if ($token !== null) {
            $store->put(new StoredToken($token));
            $this->info('Stored a static access token.');

            return self::SUCCESS;
        }

        $this->error('No token provided. Pass --refresh-token=… or --token=…, or set VELOCITY_FLEET_REFRESH_TOKEN / VELOCITY_FLEET_ACCESS_TOKEN.');

        return self::FAILURE;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function configString(string $key): ?string
    {
        $value = config($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
```

- [ ] **Step 4: Write `src/Commands/StatusCommand.php`**

```php
<?php

declare(strict_types=1);

namespace ChrisJohnLeah\VelocityFleet\Laravel\Commands;

use ChrisJohnLeah\VelocityFleet\Contracts\TokenStore;
use Illuminate\Console\Command;

class StatusCommand extends Command
{
    protected $signature = 'velocity-fleet:status';

    protected $description = 'Show the current Velocity Fleet connection status.';

    public function handle(TokenStore $store): int
    {
        $token = $store->get();

        if ($token === null) {
            $this->warn('Not connected to Velocity Fleet. Run `php artisan velocity-fleet:connect` to begin.');

            return self::FAILURE;
        }

        $this->info('Connected to Velocity Fleet.');
        $this->table(['Field', 'Value'], [
            ['Mode', $token->refreshToken !== null ? 'refresh-token (auto-refreshing)' : 'static access token'],
            ['Access token', $token->accessToken !== '' ? substr($token->accessToken, 0, 6).'…' : '(pending first exchange)'],
            ['Refresh token', $token->refreshToken !== null ? 'present' : 'missing'],
            ['Expires at', $token->expiresAt?->format('Y-m-d H:i:s') ?? 'unknown'],
            ['Expired', $token->hasExpired() ? 'YES — will refresh on next call' : 'no'],
        ]);

        return self::SUCCESS;
    }
}
```

- [ ] **Step 5: Write `src/Commands/CustomersCommand.php`**

```php
<?php

declare(strict_types=1);

namespace ChrisJohnLeah\VelocityFleet\Laravel\Commands;

use ChrisJohnLeah\VelocityFleet\Exceptions\NotConnectedException;
use ChrisJohnLeah\VelocityFleet\Exceptions\VelocityFleetException;
use ChrisJohnLeah\VelocityFleet\Laravel\FleetManager;
use Illuminate\Console\Command;

class CustomersCommand extends Command
{
    protected $signature = 'velocity-fleet:customers';

    protected $description = 'List the customers available to the connected Velocity Fleet account (live connectivity check).';

    public function handle(FleetManager $fleet): int
    {
        try {
            $customers = $fleet->customers()->list();
        } catch (NotConnectedException $exception) {
            $this->error($exception->getMessage());
            $this->line('Run `php artisan velocity-fleet:connect` first.');

            return self::FAILURE;
        } catch (VelocityFleetException $exception) {
            $this->error('Velocity Fleet API error: '.$exception->getMessage());

            return self::FAILURE;
        }

        if ($customers === []) {
            $this->warn('No customers found for this account.');

            return self::SUCCESS;
        }

        $this->table(['ID', 'Number', 'Name', 'Product'], array_map(
            static fn ($customer): array => [$customer->id, $customer->number, $customer->name, $customer->product],
            $customers,
        ));

        return self::SUCCESS;
    }
}
```

- [ ] **Step 6: Run to verify the command tests pass**

Run: `vendor/bin/pest tests/Feature/CommandsTest.php`
Expected: PASS (all seven). If the `customers lists customers` test is flaky on the Saloon `MockClient` wiring (e.g. the resolved connector differs from the command's), drop that single test and keep the not-connected assertion — the live call is covered by the core SDK's own suite. (Spec §12.)

- [ ] **Step 7: Commit**

```bash
git add src/Commands tests/Feature/CommandsTest.php
git commit -m "feat: connect, status, and customers artisan commands"
```

---

## Task 8: CI workflow, meta files, and full green gate

**Files:**
- Create: `.github/workflows/ci.yml`
- Create: `README.md`, `CHANGELOG.md`, `CONTRIBUTING.md`, `SECURITY.md`, `CODE_OF_CONDUCT.md`

- [ ] **Step 1: Write `.github/workflows/ci.yml`**

```yaml
name: CI

on:
  push:
    branches: [main]
  pull_request:

jobs:
  quality:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
          extensions: mbstring, json, curl, sqlite3, pdo_sqlite
          coverage: none
          tools: composer:v2
      - run: composer install --prefer-dist --no-interaction --no-progress
      - run: vendor/bin/pint --test
      - run: vendor/bin/phpstan analyse --no-progress

  tests:
    runs-on: ubuntu-latest
    strategy:
      fail-fast: false
      matrix:
        php: ['8.3', '8.4']
        laravel: ['11.*', '12.*', '13.*']
        dependency-version: [prefer-lowest, prefer-stable]
        include:
          - laravel: '11.*'
            testbench: '9.*'
          - laravel: '12.*'
            testbench: '10.*'
          - laravel: '13.*'
            testbench: '11.*'
    name: P${{ matrix.php }} L${{ matrix.laravel }} ${{ matrix.dependency-version }}
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php }}
          extensions: mbstring, json, curl, sqlite3, pdo_sqlite
          coverage: none
          tools: composer:v2
      - name: Constrain Laravel + Testbench
        run: composer require "illuminate/contracts:${{ matrix.laravel }}" "orchestra/testbench:${{ matrix.testbench }}" --no-interaction --no-update
      - name: Resolve dependencies
        run: composer update --${{ matrix.dependency-version }} --prefer-dist --no-interaction --no-progress
      - name: Tests
        run: vendor/bin/pest --ci
```

> **Note for the engineer:** CI cannot resolve the core SDK from the local `path` repo. Until the core SDK is on Packagist, either (a) add a `vcs` repository to the core's GitHub URL in `composer.json` for CI, or (b) gate the `tests` job behind core publication. Document whichever you choose in the README. Do not silently let CI go red.

- [ ] **Step 2: Write `CHANGELOG.md`**

```markdown
# Changelog

All notable changes to this project are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Service provider, `VelocityFleet` facade, and an Eloquent-backed single-row `TokenStore` for the core SDK.
- Config (`config/velocity-fleet.php`) with static-token and refresh-token flows, endpoints, and the token table.
- Zero-wiring auto-seed: the token store is populated from config when empty (a stored row always wins).
- Artisan commands: `velocity-fleet:connect`, `velocity-fleet:status`, `velocity-fleet:customers`.

[Unreleased]: https://github.com/chrisjohnleah/velocity-fleet-api-laravel/commits/main
```

- [ ] **Step 3: Write `README.md`** (foundation version; expanded feature docs land with later phases)

```markdown
# Velocity Fleet — Laravel

The Laravel bridge for [`chrisjohnleah/velocity-fleet-api`](https://github.com/chrisjohnleah/velocity-fleet-api). Adds a service provider, a `VelocityFleet` facade, an Eloquent token store, and artisan commands — so a Laravel app can talk to the Radius Velocity Fleet API with zero wiring.

## Requirements

- PHP 8.3+
- Laravel 11, 12, or 13

## Installation

```bash
composer require chrisjohnleah/velocity-fleet-api-laravel
php artisan migrate                                  # creates the velocity_fleet_tokens table
php artisan vendor:publish --tag=velocity-fleet-config   # optional
```

Set a token in `.env` — either a UI-generated API token, or a customer-supplied refresh token:

```dotenv
# Static API token (Account Settings > API Integrations)
VELOCITY_FLEET_ACCESS_TOKEN=...

# …or the third-party refresh-token flow
VELOCITY_FLEET_REFRESH_TOKEN=...
VELOCITY_FLEET_CLIENT_ID=...
VELOCITY_FLEET_CLIENT_SECRET=...
```

## Connecting

The token is auto-seeded from `.env` on first use. To store it explicitly:

```bash
php artisan velocity-fleet:connect --refresh-token=...   # or --token=...
php artisan velocity-fleet:status                        # check the connection
php artisan velocity-fleet:customers                     # live connectivity check
```

## Using it

```php
use ChrisJohnLeah\VelocityFleet\Laravel\Facades\VelocityFleet;

$customers = VelocityFleet::customers()->list();
$positions = VelocityFleet::devicePositions()->forCustomer($customerId);
```

## Configuration

Publish `config/velocity-fleet.php` to customise endpoints, the token table, and the refresh buffer.

## Testing

```bash
composer check   # pint + phpstan + pest
```

## Licence

MIT. Radius and Velocity Fleet are trademarks of their respective owners; this package is an independent, unofficial integration.
```

- [ ] **Step 4: Write `SECURITY.md`, `CONTRIBUTING.md`, `CODE_OF_CONDUCT.md`** (mirror the core SDK's, adjusted name)

`SECURITY.md`:

```markdown
# Security Policy

If you discover a security vulnerability, please email **christopher.leah@happywebs.co.uk** privately rather than opening a public issue. You'll get an acknowledgement as soon as possible.
```

`CONTRIBUTING.md`:

```markdown
# Contributing

```bash
git clone https://github.com/chrisjohnleah/velocity-fleet-api-laravel
cd velocity-fleet-api-laravel
composer install
composer check   # pint + phpstan + pest, all must pass
```

Tests run against `orchestra/testbench` with an in-memory SQLite database and never hit the network (the core SDK is faked via Saloon's `MockClient`). New behaviour needs a test. Keep `phpstan analyse` green at `level: max`.
```

`CODE_OF_CONDUCT.md`:

```markdown
# Code of Conduct

This project follows the [Contributor Covenant v2.1](https://www.contributor-covenant.org/version/2/1/code_of_conduct/). Report unacceptable behaviour to **christopher.leah@happywebs.co.uk**.
```

- [ ] **Step 5: Run the full check**

Run: `composer check`
Expected: pint clean, phpstan `level: max` clean, **all Pest tests pass**.

- [ ] **Step 6: Commit**

```bash
git add .github CHANGELOG.md README.md SECURITY.md CONTRIBUTING.md CODE_OF_CONDUCT.md
git commit -m "chore: CI workflow and project meta files"
```

---

## Phase 1 self-review checklist (run before declaring done)

- [ ] `composer check` is fully green (pint + phpstan max + pest).
- [ ] A token set only via `.env` makes `VelocityFleet::customers()->list()` work with no other wiring (auto-seed).
- [ ] The token store keeps exactly one row across refreshes and never writes a `business_id`.
- [ ] All three commands behave per their tests (connect persists; status reflects state; customers checks connectivity).
- [ ] No platform subsystem leaked into Phase 1 (no cache/poller/events/history/geofences/notifications yet).
- [ ] The core SDK resolves from the `path` repo; the CI core-SDK-resolution caveat (Task 8 Step 1 note) is documented, not ignored.

## Next phases (separate plans, written just-in-time against landed code)

Phase 2 Security-by-default (encrypted tokens, redaction, doctor) → Phase 3 Query+cache spine → Phase 4 Event spine + testing toolkit → Phase 5 History + lifecycle → Phase 6 Observability → Phase 7 Geofences + notifications → Phase 8 Docs + CI green + adversarial review. See spec Part B.18.

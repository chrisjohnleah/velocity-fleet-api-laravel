<?php

declare(strict_types=1);

namespace ChrisJohnLeah\VelocityFleet\Laravel;

use ChrisJohnLeah\VelocityFleet\Auth\StoredToken;
use ChrisJohnLeah\VelocityFleet\Contracts\TokenStore;
use ChrisJohnLeah\VelocityFleet\Laravel\Cache\CachedPositions;
use ChrisJohnLeah\VelocityFleet\Laravel\Commands\ConnectCommand;
use ChrisJohnLeah\VelocityFleet\Laravel\Commands\CustomersCommand;
use ChrisJohnLeah\VelocityFleet\Laravel\Commands\DoctorCommand;
use ChrisJohnLeah\VelocityFleet\Laravel\Commands\EncryptTokensCommand;
use ChrisJohnLeah\VelocityFleet\Laravel\Commands\PollCommand;
use ChrisJohnLeah\VelocityFleet\Laravel\Commands\PrunePositionsCommand;
use ChrisJohnLeah\VelocityFleet\Laravel\Commands\StatusCommand;
use ChrisJohnLeah\VelocityFleet\Laravel\Contracts\FleetSnapshotStore;
use ChrisJohnLeah\VelocityFleet\Laravel\Contracts\MetricsRecorder;
use ChrisJohnLeah\VelocityFleet\Laravel\Contracts\PositionsCache;
use ChrisJohnLeah\VelocityFleet\Laravel\Events\DevicePositionsUpdated;
use ChrisJohnLeah\VelocityFleet\Laravel\Events\DeviceWentStale;
use ChrisJohnLeah\VelocityFleet\Laravel\Events\VehicleArrived;
use ChrisJohnLeah\VelocityFleet\Laravel\Events\VehicleEnteredGeofence;
use ChrisJohnLeah\VelocityFleet\Laravel\Events\VehicleExitedGeofence;
use ChrisJohnLeah\VelocityFleet\Laravel\Listeners\LogVelocityFleetActivity;
use ChrisJohnLeah\VelocityFleet\Laravel\Listeners\MatchGeofences;
use ChrisJohnLeah\VelocityFleet\Laravel\Listeners\PersistDevicePositions;
use ChrisJohnLeah\VelocityFleet\Laravel\Listeners\SendFleetAlerts;
use ChrisJohnLeah\VelocityFleet\Laravel\Metrics\NullMetricsRecorder;
use ChrisJohnLeah\VelocityFleet\Laravel\Snapshot\CacheSnapshotStore;
use ChrisJohnLeah\VelocityFleet\Laravel\Support\RefreshRateResolver;
use ChrisJohnLeah\VelocityFleet\VelocityFleet;
use ChrisJohnLeah\VelocityFleet\VelocityFleetConnector;
use DateTimeImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use RuntimeException;
use Throwable;

class VelocityFleetServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/velocity-fleet.php', 'velocity-fleet');

        $this->app->singleton(TokenStore::class, EloquentTokenStore::class);

        $this->app->singleton(VelocityFleetConnector::class, fn (): VelocityFleetConnector => new VelocityFleetConnector(
            baseUrl: $this->stringConfig('velocity-fleet.base_url', VelocityFleetConnector::DEFAULT_BASE_URL),
            tokenEndpoint: $this->stringConfig('velocity-fleet.token_endpoint', VelocityFleetConnector::DEFAULT_TOKEN_ENDPOINT),
            clientId: $this->nullableStringConfig('velocity-fleet.client_id'),
            clientSecret: $this->nullableStringConfig('velocity-fleet.client_secret'),
        ));

        $this->app->singleton(VelocityFleet::class, function (): VelocityFleet {
            $tokenStore = $this->app->make(TokenStore::class);

            $client = new VelocityFleet(
                $this->app->make(VelocityFleetConnector::class),
                $tokenStore,
                $this->intConfig('velocity-fleet.refresh_buffer_seconds', 60),
            );

            $this->seedTokenFromConfig($tokenStore);

            return $client;
        });

        $this->app->singleton(MetricsRecorder::class, NullMetricsRecorder::class);

        $this->app->singleton(PositionsCache::class, fn (): PositionsCache => new CachedPositions(
            $this->app->make(VelocityFleet::class),
            $this->cacheRepository(),
            new RefreshRateResolver(
                $this->intConfig('velocity-fleet.cache.min_ttl', 25),
                $this->intConfig('velocity-fleet.cache.large_fleet_threshold', 100),
            ),
            $this->app->make(MetricsRecorder::class),
            $this->stringConfig('velocity-fleet.cache.prefix', 'velocity_fleet'),
            $this->intConfig('velocity-fleet.cache.lock_ttl', 45),
            $this->intConfig('velocity-fleet.cache.lock_seconds', 10),
        ));

        $this->app->singleton(FleetManager::class, fn (): FleetManager => new FleetManager(
            $this->app->make(VelocityFleet::class),
            $this->app->make(PositionsCache::class),
        ));

        $this->app->singleton(FleetSnapshotStore::class, fn (): FleetSnapshotStore => new CacheSnapshotStore(
            $this->cacheRepository(),
            $this->stringConfig('velocity-fleet.cache.prefix', 'velocity_fleet'),
        ));
    }

    public function boot(): void
    {
        $this->guardEncryptionKey();

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->registerEventListeners();
        $this->registerSchedule();

        if ($this->app->runningInConsole()) {
            $this->commands([
                ConnectCommand::class,
                StatusCommand::class,
                CustomersCommand::class,
                PollCommand::class,
                PrunePositionsCommand::class,
                EncryptTokensCommand::class,
                DoctorCommand::class,
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
     * Zero-wiring seed: when the store is empty and config carries a token, seed
     * it. A refresh token becomes an already-expired seed so the first call
     * performs the exchange; a static access token is stored as-is. A stored row
     * always wins (we only seed an empty store), so a redeploy never clobbers a
     * rotated refresh token. Guarded so resolving the client never fatals before
     * the migration has run.
     */
    private function seedTokenFromConfig(TokenStore $tokenStore): void
    {
        try {
            if ($tokenStore->get() !== null) {
                return;
            }

            $refreshToken = $this->nullableStringConfig('velocity-fleet.refresh_token');

            if ($refreshToken !== null) {
                $tokenStore->put(new StoredToken('', $refreshToken, new DateTimeImmutable('-1 second')));

                return;
            }

            $accessToken = $this->nullableStringConfig('velocity-fleet.access_token');

            if ($accessToken !== null) {
                $tokenStore->put(new StoredToken($accessToken));
            }
        } catch (Throwable) {
            // Migration not run yet (or DB unavailable) — seed later via the command.
        }
    }

    /**
     * Fail loudly if token encryption is on but no APP_KEY is configured —
     * otherwise the encrypted casts would error at write time, opaquely.
     */
    private function guardEncryptionKey(): void
    {
        if (config('velocity-fleet.encrypt_tokens', true) === false) {
            return;
        }

        $key = config('app.key');

        if (! is_string($key) || $key === '') {
            throw new RuntimeException(
                'velocity-fleet: token encryption is enabled but APP_KEY is not set. '
                .'Set APP_KEY, or disable it with VELOCITY_FLEET_ENCRYPT_TOKENS=false.',
            );
        }
    }

    /**
     * Wire the change-detection events to their listeners. Each listener self-gates
     * on its config flag (history / geofencing / notifications), so registering
     * them unconditionally is cheap and safe — they no-op when disabled.
     */
    private function registerEventListeners(): void
    {
        Event::subscribe(LogVelocityFleetActivity::class);

        Event::listen(DevicePositionsUpdated::class, PersistDevicePositions::class);
        Event::listen(DevicePositionsUpdated::class, MatchGeofences::class);
        Event::listen(DevicePositionsUpdated::class, [SendFleetAlerts::class, 'handlePositionsUpdated']);

        Event::listen(VehicleArrived::class, [SendFleetAlerts::class, 'handleVehicleArrived']);
        Event::listen(VehicleEnteredGeofence::class, [SendFleetAlerts::class, 'handleVehicleEnteredGeofence']);
        Event::listen(VehicleExitedGeofence::class, [SendFleetAlerts::class, 'handleVehicleExitedGeofence']);
        Event::listen(DeviceWentStale::class, [SendFleetAlerts::class, 'handleDeviceWentStale']);
    }

    /**
     * Schedule polling + retention, each gated behind its config flag (both
     * default OFF). Cadence is a coarse floor; the cache derives the real TTL.
     */
    private function registerSchedule(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            if (config('velocity-fleet.polling.enabled') === true) {
                $schedule->command('velocity-fleet:poll')
                    ->everyThirtySeconds()
                    ->withoutOverlapping()
                    ->onOneServer();
            }

            if (config('velocity-fleet.history.enabled') === true) {
                $schedule->command('velocity-fleet:prune-positions')->daily();
            }
        });
    }

    private function cacheRepository(): CacheRepository
    {
        $store = config('velocity-fleet.cache.store');
        $store = is_string($store) && $store !== '' ? $store : null;

        return $this->app->make(CacheFactory::class)->store($store);
    }

    private function stringConfig(string $key, string $default = ''): string
    {
        $value = config($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    private function nullableStringConfig(string $key): ?string
    {
        $value = config($key);

        if (! is_scalar($value)) {
            return null;
        }

        $string = (string) $value;

        return $string === '' ? null : $string;
    }

    private function intConfig(string $key, int $default): int
    {
        $value = config($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }
}

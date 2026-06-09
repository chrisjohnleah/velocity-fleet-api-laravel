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

<?php

declare(strict_types=1);

namespace ChrisJohnLeah\VelocityFleet\Laravel\Commands;

use ChrisJohnLeah\VelocityFleet\Auth\StoredToken;
use ChrisJohnLeah\VelocityFleet\Contracts\TokenStore;
use DateTimeImmutable;
use Illuminate\Console\Command;

class ConnectCommand extends Command
{
    protected $signature = 'velocity-fleet:connect
        {--token= : A ready API access token (from the Velocity UI)}
        {--refresh-token= : A customer-issued refresh token to exchange on first use}';

    protected $description = 'Store a Velocity Fleet token (static access token or refresh token) for the connection.';

    public function handle(TokenStore $store): int
    {
        $refreshToken = $this->stringOption('refresh-token') ?? $this->stringConfig('velocity-fleet.refresh_token');

        if ($refreshToken !== null) {
            // An already-expired seed forces the exchange on the first API call.
            $store->put(new StoredToken('', $refreshToken, new DateTimeImmutable('-1 second')));
            $this->info('Stored a refresh token — it will be exchanged for an access token on the first call.');

            return self::SUCCESS;
        }

        $accessToken = $this->stringOption('token') ?? $this->stringConfig('velocity-fleet.access_token');

        if ($accessToken !== null) {
            $store->put(new StoredToken($accessToken));
            $this->info('Stored a static access token.');

            return self::SUCCESS;
        }

        $this->error('No token provided. Pass --refresh-token or --token, or set '
            .'VELOCITY_FLEET_REFRESH_TOKEN / VELOCITY_FLEET_ACCESS_TOKEN.');

        return self::FAILURE;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function stringConfig(string $key): ?string
    {
        $value = config($key);

        if (! is_scalar($value)) {
            return null;
        }

        $string = (string) $value;

        return $string === '' ? null : $string;
    }
}

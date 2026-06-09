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
            ['Mode', $token->refreshToken !== null ? 'refresh token' : 'static access token'],
            ['Access token', $token->accessToken === '' ? '(pending first refresh)' : substr($token->accessToken, 0, 6).'…'],
            ['Refresh token', $token->refreshToken !== null ? 'present' : 'missing'],
            ['Expires at', $token->expiresAt?->format('Y-m-d H:i:s') ?? 'unknown'],
            ['Expired', $token->hasExpired() ? 'YES — will refresh on next call' : 'no'],
        ]);

        return self::SUCCESS;
    }
}

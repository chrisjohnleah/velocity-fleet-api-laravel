<?php

declare(strict_types=1);

namespace ChrisJohnLeah\VelocityFleet\Laravel\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Persisted Velocity Fleet token. A single row is maintained (the connection),
 * overwritten on every refresh so a rotated refresh token is never left stale.
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

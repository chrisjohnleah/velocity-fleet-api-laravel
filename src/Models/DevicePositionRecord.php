<?php

declare(strict_types=1);

namespace ChrisJohnLeah\VelocityFleet\Laravel\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A single persisted historical device position. One row per
 * (customer_id, device_id, recorded_at) — see the unique index that backs the
 * idempotent upsert performed by {@see \ChrisJohnLeah\VelocityFleet\Laravel\Jobs\IngestPositionsJob}.
 *
 * The PII columns ({@see $driver_name}, {@see $vehicle_registration},
 * {@see $mobile_phone}) are stored as Laravel `encrypted` ciphertext when
 * `velocity-fleet.history.encrypt_pii` is enabled (the default), so they are
 * unreadable at rest without the application key.
 *
 * @property int $id
 * @property string $customer_id
 * @property string $device_id
 * @property string|null $service_id
 * @property Carbon $recorded_at
 * @property string|null $lat
 * @property string|null $lon
 * @property int|null $speed
 * @property string|null $direction
 * @property string|null $ignition
 * @property string|null $driver_name
 * @property string|null $vehicle_registration
 * @property string|null $mobile_phone
 * @property string|null $signal_strength_color
 * @property string|null $raw
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class DevicePositionRecord extends Model
{
    protected $guarded = [];

    public function getTable(): string
    {
        return 'velocity_fleet_device_positions';
    }

    public function getConnectionName(): ?string
    {
        $connection = config('velocity-fleet.history.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        $casts = [
            'recorded_at' => 'datetime',
        ];

        $encryptPii = config('velocity-fleet.history.encrypt_pii');

        if (! is_bool($encryptPii) || $encryptPii) {
            $casts['driver_name'] = 'encrypted';
            $casts['vehicle_registration'] = 'encrypted';
            $casts['mobile_phone'] = 'encrypted';
        }

        return $casts;
    }
}

<?php

declare(strict_types=1);

namespace ChrisJohnLeah\VelocityFleet\Laravel\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A durable record of a device's occupancy of a geofence. A row with a null
 * exited_at is an "open" event meaning the device is currently inside; it is
 * closed (exited_at + dwell_seconds set) when the device leaves.
 *
 * @property int $id
 * @property int $device_id
 * @property int $geofence_id
 * @property string|null $customer_id
 * @property Carbon $entered_at
 * @property Carbon|null $exited_at
 * @property int|null $dwell_seconds
 */
class GeofenceEvent extends Model
{
    protected $guarded = [];

    public function getTable(): string
    {
        $table = config('velocity-fleet.geofencing.events_table', 'velocity_fleet_geofence_events');

        return is_string($table) ? $table : 'velocity_fleet_geofence_events';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'device_id' => 'integer',
            'geofence_id' => 'integer',
            'entered_at' => 'datetime',
            'exited_at' => 'datetime',
            'dwell_seconds' => 'integer',
        ];
    }
}

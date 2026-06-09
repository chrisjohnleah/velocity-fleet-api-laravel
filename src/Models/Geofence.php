<?php

declare(strict_types=1);

namespace ChrisJohnLeah\VelocityFleet\Laravel\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A geofence definition: either a circle (centre + radius) or a polygon (list of
 * lat/lon vertices). Optionally scoped to a customer and/or a device group; a
 * null scope means it applies to every customer/device.
 *
 * @property int $id
 * @property string $name
 * @property string $type
 * @property float|null $center_lat
 * @property float|null $center_lon
 * @property float|null $radius_m
 * @property array<int, array<string, float>>|null $points
 * @property string|null $customer_id
 * @property int|null $device_group_id
 * @property bool $active
 */
class Geofence extends Model
{
    protected $guarded = [];

    public function getTable(): string
    {
        $table = config('velocity-fleet.geofencing.table', 'velocity_fleet_geofences');

        return is_string($table) ? $table : 'velocity_fleet_geofences';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'center_lat' => 'float',
            'center_lon' => 'float',
            'radius_m' => 'float',
            'points' => 'array',
            'device_group_id' => 'integer',
            'active' => 'boolean',
        ];
    }
}

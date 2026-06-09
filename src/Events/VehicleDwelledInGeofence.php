<?php

declare(strict_types=1);

namespace ChrisJohnLeah\VelocityFleet\Laravel\Events;

use Carbon\CarbonImmutable;
use ChrisJohnLeah\VelocityFleet\Data\Device;
use ChrisJohnLeah\VelocityFleet\Laravel\Models\Geofence;

/**
 * A device has remained inside a geofence beyond the configured dwell threshold
 * (velocity-fleet.geofencing.dwell_minutes). Fired once per occupancy.
 */
final class VehicleDwelledInGeofence
{
    public function __construct(
        public readonly string $customerId,
        public readonly Device $device,
        public readonly Geofence $geofence,
        public readonly CarbonImmutable $occurredAt,
    ) {
    }
}

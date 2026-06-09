<?php

declare(strict_types=1);

namespace ChrisJohnLeah\VelocityFleet\Laravel\Events;

use Carbon\CarbonImmutable;
use ChrisJohnLeah\VelocityFleet\Data\Device;
use ChrisJohnLeah\VelocityFleet\Laravel\Models\Geofence;

/** A device crossed out of a geofence it was previously inside. */
final class VehicleExitedGeofence
{
    public function __construct(
        public readonly string $customerId,
        public readonly Device $device,
        public readonly Geofence $geofence,
        public readonly CarbonImmutable $occurredAt,
    ) {
    }
}

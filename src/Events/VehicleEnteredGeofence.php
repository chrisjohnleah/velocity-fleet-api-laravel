<?php

declare(strict_types=1);

namespace ChrisJohnLeah\VelocityFleet\Laravel\Events;

use Carbon\CarbonImmutable;
use ChrisJohnLeah\VelocityFleet\Data\Device;
use ChrisJohnLeah\VelocityFleet\Laravel\Models\Geofence;

/** A device crossed into a geofence it was previously outside of. */
final class VehicleEnteredGeofence
{
    public function __construct(
        public readonly string $customerId,
        public readonly Device $device,
        public readonly Geofence $geofence,
        public readonly CarbonImmutable $occurredAt,
    ) {
    }
}

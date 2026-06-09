<?php

declare(strict_types=1);

namespace ChrisJohnLeah\VelocityFleet\Laravel\Events;

use Carbon\CarbonImmutable;
use ChrisJohnLeah\VelocityFleet\Data\Device;
use ChrisJohnLeah\VelocityFleet\Laravel\Models\Geofence;

/**
 * A device arrived at a geofence — a semantic alias fired alongside
 * {@see VehicleEnteredGeofence} on entry, for "arrival at depot/destination"
 * style listeners that don't care about raw boundary crossings.
 */
final class VehicleArrived
{
    public function __construct(
        public readonly string $customerId,
        public readonly Device $device,
        public readonly Geofence $geofence,
        public readonly CarbonImmutable $occurredAt,
    ) {
    }
}

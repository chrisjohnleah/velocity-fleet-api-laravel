<?php

declare(strict_types=1);

namespace ChrisJohnLeah\VelocityFleet\Laravel\Listeners;

use ChrisJohnLeah\VelocityFleet\Laravel\Events\DevicePositionsUpdated;
use ChrisJohnLeah\VelocityFleet\Laravel\Geofencing\GeofenceMatcher;

/**
 * Bridges the poll's {@see DevicePositionsUpdated} event into the geofencing
 * engine. A no-op unless velocity-fleet.geofencing.enabled is true.
 */
final class MatchGeofences
{
    public function __construct(
        private readonly GeofenceMatcher $matcher,
    ) {
    }

    public function handle(DevicePositionsUpdated $event): void
    {
        if (! $this->enabled()) {
            return;
        }

        $this->matcher->match($event->customerId, $event->positions->devices);
    }

    private function enabled(): bool
    {
        return config('velocity-fleet.geofencing.enabled') === true;
    }
}

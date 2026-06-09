<?php

declare(strict_types=1);

namespace ChrisJohnLeah\VelocityFleet\Laravel\Events;

use Carbon\CarbonImmutable;
use ChrisJohnLeah\VelocityFleet\Data\DevicePositions;

/**
 * The umbrella event fired once per poll: carries the full positions payload and
 * the list of per-device change events ({@see DeviceEvent}) detected this tick.
 */
final class DevicePositionsUpdated
{
    /**
     * @param  list<DeviceEvent>  $changes
     */
    public function __construct(
        public readonly string $customerId,
        public readonly DevicePositions $positions,
        public readonly array $changes,
        public readonly CarbonImmutable $occurredAt,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace ChrisJohnLeah\VelocityFleet\Laravel\Events;

use Carbon\CarbonImmutable;
use ChrisJohnLeah\VelocityFleet\Data\Device;

/**
 * Base for per-device change-detection events fired by the poller. Carries the
 * device, the customer it belongs to, and when the change was detected.
 */
abstract class DeviceEvent
{
    public function __construct(
        public readonly string $customerId,
        public readonly Device $device,
        public readonly CarbonImmutable $occurredAt,
    ) {
    }
}

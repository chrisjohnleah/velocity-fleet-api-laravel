<?php

declare(strict_types=1);

namespace ChrisJohnLeah\VelocityFleet\Laravel\Events;

use Carbon\CarbonImmutable;

/** A scheduled poll was skipped (e.g. served from a fresh cache window). */
final class PollSkipped
{
    public function __construct(
        public readonly string $customerId,
        public readonly string $reason,
        public readonly CarbonImmutable $occurredAt,
    ) {
    }
}

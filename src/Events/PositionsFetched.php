<?php

declare(strict_types=1);

namespace ChrisJohnLeah\VelocityFleet\Laravel\Events;

use Carbon\CarbonImmutable;

/** Positions were fetched for a customer (from the API or the cache). */
final class PositionsFetched
{
    public function __construct(
        public readonly string $customerId,
        public readonly int $deviceCount,
        public readonly bool $fromCache,
        public readonly CarbonImmutable $occurredAt,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace ChrisJohnLeah\VelocityFleet\Laravel\Events;

use Carbon\CarbonImmutable;

/** Old position-history rows were pruned by the retention job. */
final class PositionHistoryPruned
{
    public function __construct(
        public readonly int $deleted,
        public readonly CarbonImmutable $occurredAt,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace ChrisJohnLeah\VelocityFleet\Laravel\Contracts;

use ChrisJohnLeah\VelocityFleet\Laravel\ValueObjects\PositionsSnapshot;

/**
 * Stores the most recent positions snapshot per customer so the poller can diff
 * the current fetch against it to detect changes.
 */
interface FleetSnapshotStore
{
    public function previous(string $customerId): ?PositionsSnapshot;

    public function put(string $customerId, PositionsSnapshot $snapshot): void;
}

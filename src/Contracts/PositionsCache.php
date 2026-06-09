<?php

declare(strict_types=1);

namespace ChrisJohnLeah\VelocityFleet\Laravel\Contracts;

use ChrisJohnLeah\VelocityFleet\Data\DevicePositions;
use ChrisJohnLeah\VelocityFleet\Laravel\ValueObjects\PositionsSnapshot;

/**
 * A refresh-rate-aware cache for a customer's device positions.
 */
interface PositionsCache
{
    public function positions(string $customerId): DevicePositions;

    public function snapshot(string $customerId): PositionsSnapshot;

    public function forget(string $customerId): void;
}

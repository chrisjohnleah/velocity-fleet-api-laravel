<?php

declare(strict_types=1);

namespace ChrisJohnLeah\VelocityFleet\Laravel\ValueObjects;

use Carbon\CarbonImmutable;
use ChrisJohnLeah\VelocityFleet\Data\DevicePositions;

/**
 * A point-in-time capture of a customer's device positions, with the moment it
 * was fetched and the TTL it was cached for. Used by the cache (freshness) and
 * the poller (diffing the current fetch against the previous snapshot).
 */
final readonly class PositionsSnapshot
{
    public function __construct(
        public DevicePositions $positions,
        public CarbonImmutable $fetchedAt,
        public int $ttl,
    ) {
    }

    public function expiresAt(): CarbonImmutable
    {
        return $this->fetchedAt->addSeconds($this->ttl);
    }

    public function isStale(?CarbonImmutable $now = null): bool
    {
        return ($now ?? CarbonImmutable::now())->greaterThanOrEqualTo($this->expiresAt());
    }

    public function ageInSeconds(?CarbonImmutable $now = null): int
    {
        return (int) $this->fetchedAt->diffInSeconds($now ?? CarbonImmutable::now(), true);
    }
}

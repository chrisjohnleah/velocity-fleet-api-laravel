<?php

declare(strict_types=1);

namespace ChrisJohnLeah\VelocityFleet\Laravel\Support;

use ChrisJohnLeah\VelocityFleet\Data\DevicePositions;

/**
 * Decides the cache TTL / poll interval for a customer's positions from the
 * API's own live-map refresh hints: the normal rate for small fleets and the
 * large-fleet rate once a fleet exceeds the size threshold. The result is always
 * clamped to at least {@see $minTtlSeconds} so configuration can never poll
 * faster than the provider recommends.
 */
final class RefreshRateResolver
{
    private const DEFAULT_NORMAL_MS = 30_000;

    private const DEFAULT_LARGE_MS = 90_000;

    public function __construct(
        private readonly int $minTtlSeconds = 25,
        private readonly int $largeFleetThreshold = 100,
    ) {
    }

    public function ttlFor(DevicePositions $positions): int
    {
        $count = $positions->deviceCount ?? count($positions->devices);

        return $this->ttlForCount($count, $positions->liveMapRefreshRate, $positions->liveMapLargeFleetRefreshRate);
    }

    public function ttlForCount(int $deviceCount, ?int $normalRateMs, ?int $largeRateMs): int
    {
        $normal = $normalRateMs ?? self::DEFAULT_NORMAL_MS;
        $large = $largeRateMs ?? self::DEFAULT_LARGE_MS;

        $rateMs = $deviceCount >= $this->largeFleetThreshold ? $large : $normal;

        $seconds = (int) ceil($rateMs / 1000);

        return max($this->minTtlSeconds, $seconds);
    }
}

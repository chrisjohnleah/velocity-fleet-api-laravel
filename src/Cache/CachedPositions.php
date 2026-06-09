<?php

declare(strict_types=1);

namespace ChrisJohnLeah\VelocityFleet\Laravel\Cache;

use Carbon\CarbonImmutable;
use ChrisJohnLeah\VelocityFleet\Data\DevicePositions;
use ChrisJohnLeah\VelocityFleet\Laravel\Contracts\MetricsRecorder;
use ChrisJohnLeah\VelocityFleet\Laravel\Contracts\PositionsCache;
use ChrisJohnLeah\VelocityFleet\Laravel\Support\RefreshRateResolver;
use ChrisJohnLeah\VelocityFleet\Laravel\ValueObjects\PositionsSnapshot;
use ChrisJohnLeah\VelocityFleet\VelocityFleet;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Refresh-rate-aware cache for device positions with stale-while-revalidate and
 * single-flight refresh. Concurrent callers/workers collapse to one upstream POST
 * per refresh window: the lock holder fetches while everyone else is served the
 * stale snapshot. The TTL comes from the API's own live-map hints (clamped).
 *
 * Single-flight needs a shared atomic lock store (redis/database/memcached). With
 * a store that has no lock support, this degrades safely to a per-caller refresh.
 */
final class CachedPositions implements PositionsCache
{
    public function __construct(
        private readonly VelocityFleet $client,
        private readonly CacheRepository $cache,
        private readonly RefreshRateResolver $resolver,
        private readonly MetricsRecorder $metrics,
        private readonly string $prefix = 'velocity_fleet',
        private readonly int $lockTtl = 45,
        private readonly int $blockSeconds = 10,
    ) {
    }

    public function positions(string $customerId): DevicePositions
    {
        return $this->snapshot($customerId)->positions;
    }

    public function snapshot(string $customerId): PositionsSnapshot
    {
        $existing = $this->read($customerId);

        if ($existing !== null && ! $existing->isStale()) {
            $this->metrics->increment('positions.cache_hit');

            return $existing;
        }

        $lock = $this->lock($customerId);

        // No atomic-lock support (e.g. the array/file cache store): we cannot
        // single-flight, so degrade to a direct refresh.
        if ($lock === null) {
            return $this->refresh($customerId);
        }

        // Try to become the single flight without waiting.
        if ($lock->get()) {
            try {
                // Re-check under the lock — another worker may have just refreshed.
                $fresh = $this->read($customerId);

                if ($fresh !== null && ! $fresh->isStale()) {
                    return $fresh;
                }

                return $this->refresh($customerId);
            } finally {
                $lock->release();
            }
        }

        // Another worker holds the lock. Serve the stale copy immediately (SWR).
        if ($existing !== null) {
            $this->metrics->increment('positions.served_stale');

            return $existing;
        }

        // Cold start with no stale copy: wait for the holder, then refresh inside
        // the critical section. block() ACQUIRES the lock for the callback (and
        // releases it after), so the wait-then-refresh is genuinely single-flight
        // — not a bare acquire/release that would let the herd through.
        try {
            $result = $lock->block(
                $this->blockSeconds,
                function () use ($customerId): PositionsSnapshot {
                    $fresh = $this->read($customerId);

                    if ($fresh !== null && ! $fresh->isStale()) {
                        return $fresh;
                    }

                    return $this->refresh($customerId);
                },
            );

            return $result instanceof PositionsSnapshot ? $result : $this->refresh($customerId);
        } catch (LockTimeoutException) {
            // Holder took longer than the wait budget — fetch ourselves rather
            // than block the request indefinitely.
            return $this->read($customerId) ?? $this->refresh($customerId);
        }
    }

    public function forget(string $customerId): void
    {
        $this->cache->forget($this->key($customerId));
    }

    private function refresh(string $customerId): PositionsSnapshot
    {
        $positions = $this->client->devicePositions()->forCustomer($customerId);
        $ttl = $this->resolver->ttlFor($positions);
        $snapshot = new PositionsSnapshot($positions, CarbonImmutable::now(), $ttl);

        // Retain the stale copy well past the fresh window so SWR can serve it.
        $this->cache->put($this->key($customerId), $snapshot, $ttl * 10);
        $this->metrics->increment('positions.fetched');

        return $snapshot;
    }

    private function read(string $customerId): ?PositionsSnapshot
    {
        $value = $this->cache->get($this->key($customerId));

        return $value instanceof PositionsSnapshot ? $value : null;
    }

    private function lock(string $customerId): ?Lock
    {
        $store = $this->cache->getStore();

        if ($store instanceof LockProvider) {
            return $store->lock($this->key($customerId).':lock', $this->lockTtl);
        }

        return null;
    }

    private function key(string $customerId): string
    {
        return $this->prefix.':positions:'.$customerId;
    }
}

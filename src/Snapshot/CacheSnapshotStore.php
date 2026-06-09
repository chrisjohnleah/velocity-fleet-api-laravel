<?php

declare(strict_types=1);

namespace ChrisJohnLeah\VelocityFleet\Laravel\Snapshot;

use ChrisJohnLeah\VelocityFleet\Laravel\Contracts\FleetSnapshotStore;
use ChrisJohnLeah\VelocityFleet\Laravel\ValueObjects\PositionsSnapshot;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Stores the previous {@see PositionsSnapshot} per customer in the Laravel cache
 * so the poller can diff the current fetch against it. Keyed by customer id and
 * held for a long TTL: the snapshot is poll-state, not a freshness window, so it
 * must outlive any single poll interval (otherwise every poll after a gap would
 * re-seed silently and miss the change that happened across the gap).
 */
final class CacheSnapshotStore implements FleetSnapshotStore
{
    public function __construct(
        private readonly CacheRepository $cache,
        private readonly string $prefix = 'velocity_fleet',
        private readonly int $ttlSeconds = 604_800,
    ) {
    }

    public function previous(string $customerId): ?PositionsSnapshot
    {
        $value = $this->cache->get($this->key($customerId));

        return $value instanceof PositionsSnapshot ? $value : null;
    }

    public function put(string $customerId, PositionsSnapshot $snapshot): void
    {
        $this->cache->put($this->key($customerId), $snapshot, $this->ttlSeconds);
    }

    private function key(string $customerId): string
    {
        return $this->prefix.':snapshot:'.$customerId;
    }
}

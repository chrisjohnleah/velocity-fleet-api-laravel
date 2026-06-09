<?php

declare(strict_types=1);

namespace ChrisJohnLeah\VelocityFleet\Laravel\Polling;

use Carbon\CarbonImmutable;
use ChrisJohnLeah\VelocityFleet\Data\Device;
use ChrisJohnLeah\VelocityFleet\Data\DevicePositions;
use ChrisJohnLeah\VelocityFleet\Laravel\Contracts\FleetSnapshotStore;
use ChrisJohnLeah\VelocityFleet\Laravel\Contracts\MetricsRecorder;
use ChrisJohnLeah\VelocityFleet\Laravel\Contracts\PositionsCache;
use ChrisJohnLeah\VelocityFleet\Laravel\Events\DeviceCameBackOnline;
use ChrisJohnLeah\VelocityFleet\Laravel\Events\DeviceEvent;
use ChrisJohnLeah\VelocityFleet\Laravel\Events\DevicePositionsUpdated;
use ChrisJohnLeah\VelocityFleet\Laravel\Events\DeviceWentStale;
use ChrisJohnLeah\VelocityFleet\Laravel\Events\IgnitionTurnedOff;
use ChrisJohnLeah\VelocityFleet\Laravel\Events\IgnitionTurnedOn;
use ChrisJohnLeah\VelocityFleet\Laravel\Events\VehicleStartedMoving;
use ChrisJohnLeah\VelocityFleet\Laravel\Events\VehicleStopped;
use ChrisJohnLeah\VelocityFleet\Laravel\ValueObjects\PositionsSnapshot;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Polls a customer's device positions through the {@see PositionsCache} and
 * diffs each device against the previous {@see PositionsSnapshot} to detect
 * state transitions, firing a {@see DeviceEvent} per change and one umbrella
 * {@see DevicePositionsUpdated} per poll. The new snapshot is persisted to the
 * {@see FleetSnapshotStore} for next time.
 *
 * The very first poll for a customer (no previous snapshot) seeds state silently
 * — it persists the snapshot but fires no per-device events, so a cold start
 * never emits a flood of spurious "turned on / started moving" transitions.
 */
final class FleetPoller
{
    public function __construct(
        private readonly PositionsCache $cache,
        private readonly FleetSnapshotStore $snapshots,
        private readonly MetricsRecorder $metrics,
        private readonly Dispatcher $events,
    ) {
    }

    public function poll(string $customerId): void
    {
        $now = CarbonImmutable::now();

        $this->metrics->increment('poll.run', 1, ['customer' => $customerId]);

        $positions = $this->cache->positions($customerId);
        $previous = $this->snapshots->previous($customerId);
        $snapshot = new PositionsSnapshot($positions, $now, $this->snapshotTtl($positions));

        // First poll: seed silently so a cold start never floods spurious events.
        if ($previous === null) {
            $this->snapshots->put($customerId, $snapshot);
            $this->events->dispatch(new DevicePositionsUpdated($customerId, $positions, [], $now));

            return;
        }

        $changes = $this->diff($customerId, $previous, $positions, $now);

        foreach ($changes as $change) {
            $this->events->dispatch($change);
        }

        $this->metrics->increment('poll.changes', count($changes), ['customer' => $customerId]);

        $this->snapshots->put($customerId, $snapshot);
        $this->events->dispatch(new DevicePositionsUpdated($customerId, $positions, $changes, $now));
    }

    /**
     * @return list<DeviceEvent>
     */
    private function diff(
        string $customerId,
        PositionsSnapshot $previous,
        DevicePositions $current,
        CarbonImmutable $now,
    ): array {
        $staleAfter = $this->staleAfterSeconds();
        $previousAt = $previous->fetchedAt;
        $before = $this->index($previous->positions->devices);

        $changes = [];
        $seen = [];

        foreach ($current->devices as $device) {
            $key = $this->deviceKey($device);

            if ($key === null) {
                continue;
            }

            $seen[$key] = true;
            $prior = $before[$key] ?? null;

            foreach ($this->deviceChanges($customerId, $prior, $device, $staleAfter, $now, $previousAt) as $change) {
                $changes[] = $change;
            }
        }

        // Disappeared devices: in the previous feed, gone now. Report as going
        // stale, but only if they were still fresh when last seen (a device that
        // was already stale was reported by freshnessChange on a prior poll).
        foreach ($before as $key => $device) {
            if (isset($seen[$key])) {
                continue;
            }

            if (! $this->isStale($device, $staleAfter, $previousAt)) {
                $changes[] = new DeviceWentStale($customerId, $device, $now);
            }
        }

        return $changes;
    }

    /**
     * @return list<DeviceEvent>
     */
    private function deviceChanges(
        string $customerId,
        ?Device $previous,
        Device $current,
        int $staleAfter,
        CarbonImmutable $now,
        CarbonImmutable $previousAt,
    ): array {
        // A device seen for the first time has no prior state to transition from,
        // so it is seeded silently — no spurious "came back online" on appearance.
        if ($previous === null) {
            return [];
        }

        $changes = [];

        if ($event = $this->ignitionChange($customerId, $previous, $current, $now)) {
            $changes[] = $event;
        }

        if ($event = $this->movementChange($customerId, $previous, $current, $now)) {
            $changes[] = $event;
        }

        if ($event = $this->freshnessChange($customerId, $previous, $current, $staleAfter, $now, $previousAt)) {
            $changes[] = $event;
        }

        return $changes;
    }

    private function ignitionChange(
        string $customerId,
        Device $previous,
        Device $current,
        CarbonImmutable $now,
    ): ?DeviceEvent {
        $was = $previous->ignitionOn();
        $is = $current->ignitionOn();

        if ($was === false && $is === true) {
            return new IgnitionTurnedOn($customerId, $current, $now);
        }

        if ($was === true && $is === false) {
            return new IgnitionTurnedOff($customerId, $current, $now);
        }

        return null;
    }

    private function movementChange(
        string $customerId,
        Device $previous,
        Device $current,
        CarbonImmutable $now,
    ): ?DeviceEvent {
        // Unknown speed on either side — suppress, so a single missing reading
        // doesn't fabricate a stop/start (mirrors the ignition tri-state guard).
        if ($previous->speed === null || $current->speed === null) {
            return null;
        }

        $wasMoving = $previous->speed > 0;
        $isMoving = $current->speed > 0;

        if (! $wasMoving && $isMoving) {
            return new VehicleStartedMoving($customerId, $current, $now);
        }

        if ($wasMoving && ! $isMoving) {
            return new VehicleStopped($customerId, $current, $now);
        }

        return null;
    }

    private function freshnessChange(
        string $customerId,
        Device $previous,
        Device $current,
        int $staleAfter,
        CarbonImmutable $now,
        CarbonImmutable $previousAt,
    ): ?DeviceEvent {
        // Judge the previous reading as of when it was fetched, not "now": a
        // genuinely offline device keeps returning the same frozen timestamp, so
        // comparing both readings against "now" would never detect the transition.
        $wasStale = $this->isStale($previous, $staleAfter, $previousAt);
        $currentStale = $this->isStale($current, $staleAfter, $now);

        if (! $wasStale && $currentStale) {
            return new DeviceWentStale($customerId, $current, $now);
        }

        if ($wasStale && ! $currentStale) {
            return new DeviceCameBackOnline($customerId, $current, $now);
        }

        return null;
    }

    /**
     * A device is stale when its last reported position is older than the
     * configured window. A device that reports no timestamp at all is treated as
     * stale (we cannot confirm it is fresh).
     */
    private function isStale(Device $device, int $staleAfter, CarbonImmutable $now): bool
    {
        $at = $device->occurredAt();

        if ($at === null) {
            return true;
        }

        return $at->getTimestamp() < $now->getTimestamp() - $staleAfter;
    }

    /**
     * Stable identity for a device across polls: the numeric id, falling back to
     * the service id when present.
     */
    private function deviceKey(Device $device): ?string
    {
        if ($device->id !== null) {
            return 'id:'.$device->id;
        }

        if ($device->serviceId !== null && $device->serviceId !== '') {
            return 'svc:'.$device->serviceId;
        }

        return null;
    }

    /**
     * @param  list<Device>  $devices
     * @return array<string, Device>
     */
    private function index(array $devices): array
    {
        $indexed = [];

        foreach ($devices as $device) {
            $key = $this->deviceKey($device);

            if ($key !== null) {
                $indexed[$key] = $device;
            }
        }

        return $indexed;
    }

    private function snapshotTtl(DevicePositions $positions): int
    {
        $rate = $positions->liveMapRefreshRate;

        if ($rate === null || $rate <= 0) {
            return $this->staleAfterSeconds();
        }

        return (int) ceil($rate / 1000);
    }

    private function staleAfterSeconds(): int
    {
        $value = config('velocity-fleet.stale_after_seconds', 900);

        return is_numeric($value) ? (int) $value : 900;
    }
}

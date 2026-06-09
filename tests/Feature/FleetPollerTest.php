<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use ChrisJohnLeah\VelocityFleet\Data\DevicePositions;
use ChrisJohnLeah\VelocityFleet\Laravel\Contracts\FleetSnapshotStore;
use ChrisJohnLeah\VelocityFleet\Laravel\Contracts\MetricsRecorder;
use ChrisJohnLeah\VelocityFleet\Laravel\Contracts\PositionsCache;
use ChrisJohnLeah\VelocityFleet\Laravel\Events\DeviceCameBackOnline;
use ChrisJohnLeah\VelocityFleet\Laravel\Events\DevicePositionsUpdated;
use ChrisJohnLeah\VelocityFleet\Laravel\Events\DeviceWentStale;
use ChrisJohnLeah\VelocityFleet\Laravel\Events\IgnitionTurnedOff;
use ChrisJohnLeah\VelocityFleet\Laravel\Events\IgnitionTurnedOn;
use ChrisJohnLeah\VelocityFleet\Laravel\Events\VehicleStartedMoving;
use ChrisJohnLeah\VelocityFleet\Laravel\Events\VehicleStopped;
use ChrisJohnLeah\VelocityFleet\Laravel\Polling\FleetPoller;
use ChrisJohnLeah\VelocityFleet\Laravel\Snapshot\CacheSnapshotStore;
use ChrisJohnLeah\VelocityFleet\Laravel\ValueObjects\PositionsSnapshot;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Event;

/**
 * Build a DevicePositions payload. Each device entry is keyed by id and carries a
 * speed, ignition flag, and a timestamp expressed as seconds-ago-from-now so we
 * can stage fresh and stale devices deterministically.
 *
 * @param  list<array{id:int, speed:int, ignition:string, ago:int}>  $devices
 */
function pollerPositions(array $devices): DevicePositions
{
    $now = time();

    return DevicePositions::fromArray([
        'device_count' => count($devices),
        'KINESIS_LIVE_MAP_REFRESH_RATE' => 30000,
        'devices' => array_map(static fn (array $d): array => [
            'id' => $d['id'],
            'speed' => $d['speed'],
            'ignition' => $d['ignition'],
            'timestamp' => $now - $d['ago'],
        ], $devices),
    ]);
}

/**
 * A PositionsCache test double that serves a queued sequence of payloads — one
 * dequeued per positions() call — so successive polls see different state without
 * any network or real cache.
 */
function sequencedCache(DevicePositions ...$sequence): PositionsCache
{
    return new class ($sequence) implements PositionsCache {
        /** @param list<DevicePositions> $sequence */
        public function __construct(
            /** @var list<DevicePositions> */
            private array $sequence,
        ) {
        }

        public function positions(string $customerId): DevicePositions
        {
            return array_shift($this->sequence) ?? new DevicePositions();
        }

        public function snapshot(string $customerId): PositionsSnapshot
        {
            return new PositionsSnapshot($this->positions($customerId), CarbonImmutable::now(), 30);
        }

        public function forget(string $customerId): void
        {
        }
    };
}

function pollerFor(PositionsCache $cache, FleetSnapshotStore $store): FleetPoller
{
    return new FleetPoller(
        $cache,
        $store,
        app(MetricsRecorder::class),
        app(Dispatcher::class),
    );
}

beforeEach(function () {
    config()->set('velocity-fleet.stale_after_seconds', 900);
});

it('seeds silently on the first poll — no per-device events fire', function () {
    Event::fake();

    $store = app(CacheSnapshotStore::class);
    $cache = sequencedCache(pollerPositions([
        ['id' => 1, 'speed' => 0, 'ignition' => 'N', 'ago' => 10],
    ]));

    pollerFor($cache, $store)->poll('cust-1');

    // First poll seeds state: the umbrella event fires with an empty change set,
    // but no transition events at all.
    Event::assertDispatched(DevicePositionsUpdated::class, function (DevicePositionsUpdated $event): bool {
        return $event->customerId === 'cust-1' && $event->changes === [];
    });
    Event::assertNotDispatched(IgnitionTurnedOn::class);
    Event::assertNotDispatched(VehicleStartedMoving::class);
    Event::assertNotDispatched(DeviceCameBackOnline::class);

    // State was persisted for the next poll to diff against.
    expect($store->previous('cust-1'))->toBeInstanceOf(PositionsSnapshot::class);
});

it('fires ignition and movement transitions on the second poll', function () {
    Event::fake();

    $store = app(CacheSnapshotStore::class);
    $cache = sequencedCache(
        // Poll 1 (seed): device 1 off+stopped, device 2 on+moving.
        pollerPositions([
            ['id' => 1, 'speed' => 0, 'ignition' => 'N', 'ago' => 10],
            ['id' => 2, 'speed' => 40, 'ignition' => 'Y', 'ago' => 10],
        ]),
        // Poll 2: device 1 turns on and starts moving; device 2 stops and turns off.
        pollerPositions([
            ['id' => 1, 'speed' => 25, 'ignition' => 'Y', 'ago' => 10],
            ['id' => 2, 'speed' => 0, 'ignition' => 'N', 'ago' => 10],
        ]),
    );

    $poller = pollerFor($cache, $store);
    $poller->poll('cust-2'); // seed
    $poller->poll('cust-2'); // diff

    Event::assertDispatched(IgnitionTurnedOn::class, fn (IgnitionTurnedOn $e): bool => $e->device->id === 1);
    Event::assertDispatched(VehicleStartedMoving::class, fn (VehicleStartedMoving $e): bool => $e->device->id === 1);
    Event::assertDispatched(IgnitionTurnedOff::class, fn (IgnitionTurnedOff $e): bool => $e->device->id === 2);
    Event::assertDispatched(VehicleStopped::class, fn (VehicleStopped $e): bool => $e->device->id === 2);

    // The umbrella event for the second poll carries all four changes.
    Event::assertDispatched(DevicePositionsUpdated::class, function (DevicePositionsUpdated $event): bool {
        return $event->customerId === 'cust-2' && count($event->changes) === 4;
    });
});

it('fires staleness transitions when a device crosses the freshness window', function () {
    Event::fake();

    $store = app(CacheSnapshotStore::class);
    $cache = sequencedCache(
        // Poll 1 (seed): fresh device.
        pollerPositions([
            ['id' => 7, 'speed' => 0, 'ignition' => 'N', 'ago' => 10],
        ]),
        // Poll 2: same device, last report now older than the 900s window.
        pollerPositions([
            ['id' => 7, 'speed' => 0, 'ignition' => 'N', 'ago' => 1200],
        ]),
        // Poll 3: device reports fresh again.
        pollerPositions([
            ['id' => 7, 'speed' => 0, 'ignition' => 'N', 'ago' => 5],
        ]),
    );

    $poller = pollerFor($cache, $store);
    $poller->poll('cust-3'); // seed (fresh)
    $poller->poll('cust-3'); // went stale

    Event::assertDispatched(DeviceWentStale::class, fn (DeviceWentStale $e): bool => $e->device->id === 7);

    $poller->poll('cust-3'); // came back online

    Event::assertDispatched(DeviceCameBackOnline::class, fn (DeviceCameBackOnline $e): bool => $e->device->id === 7);
});

it('resolves a FleetPoller wired with the bound snapshot store', function () {
    // Once the provider binds FleetSnapshotStore -> CacheSnapshotStore, the poller
    // resolves with all real collaborators. We bind it here to mirror that wiring.
    app()->singleton(FleetSnapshotStore::class, CacheSnapshotStore::class);

    expect(app(FleetPoller::class))->toBeInstanceOf(FleetPoller::class)
        ->and(app(FleetSnapshotStore::class))->toBeInstanceOf(CacheSnapshotStore::class);
});

it('detects a device going offline when it freezes (same timestamp, wall-clock crosses the window)', function () {
    Event::fake();

    // The real-world offline case: the API keeps returning the SAME last-known
    // timestamp while wall-clock time marches past the staleness window.
    $frozenTs = 1_700_000_000;
    $payload = DevicePositions::fromArray([
        'device_count' => 1,
        'KINESIS_LIVE_MAP_REFRESH_RATE' => 30000,
        'devices' => [['id' => 9, 'speed' => 0, 'ignition' => 'N', 'timestamp' => $frozenTs]],
    ]);

    $store = app(CacheSnapshotStore::class);
    $poller = pollerFor(sequencedCache($payload, $payload), $store);

    CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestamp($frozenTs + 1));
    $poller->poll('cust-off'); // seed while still fresh

    CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestamp($frozenTs + 1000));
    $poller->poll('cust-off'); // 1000s later, same frozen reading

    Event::assertDispatched(DeviceWentStale::class, fn (DeviceWentStale $e): bool => $e->device->id === 9);

    CarbonImmutable::setTestNow();
});

it('does not fire spurious movement events when a speed reading drops out', function () {
    Event::fake();

    $store = app(CacheSnapshotStore::class);
    $now = time();
    $moving = DevicePositions::fromArray([
        'device_count' => 1,
        'KINESIS_LIVE_MAP_REFRESH_RATE' => 30000,
        'devices' => [['id' => 3, 'speed' => 40, 'ignition' => 'Y', 'timestamp' => $now]],
    ]);
    $noSpeed = DevicePositions::fromArray([
        'device_count' => 1,
        'KINESIS_LIVE_MAP_REFRESH_RATE' => 30000,
        'devices' => [['id' => 3, 'ignition' => 'Y', 'timestamp' => $now]], // speed absent -> null
    ]);

    $poller = pollerFor(sequencedCache($moving, $noSpeed), $store);
    $poller->poll('cust-sp'); // seed (moving)
    $poller->poll('cust-sp'); // speed dropped to null

    Event::assertNotDispatched(VehicleStopped::class);
    Event::assertNotDispatched(VehicleStartedMoving::class);
});

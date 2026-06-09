<?php

declare(strict_types=1);

use ChrisJohnLeah\VelocityFleet\Auth\StoredToken;
use ChrisJohnLeah\VelocityFleet\Contracts\TokenStore;
use ChrisJohnLeah\VelocityFleet\Laravel\Facades\VelocityFleet;
use ChrisJohnLeah\VelocityFleet\Laravel\FleetManager;
use ChrisJohnLeah\VelocityFleet\Laravel\Support\DeviceCollection;
use ChrisJohnLeah\VelocityFleet\Requests\DevicePositions\GetDevicePositions;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

function fleetPositionsBody(): array
{
    return [
        'device_count' => 2,
        'KINESIS_LIVE_MAP_REFRESH_RATE' => 30000,
        'KINESIS_LIVE_MAP_LARGE_FLEET_REFRESH_RATE' => 90000,
        'devices' => [
            ['id' => 1, 'speed' => 30, 'ignition' => 'Y', 'vehicle_registration' => 'AB12 ABC'],
            ['id' => 2, 'speed' => 0, 'ignition' => 'N', 'vehicle_registration' => 'XY99 XYZ'],
        ],
    ];
}

beforeEach(function () {
    app(TokenStore::class)->put(new StoredToken('test-token'));
});

afterEach(function () {
    MockClient::destroyGlobal();
});

it('queries live devices via fleet() with collection scopes', function () {
    FleetManager::fake([
        GetDevicePositions::class => MockResponse::make(fleetPositionsBody()),
    ]);

    $moving = VelocityFleet::fleet('11111')->moving();

    expect($moving)->toBeInstanceOf(DeviceCollection::class)
        ->and($moving->pluck('id')->all())->toBe([1]);
});

it('serves a second cached read from cache — one upstream call per window', function () {
    $mock = FleetManager::fake([
        GetDevicePositions::class => MockResponse::make(fleetPositionsBody()),
    ]);

    $first = VelocityFleet::cached()->positions('11111');
    $second = VelocityFleet::cached()->positions('11111');

    expect($first->deviceCount)->toBe(2)
        ->and($second->deviceCount)->toBe(2);

    // The second read hit the cache (fresh within TTL), so only one POST went out.
    $mock->assertSentCount(1);
});

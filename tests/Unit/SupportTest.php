<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use ChrisJohnLeah\VelocityFleet\Data\DevicePositions;
use ChrisJohnLeah\VelocityFleet\Laravel\Support\Haversine;
use ChrisJohnLeah\VelocityFleet\Laravel\Support\RefreshRateResolver;
use ChrisJohnLeah\VelocityFleet\Laravel\ValueObjects\PositionsSnapshot;

it('computes haversine distance in km', function () {
    // Birmingham -> London is ~163 km.
    expect(Haversine::km(52.4862, -1.8904, 51.5074, -0.1278))
        ->toBeGreaterThan(150.0)
        ->toBeLessThan(180.0)
        ->and(Haversine::km(52.0, -1.0, 52.0, -1.0))->toBe(0.0);
});

it('resolves the TTL from the API refresh-rate hints, clamped to the minimum', function () {
    $resolver = new RefreshRateResolver(minTtlSeconds: 25, largeFleetThreshold: 100);

    $small = DevicePositions::fromArray([
        'device_count' => 10,
        'KINESIS_LIVE_MAP_REFRESH_RATE' => 30000,
        'KINESIS_LIVE_MAP_LARGE_FLEET_REFRESH_RATE' => 90000,
    ]);
    $large = DevicePositions::fromArray([
        'device_count' => 250,
        'KINESIS_LIVE_MAP_REFRESH_RATE' => 30000,
        'KINESIS_LIVE_MAP_LARGE_FLEET_REFRESH_RATE' => 90000,
    ]);

    expect($resolver->ttlFor($small))->toBe(30)
        ->and($resolver->ttlFor($large))->toBe(90);

    // A higher min clamps the small-fleet 30s up to 60s.
    $clamped = new RefreshRateResolver(minTtlSeconds: 60, largeFleetThreshold: 100);
    expect($clamped->ttlFor($small))->toBe(60);
});

it('detects snapshot staleness against the TTL window', function () {
    $now = CarbonImmutable::now();
    $snapshot = new PositionsSnapshot(DevicePositions::fromArray([]), $now, 30);

    expect($snapshot->isStale($now->addSeconds(10)))->toBeFalse()
        ->and($snapshot->isStale($now->addSeconds(31)))->toBeTrue();
});

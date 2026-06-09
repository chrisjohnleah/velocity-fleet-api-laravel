<?php

declare(strict_types=1);

use ChrisJohnLeah\VelocityFleet\Data\Device;
use ChrisJohnLeah\VelocityFleet\Data\DevicePositions;
use ChrisJohnLeah\VelocityFleet\Laravel\Testing\FakeDevice;
use ChrisJohnLeah\VelocityFleet\Laravel\Testing\FakeDevicePositions;
use ChrisJohnLeah\VelocityFleet\Laravel\Testing\FleetScenario;

it('builds a fully-typed Device with sane defaults from FakeDevice::make', function () {
    $device = FakeDevice::make();

    expect($device)->toBeInstanceOf(Device::class)
        ->and($device->id)->toBe(1)
        ->and($device->lat)->toBeFloat()
        ->and($device->lon)->toBeFloat()
        ->and($device->ignition)->toBe('Y')
        ->and($device->ignitionOn())->toBeTrue()
        ->and($device->vehicleRegistration)->toBe('AB12 ABC')
        ->and($device->speed)->toBe(32)
        ->and($device->occurredAt())->toBeInstanceOf(DateTimeImmutable::class);
});

it('merges snake_case overrides over FakeDevice defaults', function () {
    $device = FakeDevice::make([
        'id' => 99,
        'ignition' => 'N',
        'speed' => 0,
        'vehicle_registration' => 'ZZ99 ZZZ',
    ]);

    expect($device->id)->toBe(99)
        ->and($device->ignition)->toBe('N')
        ->and($device->ignitionOn())->toBeFalse()
        ->and($device->speed)->toBe(0)
        ->and($device->vehicleRegistration)->toBe('ZZ99 ZZZ');
});

it('bakes a typed DevicePositions from device DTOs with the uppercase refresh keys', function () {
    $positions = FakeDevicePositions::withDevices([
        FakeDevice::make(['id' => 1, 'ignition' => 'Y']),
        FakeDevice::make(['id' => 2, 'ignition' => 'N']),
    ]);

    expect($positions)->toBeInstanceOf(DevicePositions::class)
        ->and($positions->devices)->toHaveCount(2)
        ->and($positions->devices[0])->toBeInstanceOf(Device::class)
        ->and($positions->devices[0]->id)->toBe(1)
        ->and($positions->devices[1]->id)->toBe(2)
        ->and($positions->deviceCount)->toBe(2)
        ->and($positions->liveMapRefreshRate)->toBe(30000)
        ->and($positions->liveMapLargeFleetRefreshRate)->toBe(90000);
});

it('accepts raw attribute arrays as devices and exposes the raw body', function () {
    $positions = FakeDevicePositions::withDevices([
        ['id' => 7, 'ignition' => 'Y', 'speed' => 50, 'vehicle_registration' => 'CD34 EFG'],
    ]);

    expect($positions->devices)->toHaveCount(1)
        ->and($positions->devices[0]->id)->toBe(7)
        ->and($positions->devices[0]->speed)->toBe(50);

    $body = FakeDevicePositions::body([FakeDevice::make(['id' => 3])]);

    expect($body)->toHaveKey('KINESIS_LIVE_MAP_REFRESH_RATE')
        ->and($body)->toHaveKey('KINESIS_LIVE_MAP_LARGE_FLEET_REFRESH_RATE')
        ->and($body['device_count'])->toBe(1)
        ->and($body['devices'])->toBeArray();
});

it('replays staged FleetScenario frames in order then refuses an over-run', function () {
    $scenario = (new FleetScenario())
        ->pushDevices([FakeDevice::make(['id' => 1, 'ignition' => 'N', 'speed' => 0])])
        ->pushDevices([FakeDevice::make(['id' => 1, 'ignition' => 'Y', 'speed' => 40])]);

    $first = DevicePositions::fromArray($scenario->next());
    $second = DevicePositions::fromArray($scenario->next());

    expect($scenario->count())->toBe(2)
        ->and($first->devices[0]->ignitionOn())->toBeFalse()
        ->and($second->devices[0]->ignitionOn())->toBeTrue()
        ->and($scenario->toMockResponses(
            ChrisJohnLeah\VelocityFleet\Requests\DevicePositions\GetDevicePositions::class,
        ))->toHaveCount(2);

    expect(fn () => $scenario->next())->toThrow(OutOfRangeException::class);
});

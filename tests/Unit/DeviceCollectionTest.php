<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use ChrisJohnLeah\VelocityFleet\Data\Device;
use ChrisJohnLeah\VelocityFleet\Laravel\Support\DeviceCollection;

it('filters moving and idling devices', function () {
    $devices = new DeviceCollection([
        Device::fromArray(['id' => 1, 'speed' => 30, 'ignition' => 'Y']),
        Device::fromArray(['id' => 2, 'speed' => 0, 'ignition' => 'Y']),
        Device::fromArray(['id' => 3, 'speed' => 0, 'ignition' => 'N']),
    ]);

    expect($devices->moving()->pluck('id')->all())->toBe([1])
        ->and($devices->idling()->pluck('id')->all())->toBe([2]);
});

it('filters by ignition state', function () {
    $devices = new DeviceCollection([
        Device::fromArray(['id' => 1, 'ignition' => 'Y']),
        Device::fromArray(['id' => 2, 'ignition' => 'N']),
        Device::fromArray(['id' => 3, 'ignition' => '']),
    ]);

    expect($devices->ignitionOn()->pluck('id')->all())->toBe([1])
        ->and($devices->ignitionOff()->pluck('id')->all())->toBe([2]);
});

it('filters by device and driver group', function () {
    $devices = new DeviceCollection([
        Device::fromArray(['id' => 1, 'device_groups' => [10, 20], 'driver_groups' => [7]]),
        Device::fromArray(['id' => 2, 'device_groups' => [30], 'driver_groups' => []]),
    ]);

    expect($devices->inDeviceGroup(10)->pluck('id')->all())->toBe([1])
        ->and($devices->inDriverGroup(7)->pluck('id')->all())->toBe([1]);
});

it('filters devices near a point', function () {
    $devices = new DeviceCollection([
        Device::fromArray(['id' => 1, 'lat' => 52.4862, 'lon' => -1.8904]), // Birmingham
        Device::fromArray(['id' => 2, 'lat' => 51.5074, 'lon' => -0.1278]), // London (~160km)
    ]);

    expect($devices->near(52.4862, -1.8904, 5.0)->pluck('id')->all())->toBe([1]);
});

it('filters by registration and driver name', function () {
    $devices = new DeviceCollection([
        Device::fromArray(['id' => 1, 'vehicle_registration' => 'AB12 ABC', 'driver_name' => 'Joe Bloggs']),
        Device::fromArray(['id' => 2, 'vehicle_registration' => 'XY99 XYZ', 'driver_name' => 'Jane Doe']),
    ]);

    expect($devices->byRegistration('ab12 abc')->pluck('id')->all())->toBe([1])
        ->and($devices->withDriver('bloggs')->pluck('id')->all())->toBe([1]);
});

it('filters online and offline devices by freshness', function () {
    $now = CarbonImmutable::now();

    $devices = new DeviceCollection([
        Device::fromArray(['id' => 1, 'timestamp' => (string) $now->getTimestamp()]),
        Device::fromArray(['id' => 2, 'timestamp' => (string) $now->subHour()->getTimestamp()]),
    ]);

    expect($devices->online(900, $now)->pluck('id')->all())->toBe([1])
        ->and($devices->offline(900, $now)->pluck('id')->all())->toBe([2]);
});

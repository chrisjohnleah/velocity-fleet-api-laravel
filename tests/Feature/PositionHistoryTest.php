<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use ChrisJohnLeah\VelocityFleet\Data\Device;
use ChrisJohnLeah\VelocityFleet\Data\DevicePositions;
use ChrisJohnLeah\VelocityFleet\Laravel\Events\DevicePositionsUpdated;
use ChrisJohnLeah\VelocityFleet\Laravel\Listeners\PersistDevicePositions;
use ChrisJohnLeah\VelocityFleet\Laravel\Models\DevicePositionRecord;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Event;

/**
 * @param  list<Device>  $devices
 */
function historyPositions(array $devices): DevicePositions
{
    return new DevicePositions(devices: $devices, deviceCount: count($devices));
}

function fireHistoryPoll(string $customerId, DevicePositions $positions): void
{
    Event::dispatch(new DevicePositionsUpdated(
        $customerId,
        $positions,
        [],
        CarbonImmutable::now(),
    ));
}

beforeEach(function () {
    // Register the history sink exactly as the service provider will.
    Event::listen(DevicePositionsUpdated::class, PersistDevicePositions::class);
    config()->set('velocity-fleet.history.enabled', true);
    config()->set('velocity-fleet.history.encrypt_pii', true);
    config()->set('velocity-fleet.history.store_raw', false);
});

it('ingests a row per device when history is enabled', function () {
    $positions = historyPositions([
        new Device(id: 1, lat: 51.5, lon: -0.12, vehicleRegistration: 'AB12 ABC', speed: 30, ignition: 'Y', timestamp: 1_700_000_000),
        new Device(id: 2, lat: 52.1, lon: -1.20, vehicleRegistration: 'XY99 XYZ', speed: 0, ignition: 'N', timestamp: 1_700_000_000),
    ]);

    fireHistoryPoll('cust-1', $positions);

    expect(DevicePositionRecord::query()->count())->toBe(2)
        ->and(DevicePositionRecord::query()->where('device_id', '1')->value('speed'))->toBe(30);
});

it('does nothing when history is disabled', function () {
    config()->set('velocity-fleet.history.enabled', false);

    fireHistoryPoll('cust-1', historyPositions([
        new Device(id: 1, timestamp: 1_700_000_000),
    ]));

    expect(DevicePositionRecord::query()->count())->toBe(0);
});

it('is idempotent: re-ingesting the same recorded_at yields one row', function () {
    $positions = historyPositions([
        new Device(id: 7, lat: 51.5, lon: -0.12, vehicleRegistration: 'AB12 ABC', speed: 20, ignition: 'Y', timestamp: 1_700_000_000),
    ]);

    fireHistoryPoll('cust-9', $positions);
    fireHistoryPoll('cust-9', $positions);

    expect(DevicePositionRecord::query()->count())->toBe(1);
});

it('upsert refreshes mutable columns on a repeat tick', function () {
    fireHistoryPoll('cust-2', historyPositions([
        new Device(id: 3, speed: 10, ignition: 'Y', timestamp: 1_700_000_000),
    ]));

    // Same device + recorded_at, newer telemetry — should overwrite, not duplicate.
    fireHistoryPoll('cust-2', historyPositions([
        new Device(id: 3, speed: 55, ignition: 'N', timestamp: 1_700_000_000),
    ]));

    expect(DevicePositionRecord::query()->count())->toBe(1)
        ->and(DevicePositionRecord::query()->where('device_id', '3')->value('speed'))->toBe(55);
});

it('skips private devices', function () {
    fireHistoryPoll('cust-3', historyPositions([
        new Device(id: 4, private: true, timestamp: 1_700_000_000),
        new Device(id: 5, private: false, timestamp: 1_700_000_000),
    ]));

    expect(DevicePositionRecord::query()->count())->toBe(1)
        ->and(DevicePositionRecord::query()->where('device_id', '5')->exists())->toBeTrue();
});

it('encrypts PII at rest and decrypts it through the model cast', function () {
    fireHistoryPoll('cust-4', historyPositions([
        new Device(id: 6, vehicleRegistration: 'SECRET1', driverName: 'Jane Doe', mobilePhone: '07000000000', timestamp: 1_700_000_000),
    ]));

    // Stored ciphertext is not the plaintext...
    $raw = DevicePositionRecord::query()
        ->getQuery()
        ->where('device_id', '6')
        ->value('vehicle_registration');

    expect($raw)->toBeString()
        ->and($raw)->not->toBe('SECRET1')
        ->and(Crypt::decryptString(is_string($raw) ? $raw : ''))->toBe('SECRET1');

    // ...but the model cast transparently decrypts on read.
    $record = DevicePositionRecord::query()->where('device_id', '6')->firstOrFail();

    expect($record->vehicle_registration)->toBe('SECRET1')
        ->and($record->driver_name)->toBe('Jane Doe')
        ->and($record->mobile_phone)->toBe('07000000000');
});

it('stores plaintext PII when encryption is disabled', function () {
    config()->set('velocity-fleet.history.encrypt_pii', false);

    fireHistoryPoll('cust-5', historyPositions([
        new Device(id: 8, vehicleRegistration: 'PLAIN1', timestamp: 1_700_000_000),
    ]));

    $raw = DevicePositionRecord::query()
        ->getQuery()
        ->where('device_id', '8')
        ->value('vehicle_registration');

    expect($raw)->toBe('PLAIN1');
});

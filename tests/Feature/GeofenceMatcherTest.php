<?php

declare(strict_types=1);

use ChrisJohnLeah\VelocityFleet\Data\Device;
use ChrisJohnLeah\VelocityFleet\Laravel\Events\VehicleArrived;
use ChrisJohnLeah\VelocityFleet\Laravel\Events\VehicleEnteredGeofence;
use ChrisJohnLeah\VelocityFleet\Laravel\Events\VehicleExitedGeofence;
use ChrisJohnLeah\VelocityFleet\Laravel\Geofencing\GeofenceMatcher;
use ChrisJohnLeah\VelocityFleet\Laravel\Models\Geofence;
use ChrisJohnLeah\VelocityFleet\Laravel\Models\GeofenceEvent;
use Illuminate\Support\Facades\Event;

function geofenceCircle(): Geofence
{
    return Geofence::query()->create([
        'name' => 'Manchester Depot',
        'type' => 'circle',
        'center_lat' => 53.4808,
        'center_lon' => -2.2426,
        'radius_m' => 500.0,
        'customer_id' => 'cust-geo-1',
        'device_group_id' => null,
        'active' => true,
    ]);
}

function geofenceDevice(float $lat, float $lon): Device
{
    return new Device(id: 9001, lat: $lat, lon: $lon, vehicleRegistration: 'GE01 FNC');
}

it('fires entered and arrived when a device is inside a circle geofence', function () {
    geofenceCircle();
    Event::fake([VehicleEnteredGeofence::class, VehicleArrived::class, VehicleExitedGeofence::class]);

    $matcher = app(GeofenceMatcher::class);

    // ~30m north of centre — well inside the 500m radius.
    $matcher->match('cust-geo-1', [geofenceDevice(53.4811, -2.2426)]);

    Event::assertDispatched(VehicleEnteredGeofence::class, function (VehicleEnteredGeofence $event): bool {
        return $event->customerId === 'cust-geo-1'
            && $event->device->id === 9001
            && $event->geofence->name === 'Manchester Depot';
    });
    Event::assertDispatched(VehicleArrived::class);
    Event::assertNotDispatched(VehicleExitedGeofence::class);

    expect(GeofenceEvent::query()->whereNull('exited_at')->count())->toBe(1);
});

it('does not re-fire entered while the device stays inside', function () {
    geofenceCircle();
    Event::fake([VehicleEnteredGeofence::class]);

    $matcher = app(GeofenceMatcher::class);

    $matcher->match('cust-geo-1', [geofenceDevice(53.4810, -2.2426)]);
    $matcher->match('cust-geo-1', [geofenceDevice(53.4809, -2.2427)]);

    Event::assertDispatchedTimes(VehicleEnteredGeofence::class, 1);
    expect(GeofenceEvent::query()->count())->toBe(1);
});

it('fires exited and closes the open event when the device moves outside', function () {
    geofenceCircle();
    Event::fake([VehicleExitedGeofence::class]);

    $matcher = app(GeofenceMatcher::class);

    // First inside, then far outside (central London, ~250km away).
    $matcher->match('cust-geo-1', [geofenceDevice(53.4808, -2.2426)]);
    $matcher->match('cust-geo-1', [geofenceDevice(51.5074, -0.1278)]);

    Event::assertDispatched(VehicleExitedGeofence::class, function (VehicleExitedGeofence $event): bool {
        return $event->device->id === 9001;
    });

    $closed = GeofenceEvent::query()->whereNotNull('exited_at')->first();
    expect($closed)->not->toBeNull()
        ->and($closed?->dwell_seconds)->not->toBeNull();
    expect(GeofenceEvent::query()->whereNull('exited_at')->count())->toBe(0);
});

it('ignores geofences scoped to a different customer', function () {
    $fence = geofenceCircle();
    $fence->forceFill(['customer_id' => 'someone-else'])->save();

    Event::fake([VehicleEnteredGeofence::class]);

    app(GeofenceMatcher::class)->match('cust-geo-1', [geofenceDevice(53.4808, -2.2426)]);

    Event::assertNotDispatched(VehicleEnteredGeofence::class);
    expect(GeofenceEvent::query()->count())->toBe(0);
});

it('matches a device against a polygon geofence via ray casting', function () {
    Geofence::query()->create([
        'name' => 'City Block',
        'type' => 'polygon',
        'points' => [
            ['lat' => 53.0, 'lon' => -2.0],
            ['lat' => 53.0, 'lon' => -1.0],
            ['lat' => 54.0, 'lon' => -1.0],
            ['lat' => 54.0, 'lon' => -2.0],
        ],
        'customer_id' => null,
        'active' => true,
    ]);

    Event::fake([VehicleEnteredGeofence::class]);

    // (53.5, -1.5) sits inside the square; (52.0, -1.5) sits below it.
    app(GeofenceMatcher::class)->match('cust-geo-1', [
        geofenceDevice(53.5, -1.5),
        new Device(id: 9002, lat: 52.0, lon: -1.5),
    ]);

    Event::assertDispatchedTimes(VehicleEnteredGeofence::class, 1);
    Event::assertDispatched(VehicleEnteredGeofence::class, function (VehicleEnteredGeofence $event): bool {
        return $event->device->id === 9001;
    });
});

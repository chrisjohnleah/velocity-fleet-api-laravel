<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use ChrisJohnLeah\VelocityFleet\Data\Device;
use ChrisJohnLeah\VelocityFleet\Data\DevicePositions;
use ChrisJohnLeah\VelocityFleet\Laravel\Events\DevicePositionsUpdated;
use ChrisJohnLeah\VelocityFleet\Laravel\Events\DeviceWentStale;
use ChrisJohnLeah\VelocityFleet\Laravel\Events\VehicleArrived;
use ChrisJohnLeah\VelocityFleet\Laravel\Listeners\SendFleetAlerts;
use ChrisJohnLeah\VelocityFleet\Laravel\Models\Geofence;
use ChrisJohnLeah\VelocityFleet\Laravel\Notifications\DeviceOfflineNotification;
use ChrisJohnLeah\VelocityFleet\Laravel\Notifications\IdlingTooLongNotification;
use ChrisJohnLeah\VelocityFleet\Laravel\Notifications\SpeedingNotification;
use ChrisJohnLeah\VelocityFleet\Laravel\Notifications\VehicleArrivedNotification;
use Illuminate\Support\Facades\Notification;

function alertsListener(): SendFleetAlerts
{
    return app(SendFleetAlerts::class);
}

function enableAlerts(int $throttleMinutes = 30): void
{
    config()->set('velocity-fleet.notifications.enabled', true);
    config()->set('velocity-fleet.notifications.channels', ['mail']);
    config()->set('velocity-fleet.notifications.routes', ['mail' => ['ops@example.test']]);
    config()->set('velocity-fleet.alerts.throttle_minutes', $throttleMinutes);
}

function alertsGeofence(): Geofence
{
    $fence = new Geofence();
    $fence->forceFill([
        'id' => 4242,
        'name' => 'Leeds Depot',
        'type' => 'circle',
        'active' => true,
    ]);

    return $fence;
}

function alertsDevice(int $id = 5001): Device
{
    return new Device(
        id: $id,
        speed: 0,
        ignition: 'Y',
        vehicleRegistration: 'AL01 ERT',
        driverName: 'Sam Driver',
        timestamp: CarbonImmutable::now()->getTimestamp(),
    );
}

it('sends a VehicleArrived notification once and throttles an immediate repeat', function () {
    enableAlerts();
    Notification::fake();

    $event = new VehicleArrived('cust-1', alertsDevice(), alertsGeofence(), CarbonImmutable::now());

    alertsListener()->handleVehicleArrived($event);
    alertsListener()->handleVehicleArrived($event);

    Notification::assertSentOnDemandTimes(VehicleArrivedNotification::class, 1);
});

it('does nothing when notifications are disabled', function () {
    config()->set('velocity-fleet.notifications.enabled', false);
    config()->set('velocity-fleet.notifications.routes', ['mail' => ['ops@example.test']]);
    Notification::fake();

    alertsListener()->handleVehicleArrived(
        new VehicleArrived('cust-1', alertsDevice(), alertsGeofence(), CarbonImmutable::now()),
    );

    Notification::assertNothingSent();
});

it('does nothing when no recipient routes are configured', function () {
    enableAlerts();
    config()->set('velocity-fleet.notifications.routes', []);
    Notification::fake();

    alertsListener()->handleVehicleArrived(
        new VehicleArrived('cust-1', alertsDevice(), alertsGeofence(), CarbonImmutable::now()),
    );

    Notification::assertNothingSent();
});

it('alerts distinct devices independently under the same rule', function () {
    enableAlerts();
    Notification::fake();

    $listener = alertsListener();
    $now = CarbonImmutable::now();

    $listener->handleVehicleArrived(new VehicleArrived('cust-1', alertsDevice(5001), alertsGeofence(), $now));
    $listener->handleVehicleArrived(new VehicleArrived('cust-1', alertsDevice(5002), alertsGeofence(), $now));

    Notification::assertSentOnDemandTimes(VehicleArrivedNotification::class, 2);
});

it('sends a DeviceOffline notification when a device goes stale', function () {
    enableAlerts();
    Notification::fake();

    alertsListener()->handleDeviceWentStale(
        new DeviceWentStale('cust-1', alertsDevice(), CarbonImmutable::now()),
    );

    Notification::assertSentOnDemandTimes(DeviceOfflineNotification::class, 1);
});

it('sends a heuristic Speeding notification when speed exceeds the threshold', function () {
    enableAlerts();
    config()->set('velocity-fleet.alerts.speeding_mph', 70);
    Notification::fake();

    $speeder = new Device(id: 6001, speed: 95, ignition: 'Y', timestamp: CarbonImmutable::now()->getTimestamp());

    alertsListener()->handlePositionsUpdated(
        new DevicePositionsUpdated('cust-1', new DevicePositions(devices: [$speeder]), [], CarbonImmutable::now()),
    );

    Notification::assertSentOnDemandTimes(SpeedingNotification::class, 1);
});

it('sends a heuristic Idling notification only after the threshold elapses across snapshots', function () {
    enableAlerts();
    config()->set('velocity-fleet.alerts.idling_minutes', 10);
    Notification::fake();

    $listener = alertsListener();
    $start = CarbonImmutable::create(2026, 6, 9, 12, 0, 0);

    $idler = new Device(id: 7001, speed: 0, ignition: 'Y', timestamp: $start->getTimestamp());

    // First snapshot starts the idling clock — no alert yet.
    $listener->handlePositionsUpdated(
        new DevicePositionsUpdated('cust-1', new DevicePositions(devices: [$idler]), [], $start),
    );
    Notification::assertNothingSent();

    // 12 minutes later, still idling — now past the 10 minute threshold.
    $listener->handlePositionsUpdated(
        new DevicePositionsUpdated('cust-1', new DevicePositions(devices: [$idler]), [], $start->addMinutes(12)),
    );

    Notification::assertSentOnDemandTimes(IdlingTooLongNotification::class, 1);
});

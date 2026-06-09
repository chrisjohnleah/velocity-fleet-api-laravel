<?php

declare(strict_types=1);

namespace ChrisJohnLeah\VelocityFleet\Laravel\Listeners;

use ChrisJohnLeah\VelocityFleet\Data\Device;
use ChrisJohnLeah\VelocityFleet\Laravel\Alerts\AlertRule;
use ChrisJohnLeah\VelocityFleet\Laravel\Events\DevicePositionsUpdated;
use ChrisJohnLeah\VelocityFleet\Laravel\Events\DeviceWentStale;
use ChrisJohnLeah\VelocityFleet\Laravel\Events\VehicleArrived;
use ChrisJohnLeah\VelocityFleet\Laravel\Events\VehicleEnteredGeofence;
use ChrisJohnLeah\VelocityFleet\Laravel\Events\VehicleExitedGeofence;
use ChrisJohnLeah\VelocityFleet\Laravel\Notifications\DeviceOfflineNotification;
use ChrisJohnLeah\VelocityFleet\Laravel\Notifications\GeofenceBreachedNotification;
use ChrisJohnLeah\VelocityFleet\Laravel\Notifications\IdlingTooLongNotification;
use ChrisJohnLeah\VelocityFleet\Laravel\Notifications\SpeedingNotification;
use ChrisJohnLeah\VelocityFleet\Laravel\Notifications\VehicleArrivedNotification;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as NotificationFacade;

/**
 * Turns fleet domain events into throttled, multi-channel notifications.
 *
 * Bound to several events by the service provider (see provider wiring). Every
 * handler is a no-op unless `notifications.enabled` is true. Each alert is keyed
 * by rule + customer + device and suppressed for `alerts.throttle_minutes` using
 * an atomic cache `add()` so a position storm never produces an alert storm.
 *
 * Speeding and idling are HEURISTICS derived from poll snapshots, not dedicated
 * sensors — the notifications label themselves as approximate.
 */
final class SendFleetAlerts
{
    private const IDLING_STATE_PREFIX = 'velocity_fleet:idling_since';

    public function __construct(
        private readonly CacheRepository $cache,
    ) {
    }

    /**
     * A geofence "vehicle arrived" event (geofences module, B.12).
     */
    public function handleVehicleArrived(VehicleArrived $event): void
    {
        if (! $this->enabled()) {
            return;
        }

        $rule = AlertRule::make('vehicle_arrived', VehicleArrivedNotification::class);

        $this->dispatch(
            $rule,
            $event->customerId,
            $event->device,
            new VehicleArrivedNotification($event->customerId, $event->device, $event->geofence),
        );
    }

    /**
     * A device crossed into a geofence (geofences module, B.12).
     */
    public function handleVehicleEnteredGeofence(VehicleEnteredGeofence $event): void
    {
        if (! $this->enabled()) {
            return;
        }

        $rule = AlertRule::make('geofence_breached_entered', GeofenceBreachedNotification::class);

        $this->dispatch(
            $rule,
            $event->customerId,
            $event->device,
            new GeofenceBreachedNotification($event->customerId, $event->device, $event->geofence, 'entered'),
        );
    }

    /**
     * A device crossed out of a geofence (geofences module, B.12).
     */
    public function handleVehicleExitedGeofence(VehicleExitedGeofence $event): void
    {
        if (! $this->enabled()) {
            return;
        }

        $rule = AlertRule::make('geofence_breached_exited', GeofenceBreachedNotification::class);

        $this->dispatch(
            $rule,
            $event->customerId,
            $event->device,
            new GeofenceBreachedNotification($event->customerId, $event->device, $event->geofence, 'exited'),
        );
    }

    /**
     * A device went stale / offline (B.3 change-detection event).
     */
    public function handleDeviceWentStale(DeviceWentStale $event): void
    {
        if (! $this->enabled()) {
            return;
        }

        $rule = AlertRule::make('device_offline', DeviceOfflineNotification::class);

        $this->dispatch(
            $rule,
            $event->customerId,
            $event->device,
            new DeviceOfflineNotification($event->customerId, $event->device),
        );
    }

    /**
     * Heuristic speeding + idling detection over a full positions update.
     */
    public function handlePositionsUpdated(DevicePositionsUpdated $event): void
    {
        if (! $this->enabled()) {
            return;
        }

        $speedingThreshold = $this->intConfig('velocity-fleet.alerts.speeding_mph', 80);
        $idlingThreshold = $this->intConfig('velocity-fleet.alerts.idling_minutes', 10);

        foreach ($event->positions->devices as $device) {
            $this->detectSpeeding($event->customerId, $device, $speedingThreshold);
            $this->detectIdling($event->customerId, $device, $idlingThreshold, $event->occurredAt->getTimestamp());
        }
    }

    private function detectSpeeding(string $customerId, Device $device, int $thresholdMph): void
    {
        $speed = $device->speed;

        if ($speed === null || $speed <= $thresholdMph) {
            return;
        }

        // The threshold is mph; only apply it when the device reports mph (or no
        // unit), so a km/h reading isn't silently misjudged as speeding.
        $unit = $device->speedMeasureText;

        if ($unit !== null && strtoupper(trim($unit)) !== 'MPH') {
            return;
        }

        $rule = AlertRule::make('speeding', SpeedingNotification::class);

        $this->dispatch(
            $rule,
            $customerId,
            $device,
            new SpeedingNotification($customerId, $device, $thresholdMph),
        );
    }

    private function detectIdling(string $customerId, Device $device, int $thresholdMinutes, int $nowTimestamp): void
    {
        $deviceId = $this->deviceKey($device);
        $stateKey = self::IDLING_STATE_PREFIX.':'.$this->normaliseKey($customerId).':'.$deviceId;

        $isIdling = $device->ignitionOn() === true && ($device->speed ?? 0) === 0;

        if (! $isIdling) {
            $this->cache->forget($stateKey);

            return;
        }

        $since = $this->cache->get($stateKey);
        $sinceTimestamp = is_int($since) ? $since : null;

        if ($sinceTimestamp === null) {
            // First snapshot we observed this device idling — start the clock.
            $this->cache->put($stateKey, $nowTimestamp, $this->idlingStateTtl($thresholdMinutes));

            return;
        }

        $elapsedMinutes = (int) floor(max(0, $nowTimestamp - $sinceTimestamp) / 60);

        if ($elapsedMinutes < $thresholdMinutes) {
            return;
        }

        $rule = AlertRule::make('idling', IdlingTooLongNotification::class);

        $this->dispatch(
            $rule,
            $customerId,
            $device,
            new IdlingTooLongNotification($customerId, $device, $elapsedMinutes, $thresholdMinutes),
        );
    }

    /**
     * Throttle then deliver to every configured recipient route. The atomic
     * cache `add()` returns false if a prior alert is still inside the window.
     */
    private function dispatch(AlertRule $rule, string $customerId, Device $device, Notification $notification): void
    {
        $deviceId = $this->deviceKey($device);

        if (! $this->passesThrottle($rule->throttleKey($customerId, $deviceId))) {
            return;
        }

        $notifiables = $this->recipients();

        if ($notifiables === []) {
            return;
        }

        foreach ($notifiables as $notifiable) {
            NotificationFacade::send($notifiable, $notification);
        }
    }

    /**
     * Atomic dedupe: true only the first time within the throttle window.
     */
    private function passesThrottle(string $key): bool
    {
        $minutes = $this->intConfig('velocity-fleet.alerts.throttle_minutes', 30);

        if ($minutes <= 0) {
            return true;
        }

        return $this->cache->add($key, 1, $minutes * 60);
    }

    /**
     * Build anonymous notifiables from the configured routes map, e.g.
     * `['mail' => ['ops@example.com'], 'broadcast' => ['fleet']]`.
     *
     * @return list<AnonymousNotifiable>
     */
    private function recipients(): array
    {
        $routes = config('velocity-fleet.notifications.routes', []);

        if (! is_array($routes)) {
            return [];
        }

        $notifiables = [];

        foreach ($routes as $channel => $targets) {
            if (! is_string($channel) || $channel === '') {
                continue;
            }

            foreach ($this->normaliseTargets($targets) as $target) {
                $notifiables[] = NotificationFacade::route($channel, $target);
            }
        }

        return $notifiables;
    }

    /**
     * A route target may be a single value or a list of values.
     *
     * @return list<mixed>
     */
    private function normaliseTargets(mixed $targets): array
    {
        if (is_array($targets)) {
            return array_values($targets);
        }

        return [$targets];
    }

    private function enabled(): bool
    {
        return $this->boolConfig('velocity-fleet.notifications.enabled', false);
    }

    private function deviceKey(Device $device): string
    {
        return $device->id !== null ? (string) $device->id : 'unknown';
    }

    private function normaliseKey(string $value): string
    {
        $trimmed = trim($value);

        return $trimmed === '' ? 'unknown' : $trimmed;
    }

    /**
     * Idling state must outlive the threshold window plus a margin so the clock
     * is not lost between polls; bounded so an abandoned key still expires.
     */
    private function idlingStateTtl(int $thresholdMinutes): int
    {
        $minutes = max(1, $thresholdMinutes) * 2 + 5;

        return $minutes * 60;
    }

    private function intConfig(string $key, int $default): int
    {
        $value = config($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    private function boolConfig(string $key, bool $default): bool
    {
        $value = config($key, $default);

        return is_bool($value) ? $value : $default;
    }
}

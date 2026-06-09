<?php

declare(strict_types=1);

namespace ChrisJohnLeah\VelocityFleet\Laravel\Geofencing;

use Carbon\CarbonImmutable;
use ChrisJohnLeah\VelocityFleet\Data\Device;
use ChrisJohnLeah\VelocityFleet\Laravel\Events\VehicleArrived;
use ChrisJohnLeah\VelocityFleet\Laravel\Events\VehicleDwelledInGeofence;
use ChrisJohnLeah\VelocityFleet\Laravel\Events\VehicleEnteredGeofence;
use ChrisJohnLeah\VelocityFleet\Laravel\Events\VehicleExitedGeofence;
use ChrisJohnLeah\VelocityFleet\Laravel\Models\Geofence;
use ChrisJohnLeah\VelocityFleet\Laravel\Models\GeofenceEvent;
use ChrisJohnLeah\VelocityFleet\Laravel\Support\Haversine;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Containment engine: tests each device against every active geofence applicable
 * to its customer/device-group, compares the result against the durable
 * {@see GeofenceEvent} state (an open event = currently inside), and fires
 * enter/exit/dwell/arrived events while opening and closing event rows.
 */
final class GeofenceMatcher
{
    public function __construct(
        private readonly Dispatcher $events,
    ) {
    }

    /**
     * @param  list<Device>  $devices
     */
    public function match(string $customerId, array $devices): void
    {
        $geofences = $this->applicableGeofences($customerId);

        if ($geofences->isEmpty()) {
            return;
        }

        $now = CarbonImmutable::now();
        $dwellSeconds = $this->dwellSeconds();

        foreach ($geofences as $geofence) {
            foreach ($devices as $device) {
                $deviceId = $device->id;

                if ($deviceId === null || $device->lat === null || $device->lon === null) {
                    continue;
                }

                if (! $this->appliesToDevice($geofence, $device)) {
                    continue;
                }

                $open = $this->openEvent($geofence, $deviceId);
                $inside = $this->contains($geofence, $device->lat, $device->lon);

                if ($inside && $open === null) {
                    $this->enter($geofence, $device, $customerId, $now);

                    continue;
                }

                if (! $inside && $open !== null) {
                    $this->exit($geofence, $device, $customerId, $open, $now);

                    continue;
                }

                if ($inside && $open !== null) {
                    $this->checkDwell($geofence, $device, $customerId, $open, $now, $dwellSeconds);
                }
            }
        }
    }

    private function enter(Geofence $geofence, Device $device, string $customerId, CarbonImmutable $now): void
    {
        GeofenceEvent::query()->create([
            'device_id' => $device->id,
            'geofence_id' => $geofence->getKey(),
            'customer_id' => $customerId,
            'entered_at' => $now,
            'exited_at' => null,
            'dwell_seconds' => null,
        ]);

        $this->events->dispatch(new VehicleEnteredGeofence($customerId, $device, $geofence, $now));
        $this->events->dispatch(new VehicleArrived($customerId, $device, $geofence, $now));
    }

    private function exit(
        Geofence $geofence,
        Device $device,
        string $customerId,
        GeofenceEvent $open,
        CarbonImmutable $now,
    ): void {
        $enteredAt = $open->entered_at;
        $dwell = $enteredAt !== null ? max(0, $now->getTimestamp() - $enteredAt->getTimestamp()) : 0;

        $open->forceFill([
            'exited_at' => $now,
            'dwell_seconds' => $dwell,
        ])->save();

        $this->events->dispatch(new VehicleExitedGeofence($customerId, $device, $geofence, $now));
    }

    private function checkDwell(
        Geofence $geofence,
        Device $device,
        string $customerId,
        GeofenceEvent $open,
        CarbonImmutable $now,
        int $dwellSeconds,
    ): void {
        if ($dwellSeconds <= 0) {
            return;
        }

        $enteredAt = $open->entered_at;

        if ($enteredAt === null) {
            return;
        }

        $elapsed = $now->getTimestamp() - $enteredAt->getTimestamp();

        // Fire once: only when the threshold is crossed and not already recorded.
        if ($elapsed < $dwellSeconds || $open->dwell_seconds !== null) {
            return;
        }

        $open->forceFill(['dwell_seconds' => $elapsed])->save();

        $this->events->dispatch(new VehicleDwelledInGeofence($customerId, $device, $geofence, $now));
    }

    private function openEvent(Geofence $geofence, int $deviceId): ?GeofenceEvent
    {
        return GeofenceEvent::query()
            ->where('geofence_id', $geofence->getKey())
            ->where('device_id', $deviceId)
            ->whereNull('exited_at')
            ->latest('entered_at')
            ->first();
    }

    /**
     * @return Collection<int, Geofence>
     */
    private function applicableGeofences(string $customerId): Collection
    {
        return Geofence::query()
            ->where('active', true)
            ->where(static function (Builder $query) use ($customerId): void {
                $query->whereNull('customer_id')->orWhere('customer_id', $customerId);
            })
            ->get();
    }

    private function appliesToDevice(Geofence $geofence, Device $device): bool
    {
        $groupId = $geofence->device_group_id;

        if ($groupId === null) {
            return true;
        }

        return in_array($groupId, $device->deviceGroups, true)
            || in_array($groupId, $device->groups, true);
    }

    private function contains(Geofence $geofence, float $lat, float $lon): bool
    {
        if ($geofence->type === 'polygon') {
            return $this->pointInPolygon($lat, $lon, $this->polygonPoints($geofence));
        }

        $centerLat = $geofence->center_lat;
        $centerLon = $geofence->center_lon;
        $radiusM = $geofence->radius_m;

        if ($centerLat === null || $centerLon === null || $radiusM === null) {
            return false;
        }

        return Haversine::km($centerLat, $centerLon, $lat, $lon) <= ($radiusM / 1000.0);
    }

    /**
     * Ray-casting point-in-polygon test. Vertices are [lat, lon] pairs; the
     * polygon is treated as closed (the last vertex links back to the first).
     *
     * @param  list<array{0: float, 1: float}>  $points
     */
    private function pointInPolygon(float $lat, float $lon, array $points): bool
    {
        $count = count($points);

        if ($count < 3) {
            return false;
        }

        $inside = false;

        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            $latI = $points[$i][0];
            $lonI = $points[$i][1];
            $latJ = $points[$j][0];
            $lonJ = $points[$j][1];

            $intersects = (($lonI > $lon) !== ($lonJ > $lon))
                && ($lat < ($latJ - $latI) * ($lon - $lonI) / ($lonJ - $lonI) + $latI);

            if ($intersects) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }

    /**
     * Normalise the stored polygon JSON into a list of [lat, lon] float pairs.
     * Accepts either ordered pairs ([lat, lon]) or keyed objects ({lat, lon}).
     *
     * @return list<array{0: float, 1: float}>
     */
    private function polygonPoints(Geofence $geofence): array
    {
        $raw = $geofence->points;

        if (! is_array($raw)) {
            return [];
        }

        $points = [];

        foreach ($raw as $vertex) {
            if (! is_array($vertex)) {
                continue;
            }

            $lat = $this->coordinate($vertex, 'lat', 0);
            $lon = $this->coordinate($vertex, 'lon', 1);

            if ($lat === null || $lon === null) {
                continue;
            }

            $points[] = [$lat, $lon];
        }

        return $points;
    }

    /**
     * @param  array<array-key, mixed>  $vertex
     */
    private function coordinate(array $vertex, string $key, int $index): ?float
    {
        $value = $vertex[$key] ?? ($vertex[$index] ?? null);

        return is_numeric($value) ? (float) $value : null;
    }

    private function dwellSeconds(): int
    {
        $minutes = config('velocity-fleet.geofencing.dwell_minutes', 5);

        return is_numeric($minutes) ? max(0, (int) $minutes) * 60 : 0;
    }
}

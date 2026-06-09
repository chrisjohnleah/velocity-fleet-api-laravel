<?php

declare(strict_types=1);

namespace ChrisJohnLeah\VelocityFleet\Laravel\Support;

use Carbon\CarbonImmutable;
use ChrisJohnLeah\VelocityFleet\Data\Device;
use Illuminate\Support\Collection;

/**
 * A collection of {@see Device}s with fleet-aware query scopes. Each scope
 * returns a new {@see DeviceCollection} for chaining, e.g.
 * `$fleet->moving()->inDriverGroup(7)->near($lat, $lon, 5)`.
 *
 * @extends Collection<int, Device>
 */
class DeviceCollection extends Collection
{
    public function online(int $staleAfterSeconds = 900, ?CarbonImmutable $now = null): static
    {
        $cutoff = ($now ?? CarbonImmutable::now())->getTimestamp() - $staleAfterSeconds;

        return $this->filter(static function (Device $device) use ($cutoff): bool {
            $at = $device->occurredAt();

            return $at !== null && $at->getTimestamp() >= $cutoff;
        })->values();
    }

    public function offline(int $staleAfterSeconds = 900, ?CarbonImmutable $now = null): static
    {
        $cutoff = ($now ?? CarbonImmutable::now())->getTimestamp() - $staleAfterSeconds;

        return $this->filter(static function (Device $device) use ($cutoff): bool {
            $at = $device->occurredAt();

            return $at === null || $at->getTimestamp() < $cutoff;
        })->values();
    }

    public function moving(): static
    {
        return $this->filter(static fn (Device $device): bool => ($device->speed ?? 0) > 0)->values();
    }

    public function idling(): static
    {
        return $this->filter(
            static fn (Device $device): bool => $device->ignitionOn() === true && ($device->speed ?? 0) === 0,
        )->values();
    }

    public function ignitionOn(): static
    {
        return $this->filter(static fn (Device $device): bool => $device->ignitionOn() === true)->values();
    }

    public function ignitionOff(): static
    {
        return $this->filter(static fn (Device $device): bool => $device->ignitionOn() === false)->values();
    }

    public function inDeviceGroup(int $groupId): static
    {
        return $this->filter(static fn (Device $device): bool => in_array($groupId, $device->deviceGroups, true))->values();
    }

    public function inDriverGroup(int $groupId): static
    {
        return $this->filter(static fn (Device $device): bool => in_array($groupId, $device->driverGroups, true))->values();
    }

    public function near(float $lat, float $lon, float $km): static
    {
        return $this->filter(static function (Device $device) use ($lat, $lon, $km): bool {
            if ($device->lat === null || $device->lon === null) {
                return false;
            }

            return Haversine::km($lat, $lon, $device->lat, $device->lon) <= $km;
        })->values();
    }

    public function byRegistration(string $registration): static
    {
        $needle = strtoupper(trim($registration));

        return $this->filter(static fn (Device $device): bool => $device->vehicleRegistration !== null
            && strtoupper(trim($device->vehicleRegistration)) === $needle)->values();
    }

    public function withDriver(string $name): static
    {
        $needle = mb_strtolower(trim($name));

        return $this->filter(static fn (Device $device): bool => $device->driverName !== null
            && str_contains(mb_strtolower($device->driverName), $needle))->values();
    }

    public function keyByDeviceId(): static
    {
        return $this->keyBy(static fn (Device $device): int => $device->id ?? 0);
    }
}

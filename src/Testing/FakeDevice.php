<?php

declare(strict_types=1);

namespace ChrisJohnLeah\VelocityFleet\Laravel\Testing;

use Carbon\CarbonImmutable;
use ChrisJohnLeah\VelocityFleet\Data\Device;

/**
 * Builds a {@see Device} DTO with sane, fully-populated defaults for tests, so
 * host apps (and our own suite) can stage realistic vehicles in one line:
 * `FakeDevice::make(['ignition' => 'N', 'speed' => 0])`.
 *
 * Overrides use the same snake_case keys the live API sends and are merged over
 * the defaults before hydrating via {@see Device::fromArray()}, so the result is
 * always a real, typed DTO that behaves exactly like a decoded payload.
 */
final class FakeDevice
{
    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function make(array $overrides = []): Device
    {
        return Device::fromArray(array_merge(self::defaults(), $overrides));
    }

    /**
     * The raw attribute array (snake_case API keys) used to hydrate a device,
     * exposed so scenarios can embed it directly in a positions body.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function attributes(array $overrides = []): array
    {
        return array_merge(self::defaults(), $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private static function defaults(): array
    {
        $now = CarbonImmutable::now();

        return [
            'id' => 1,
            'lat' => 52.4862,
            'lon' => -1.8904,
            'ignition' => 'Y',
            'speed' => 32,
            'speed_measure_text' => 'mph',
            'direction' => 90.0,
            'vehicle_registration' => 'AB12 ABC',
            'driver_name' => 'Test Driver',
            'street' => 'Test Street',
            'town' => 'Birmingham',
            'country' => 'United Kingdom',
            'post_code' => 'B1 1AA',
            'device_groups' => [],
            'groups' => [],
            'driver_groups' => [],
            'time' => $now->toIso8601String(),
            'timestamp' => $now->getTimestamp(),
        ];
    }
}

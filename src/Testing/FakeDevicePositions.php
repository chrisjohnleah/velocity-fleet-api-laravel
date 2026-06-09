<?php

declare(strict_types=1);

namespace ChrisJohnLeah\VelocityFleet\Laravel\Testing;

use ChrisJohnLeah\VelocityFleet\Data\Device;
use ChrisJohnLeah\VelocityFleet\Data\DevicePositions;

/**
 * Bakes a {@see DevicePositions} payload around a set of devices, taking care of
 * the API's awkward UPPERCASE refresh-rate keys
 * ({@see DevicePositions::$liveMapRefreshRate} /
 * {@see DevicePositions::$liveMapLargeFleetRefreshRate}) and the derived
 * `device_count`, so tests don't have to remember the wire shape.
 *
 * Devices may be supplied either as {@see Device} DTOs (e.g. from
 * {@see FakeDevice::make()}) or as raw snake_case attribute arrays; both are
 * normalised to arrays before hydration.
 */
final class FakeDevicePositions
{
    /**
     * @param  array<int, Device|array<string, mixed>>  $devices
     * @param  array<string, mixed>  $overrides
     */
    public static function withDevices(array $devices, array $overrides = []): DevicePositions
    {
        return DevicePositions::fromArray(self::body($devices, $overrides));
    }

    /**
     * The raw positions body — the array a {@see \Saloon\Http\Faking\MockResponse}
     * (or {@see FleetScenario}) feeds back as the decoded JSON for a poll.
     *
     * @param  array<int, Device|array<string, mixed>>  $devices
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function body(array $devices = [], array $overrides = []): array
    {
        $deviceArrays = array_map(self::toArray(...), array_values($devices));

        $base = [
            'device_count' => count($deviceArrays),
            'show_marker_reg_text' => true,
            'kinesis_core_customer' => true,
            'iso_numbers' => [],
            'driver_groups' => [],
            'device_groups' => [],
            'KINESIS_LIVE_MAP_REFRESH_RATE' => 30000,
            'KINESIS_LIVE_MAP_LARGE_FLEET_REFRESH_RATE' => 90000,
        ];

        return array_merge($base, $overrides, ['devices' => $deviceArrays]);
    }

    /**
     * Normalise a device entry (DTO or attribute array) into the snake_case
     * attribute array the positions body expects.
     *
     * @param  Device|array<string, mixed>  $device
     * @return array<string, mixed>
     */
    private static function toArray(Device|array $device): array
    {
        if ($device instanceof Device) {
            return self::deviceToAttributes($device);
        }

        return $device;
    }

    /**
     * @return array<string, mixed>
     */
    private static function deviceToAttributes(Device $device): array
    {
        return [
            'id' => $device->id,
            'mobile_phone' => $device->mobilePhone,
            'private' => $device->private,
            'lat' => $device->lat,
            'lon' => $device->lon,
            'driver_name' => $device->driverName,
            'driver_name_list_display' => $device->driverNameListDisplay,
            'previous_driver' => $device->previousDriver,
            'vehicle_registration' => $device->vehicleRegistration,
            'service_id' => $device->serviceId,
            'driver_id' => $device->driverId,
            'ignition' => $device->ignition,
            'speed' => $device->speed,
            'speed_measure_text' => $device->speedMeasureText,
            'direction' => $device->direction,
            'street' => $device->street,
            'town' => $device->town,
            'country' => $device->country,
            'post_code' => $device->postCode,
            'device_groups' => $device->deviceGroups,
            'groups' => $device->groups,
            'group_color' => $device->groupColor,
            'driver_groups' => $device->driverGroups,
            'device_group_color' => $device->deviceGroupColor,
            'time' => $device->time,
            'signal_strength_color' => $device->signalStrengthColor,
            'timestamp' => $device->timestamp,
            'driver_group_color' => $device->driverGroupColor,
        ];
    }
}

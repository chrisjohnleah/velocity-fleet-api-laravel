<?php

declare(strict_types=1);

namespace ChrisJohnLeah\VelocityFleet\Laravel\Support;

/**
 * Great-circle distance between two latitude/longitude points. Pure and static;
 * reused by the device collection, geofencing, and cache layers.
 */
final class Haversine
{
    private const EARTH_RADIUS_KM = 6371.0088;

    private const KM_PER_MILE = 1.609344;

    public static function km(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return self::EARTH_RADIUS_KM * 2 * asin(min(1.0, sqrt($a)));
    }

    public static function miles(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        return self::km($lat1, $lon1, $lat2, $lon2) / self::KM_PER_MILE;
    }
}

<?php

declare(strict_types=1);

namespace ChrisJohnLeah\VelocityFleet\Laravel\Events;

/** A device stopped reporting fresh positions (went offline / stale). */
final class DeviceWentStale extends DeviceEvent
{
}

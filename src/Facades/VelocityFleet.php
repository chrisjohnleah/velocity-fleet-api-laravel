<?php

declare(strict_types=1);

namespace ChrisJohnLeah\VelocityFleet\Laravel\Facades;

use ChrisJohnLeah\VelocityFleet\Laravel\FleetManager;
use Illuminate\Support\Facades\Facade;

/**
 * @mixin \ChrisJohnLeah\VelocityFleet\Laravel\FleetManager
 *
 * @see FleetManager
 */
class VelocityFleet extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return FleetManager::class;
    }
}

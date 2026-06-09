<?php

declare(strict_types=1);

namespace ChrisJohnLeah\VelocityFleet\Laravel\Facades;

use ChrisJohnLeah\VelocityFleet\VelocityFleet as VelocityFleetClient;
use Illuminate\Support\Facades\Facade;

/**
 * @mixin \ChrisJohnLeah\VelocityFleet\VelocityFleet
 *
 * @see VelocityFleetClient
 */
class VelocityFleet extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return VelocityFleetClient::class;
    }
}

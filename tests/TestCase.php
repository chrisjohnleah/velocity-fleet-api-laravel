<?php

declare(strict_types=1);

namespace ChrisJohnLeah\VelocityFleet\Laravel\Tests;

use ChrisJohnLeah\VelocityFleet\Laravel\VelocityFleetServiceProvider;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [VelocityFleetServiceProvider::class];
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}

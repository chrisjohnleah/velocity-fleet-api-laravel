<?php

declare(strict_types=1);

use ChrisJohnLeah\VelocityFleet\Laravel\Tests\TestCase;
use Saloon\Config;

/*
 * Bind the testbench base case and guarantee no test ever performs a real HTTP
 * call to Velocity — any unmocked request throws instead of hitting the network.
 */
uses(TestCase::class)
    ->beforeEach(fn () => Config::preventStrayRequests())
    ->in('Feature');

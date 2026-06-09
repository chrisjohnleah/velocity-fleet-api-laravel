<?php

declare(strict_types=1);

use ChrisJohnLeah\VelocityFleet\Auth\StoredToken;
use ChrisJohnLeah\VelocityFleet\Contracts\TokenStore;
use ChrisJohnLeah\VelocityFleet\Laravel\FleetManager;
use ChrisJohnLeah\VelocityFleet\Laravel\Jobs\PollFleetJob;
use ChrisJohnLeah\VelocityFleet\Requests\Customers\GetCustomers;
use Illuminate\Support\Facades\Bus;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

beforeEach(function () {
    app(TokenStore::class)->put(new StoredToken('test-token'));
});

afterEach(function () {
    MockClient::destroyGlobal();
});

it('dispatches a poll job for a single customer argument without calling the API', function () {
    Bus::fake();

    $this->artisan('velocity-fleet:poll', ['customer' => '424242'])
        ->expectsOutputToContain('Dispatched 1 fleet-polling job(s).')
        ->assertSuccessful();

    Bus::assertDispatched(PollFleetJob::class);
    Bus::assertDispatchedTimes(PollFleetJob::class, 1);
});

it('dispatches a poll job per linked customer when no argument is given', function () {
    config()->set('velocity-fleet.polling.customers', ['*']);

    FleetManager::fake([
        GetCustomers::class => MockResponse::make([
            '100' => ['name' => 'Alpha'],
            '200' => ['name' => 'Bravo'],
        ]),
    ]);

    Bus::fake();

    $this->artisan('velocity-fleet:poll')
        ->expectsOutputToContain('Dispatched 2 fleet-polling job(s).')
        ->assertSuccessful();

    Bus::assertDispatchedTimes(PollFleetJob::class, 2);
});

it('honours the polling.customers allow-list without calling the API', function () {
    config()->set('velocity-fleet.polling.customers', ['777', '888']);

    Bus::fake();

    // No customers MockResponse registered: a real API call would throw under
    // preventStrayRequests(), proving the allow-list short-circuits the lookup.
    $this->artisan('velocity-fleet:poll')
        ->expectsOutputToContain('Dispatched 2 fleet-polling job(s).')
        ->assertSuccessful();

    Bus::assertDispatchedTimes(PollFleetJob::class, 2);
});

it('fails cleanly when not connected', function () {
    config()->set('velocity-fleet.polling.customers', ['*']);
    app(TokenStore::class)->forget();

    Bus::fake();

    $this->artisan('velocity-fleet:poll')->assertFailed();

    Bus::assertNothingDispatched();
});

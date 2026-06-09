<?php

declare(strict_types=1);

use ChrisJohnLeah\VelocityFleet\Auth\StoredToken;
use ChrisJohnLeah\VelocityFleet\Contracts\TokenStore;
use ChrisJohnLeah\VelocityFleet\Laravel\EloquentTokenStore;
use ChrisJohnLeah\VelocityFleet\Laravel\Facades\VelocityFleet as VelocityFleetFacade;
use ChrisJohnLeah\VelocityFleet\Laravel\FleetManager;
use ChrisJohnLeah\VelocityFleet\Laravel\Models\VelocityFleetToken;
use ChrisJohnLeah\VelocityFleet\VelocityFleet;
use ChrisJohnLeah\VelocityFleet\VelocityFleetConnector;

it('resolves the client and connector from the container', function () {
    expect(app(VelocityFleet::class))->toBeInstanceOf(VelocityFleet::class)
        ->and(app(VelocityFleetConnector::class))->toBeInstanceOf(VelocityFleetConnector::class);
});

it('binds an Eloquent-backed token store', function () {
    expect(app(TokenStore::class))->toBeInstanceOf(EloquentTokenStore::class);
});

it('round-trips a token and keeps a single row that put() overwrites', function () {
    $store = app(TokenStore::class);

    expect($store->get())->toBeNull();

    $store->put(new StoredToken('access-1', 'refresh-1', new DateTimeImmutable('+5 minutes')));

    expect($store->get()->accessToken)->toBe('access-1')
        ->and($store->get()->refreshToken)->toBe('refresh-1')
        ->and($store->get()->expiresAt)->toBeInstanceOf(DateTimeImmutable::class)
        ->and(VelocityFleetToken::count())->toBe(1);

    // A refresh overwrites the same row (the token endpoint may rotate it).
    $store->put(new StoredToken('access-2', 'refresh-2', new DateTimeImmutable('+5 minutes')));

    expect($store->get()->accessToken)->toBe('access-2')
        ->and($store->get()->refreshToken)->toBe('refresh-2')
        ->and(VelocityFleetToken::count())->toBe(1);

    $store->forget();
    expect($store->get())->toBeNull();
});

it('exposes the facade backed by the FleetManager, which wraps the client', function () {
    expect(VelocityFleetFacade::getFacadeRoot())->toBeInstanceOf(FleetManager::class)
        ->and(app(FleetManager::class)->client())->toBeInstanceOf(VelocityFleet::class);
});

it('auto-seeds a static access token from config into an empty store', function () {
    config()->set('velocity-fleet.access_token', 'config-token');

    app(VelocityFleet::class); // resolving triggers the seed

    expect(app(TokenStore::class)->get()->accessToken)->toBe('config-token');
});

it('auto-seeds a refresh token from config as an already-expired seed', function () {
    config()->set('velocity-fleet.refresh_token', 'config-refresh');

    app(VelocityFleet::class);

    $token = app(TokenStore::class)->get();

    expect($token->refreshToken)->toBe('config-refresh')
        ->and($token->accessToken)->toBe('')
        ->and($token->hasExpired())->toBeTrue();
});

it('does not overwrite an existing stored token when seeding from config', function () {
    app(TokenStore::class)->put(new StoredToken('already-here'));

    config()->set('velocity-fleet.access_token', 'config-token');

    app(VelocityFleet::class);

    expect(app(TokenStore::class)->get()->accessToken)->toBe('already-here');
});

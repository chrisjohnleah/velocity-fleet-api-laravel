<?php

declare(strict_types=1);

use ChrisJohnLeah\VelocityFleet\Auth\StoredToken;
use ChrisJohnLeah\VelocityFleet\Contracts\TokenStore;

it('reports a not-connected status', function () {
    $this->artisan('velocity-fleet:status')->assertFailed();
});

it('reports a connected status once a token exists', function () {
    app(TokenStore::class)->put(
        new StoredToken('access-token', 'refresh', new DateTimeImmutable('+5 minutes')),
    );

    $this->artisan('velocity-fleet:status')->assertSuccessful();
});

it('stores a static token via velocity-fleet:connect --token', function () {
    $this->artisan('velocity-fleet:connect', ['--token' => 'my-api-token'])->assertSuccessful();

    expect(app(TokenStore::class)->get()->accessToken)->toBe('my-api-token');
});

it('stores an expired refresh-token seed via velocity-fleet:connect --refresh-token', function () {
    $this->artisan('velocity-fleet:connect', ['--refresh-token' => 'my-refresh'])->assertSuccessful();

    $token = app(TokenStore::class)->get();

    expect($token->refreshToken)->toBe('my-refresh')
        ->and($token->accessToken)->toBe('')
        ->and($token->hasExpired())->toBeTrue();
});

it('fails velocity-fleet:connect with no token available', function () {
    $this->artisan('velocity-fleet:connect')->assertFailed();
});

it('fails velocity-fleet:customers cleanly when not connected', function () {
    $this->artisan('velocity-fleet:customers')->assertFailed();
});

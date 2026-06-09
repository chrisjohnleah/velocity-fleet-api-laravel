<?php

declare(strict_types=1);

namespace ChrisJohnLeah\VelocityFleet\Laravel;

use ChrisJohnLeah\VelocityFleet\Auth\StoredToken;
use ChrisJohnLeah\VelocityFleet\Contracts\TokenStore;
use ChrisJohnLeah\VelocityFleet\Laravel\Models\VelocityFleetToken;

/**
 * Stores the Velocity Fleet connection's token in a single Eloquent row. put()
 * overwrites that row so a rotated refresh token always replaces the previous
 * one (the OAuth2 token endpoint may rotate it on every exchange).
 */
final class EloquentTokenStore implements TokenStore
{
    public function get(): ?StoredToken
    {
        $row = VelocityFleetToken::query()->latest('id')->first();

        if ($row === null) {
            return null;
        }

        return new StoredToken(
            accessToken: $row->access_token,
            refreshToken: $row->refresh_token,
            expiresAt: $row->expires_at?->toDateTimeImmutable(),
        );
    }

    public function put(StoredToken $token): void
    {
        $attributes = [
            'access_token' => $token->accessToken,
            'refresh_token' => $token->refreshToken,
            'expires_at' => $token->expiresAt,
        ];

        $existing = VelocityFleetToken::query()->latest('id')->first();

        if ($existing !== null) {
            $existing->update($attributes);

            return;
        }

        VelocityFleetToken::query()->create($attributes);
    }

    public function forget(): void
    {
        VelocityFleetToken::query()->delete();
    }
}

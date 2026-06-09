<?php

declare(strict_types=1);

namespace ChrisJohnLeah\VelocityFleet\Laravel\Events;

use Carbon\CarbonImmutable;

/** The access token was refreshed. Carries no token material. */
final class TokenRefreshed
{
    public function __construct(
        public readonly CarbonImmutable $occurredAt,
    ) {
    }
}

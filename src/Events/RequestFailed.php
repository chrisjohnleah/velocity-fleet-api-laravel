<?php

declare(strict_types=1);

namespace ChrisJohnLeah\VelocityFleet\Laravel\Events;

use Carbon\CarbonImmutable;

/** An API request failed. Carries the status and a redacted message — no tokens. */
final class RequestFailed
{
    public function __construct(
        public readonly ?string $customerId,
        public readonly ?int $status,
        public readonly string $message,
        public readonly CarbonImmutable $occurredAt,
    ) {
    }
}

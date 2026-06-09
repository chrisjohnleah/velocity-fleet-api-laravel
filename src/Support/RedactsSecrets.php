<?php

declare(strict_types=1);

namespace ChrisJohnLeah\VelocityFleet\Laravel\Support;

/**
 * Convenience accessors over {@see VelocityFleetLogSanitizer} for commands,
 * listeners, and middleware that may handle token-bearing strings.
 */
trait RedactsSecrets
{
    protected function redact(string $value): string
    {
        return VelocityFleetLogSanitizer::scrub($value);
    }

    protected function maskToken(?string $token): string
    {
        return VelocityFleetLogSanitizer::mask($token);
    }
}

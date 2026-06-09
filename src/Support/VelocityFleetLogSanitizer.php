<?php

declare(strict_types=1);

namespace ChrisJohnLeah\VelocityFleet\Laravel\Support;

/**
 * Scrubs token material from strings before they reach logs or context, and masks
 * tokens down to a short, non-reversible hint for status output.
 */
final class VelocityFleetLogSanitizer
{
    /**
     * @var array<string, string>
     */
    private const PATTERNS = [
        '/(Bearer\s+)[A-Za-z0-9._\-]+/i' => '$1[redacted]',
        '/("?(?:access_token|refresh_token|client_secret)"?\s*[:=]\s*"?)[A-Za-z0-9._\-]+/i' => '$1[redacted]',
    ];

    public static function scrub(string $message): string
    {
        foreach (self::PATTERNS as $pattern => $replacement) {
            $result = preg_replace($pattern, $replacement, $message);

            if (is_string($result)) {
                $message = $result;
            }
        }

        return $message;
    }

    public static function mask(?string $token): string
    {
        if ($token === null || $token === '') {
            return '(none)';
        }

        // Never reveal a meaningful fraction of a short token; only show a small
        // fixed-length prefix once the token is comfortably longer than it (and
        // the fixed suffix doesn't leak the token's length).
        if (mb_strlen($token) <= 8) {
            return '****';
        }

        return mb_substr($token, 0, 4).'****';
    }
}

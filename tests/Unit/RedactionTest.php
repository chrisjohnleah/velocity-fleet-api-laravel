<?php

declare(strict_types=1);

use ChrisJohnLeah\VelocityFleet\Laravel\Support\VelocityFleetLogSanitizer;

it('scrubs bearer tokens and token fields from log strings', function () {
    $line = 'GET failed Authorization: Bearer abc123secret access_token=def456secret refresh_token: "ghi789"';

    $scrubbed = VelocityFleetLogSanitizer::scrub($line);

    expect($scrubbed)->not->toContain('abc123secret')
        ->and($scrubbed)->not->toContain('def456secret')
        ->and($scrubbed)->not->toContain('ghi789')
        ->and($scrubbed)->toContain('[redacted]');
});

it('masks a token to a short non-reversible hint', function () {
    expect(VelocityFleetLogSanitizer::mask('supersecrettoken'))->toStartWith('supe')
        ->and(VelocityFleetLogSanitizer::mask('supersecrettoken'))->not->toContain('secrettoken')
        ->and(VelocityFleetLogSanitizer::mask(null))->toBe('(none)')
        ->and(VelocityFleetLogSanitizer::mask(''))->toBe('(none)');
});

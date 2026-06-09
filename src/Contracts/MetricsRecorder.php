<?php

declare(strict_types=1);

namespace ChrisJohnLeah\VelocityFleet\Laravel\Contracts;

/**
 * A sink for operational counters (positions fetched, refreshes, polls skipped,
 * errors). Bind your own implementation to forward to Prometheus/StatsD/etc.; the
 * package binds a no-op recorder by default.
 */
interface MetricsRecorder
{
    /**
     * @param  array<string, scalar>  $tags
     */
    public function increment(string $metric, int $by = 1, array $tags = []): void;
}

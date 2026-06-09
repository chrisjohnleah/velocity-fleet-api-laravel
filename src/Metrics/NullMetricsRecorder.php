<?php

declare(strict_types=1);

namespace ChrisJohnLeah\VelocityFleet\Laravel\Metrics;

use ChrisJohnLeah\VelocityFleet\Laravel\Contracts\MetricsRecorder;

/**
 * The default metrics sink: does nothing. Bind your own {@see MetricsRecorder}
 * to forward counters to Prometheus/StatsD/etc.
 */
final class NullMetricsRecorder implements MetricsRecorder
{
    /**
     * @param  array<string, scalar>  $tags
     */
    public function increment(string $metric, int $by = 1, array $tags = []): void
    {
        // no-op
    }
}

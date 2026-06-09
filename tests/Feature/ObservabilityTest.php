<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use ChrisJohnLeah\VelocityFleet\Laravel\Contracts\MetricsRecorder;
use ChrisJohnLeah\VelocityFleet\Laravel\Events\PollSkipped;
use ChrisJohnLeah\VelocityFleet\Laravel\Events\PositionsFetched;
use ChrisJohnLeah\VelocityFleet\Laravel\Events\RequestFailed;
use ChrisJohnLeah\VelocityFleet\Laravel\Events\TokenRefreshed;
use ChrisJohnLeah\VelocityFleet\Laravel\Listeners\LogVelocityFleetActivity;
use Illuminate\Log\LogManager;

/**
 * In-memory metrics recorder so we can assert the listener increments the right
 * counters without any network or external sink.
 */
final class ObservabilitySpyMetrics implements MetricsRecorder
{
    /** @var array<string, int> */
    public array $counters = [];

    /**
     * @param  array<string, scalar>  $tags
     */
    public function increment(string $metric, int $by = 1, array $tags = []): void
    {
        $this->counters[$metric] = ($this->counters[$metric] ?? 0) + $by;
    }
}

function observabilityListener(MetricsRecorder $metrics): LogVelocityFleetActivity
{
    return new LogVelocityFleetActivity(app(LogManager::class), $metrics);
}

it('passes the doctor self-checks on a sane default config', function () {
    $this->artisan('velocity-fleet:doctor')->assertSuccessful();
});

it('fails the doctor when APP_KEY is missing while token encryption is on', function () {
    config()->set('velocity-fleet.encrypt_tokens', true);
    config()->set('app.key', '');

    $this->artisan('velocity-fleet:doctor')->assertFailed();
});

it('fails the doctor when history is enabled without a retention window', function () {
    config()->set('velocity-fleet.history.enabled', true);
    config()->set('velocity-fleet.retention.positions_days', 0);

    $this->artisan('velocity-fleet:doctor')->assertFailed();
});

it('increments the positions-fetched counter when the event fires', function () {
    $metrics = new ObservabilitySpyMetrics();

    observabilityListener($metrics)->handlePositionsFetched(
        new PositionsFetched('cust-1', 12, false, CarbonImmutable::now()),
    );

    expect($metrics->counters['velocity_fleet.positions_fetched'] ?? 0)->toBe(1);
});

it('increments distinct counters across the lifecycle events', function () {
    $metrics = new ObservabilitySpyMetrics();
    $listener = observabilityListener($metrics);
    $now = CarbonImmutable::now();

    $listener->handleTokenRefreshed(new TokenRefreshed($now));
    $listener->handlePollSkipped(new PollSkipped('cust-1', 'fresh-cache', $now));
    $listener->handleRequestFailed(new RequestFailed('cust-1', 503, 'upstream unavailable', $now));

    expect($metrics->counters['velocity_fleet.token_refreshed'] ?? 0)->toBe(1)
        ->and($metrics->counters['velocity_fleet.polls_skipped'] ?? 0)->toBe(1)
        ->and($metrics->counters['velocity_fleet.errors'] ?? 0)->toBe(1);
});

it('increments via the registered event subscriber end to end', function () {
    $spy = new ObservabilitySpyMetrics();
    app()->instance(MetricsRecorder::class, $spy);

    // The service provider already subscribed LogVelocityFleetActivity, so just
    // firing the event must drive the spy exactly once (end-to-end wiring).
    event(new PositionsFetched('cust-9', 3, true, CarbonImmutable::now()));

    expect($spy->counters['velocity_fleet.positions_fetched'] ?? 0)->toBe(1);
});

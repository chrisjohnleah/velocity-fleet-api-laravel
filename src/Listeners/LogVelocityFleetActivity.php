<?php

declare(strict_types=1);

namespace ChrisJohnLeah\VelocityFleet\Laravel\Listeners;

use ChrisJohnLeah\VelocityFleet\Laravel\Contracts\MetricsRecorder;
use ChrisJohnLeah\VelocityFleet\Laravel\Events\PollSkipped;
use ChrisJohnLeah\VelocityFleet\Laravel\Events\PositionHistoryPruned;
use ChrisJohnLeah\VelocityFleet\Laravel\Events\PositionsFetched;
use ChrisJohnLeah\VelocityFleet\Laravel\Events\RequestFailed;
use ChrisJohnLeah\VelocityFleet\Laravel\Events\TokenRefreshed;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Log\LogManager;
use Psr\Log\LoggerInterface;

/**
 * Structured activity logging + operational metrics for the package's lifecycle
 * events. Every line carries counts/ids only — NEVER device-level PII, position
 * coordinates, driver details or token material. Logs to the channel configured
 * at `velocity-fleet.logging.channel` (null = the application's default channel).
 */
final class LogVelocityFleetActivity
{
    public function __construct(
        private readonly LogManager $log,
        private readonly MetricsRecorder $metrics,
    ) {
    }

    /**
     * Register the event handlers with the dispatcher.
     *
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            PositionsFetched::class => 'handlePositionsFetched',
            TokenRefreshed::class => 'handleTokenRefreshed',
            PollSkipped::class => 'handlePollSkipped',
            RequestFailed::class => 'handleRequestFailed',
            PositionHistoryPruned::class => 'handlePositionHistoryPruned',
        ];
    }

    public function handlePositionsFetched(PositionsFetched $event): void
    {
        $this->channel()->info('velocity-fleet: positions fetched', [
            'customer_id' => $event->customerId,
            'device_count' => $event->deviceCount,
            'from_cache' => $event->fromCache,
            'occurred_at' => $event->occurredAt->toIso8601String(),
        ]);

        $this->metrics->increment('velocity_fleet.positions_fetched', 1, [
            'from_cache' => $event->fromCache ? 'true' : 'false',
        ]);
    }

    public function handleTokenRefreshed(TokenRefreshed $event): void
    {
        $this->channel()->info('velocity-fleet: token refreshed', [
            'occurred_at' => $event->occurredAt->toIso8601String(),
        ]);

        $this->metrics->increment('velocity_fleet.token_refreshed');
    }

    public function handlePollSkipped(PollSkipped $event): void
    {
        $this->channel()->info('velocity-fleet: poll skipped', [
            'customer_id' => $event->customerId,
            'reason' => $event->reason,
            'occurred_at' => $event->occurredAt->toIso8601String(),
        ]);

        $this->metrics->increment('velocity_fleet.polls_skipped', 1, [
            'reason' => $event->reason,
        ]);
    }

    public function handleRequestFailed(RequestFailed $event): void
    {
        $this->channel()->warning('velocity-fleet: request failed', [
            'customer_id' => $event->customerId,
            'status' => $event->status,
            'message' => $event->message,
            'occurred_at' => $event->occurredAt->toIso8601String(),
        ]);

        $this->metrics->increment('velocity_fleet.errors', 1, [
            'status' => $event->status ?? 0,
        ]);
    }

    public function handlePositionHistoryPruned(PositionHistoryPruned $event): void
    {
        $this->channel()->info('velocity-fleet: position history pruned', [
            'deleted' => $event->deleted,
            'occurred_at' => $event->occurredAt->toIso8601String(),
        ]);

        $this->metrics->increment('velocity_fleet.history_pruned', 1);
    }

    /**
     * Resolve the configured log channel, falling back to the application default
     * when no channel is configured at `velocity-fleet.logging.channel`.
     */
    private function channel(): LoggerInterface
    {
        $channel = config('velocity-fleet.logging.channel');

        if (is_string($channel) && $channel !== '') {
            return $this->log->channel($channel);
        }

        return $this->log;
    }
}

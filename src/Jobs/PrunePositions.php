<?php

declare(strict_types=1);

namespace ChrisJohnLeah\VelocityFleet\Laravel\Jobs;

use Carbon\CarbonImmutable;
use ChrisJohnLeah\VelocityFleet\Laravel\Events\PositionHistoryPruned;
use ChrisJohnLeah\VelocityFleet\Laravel\Models\DevicePositionRecord;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Retention prune: deletes stored {@see DevicePositionRecord} history older than
 * `velocity-fleet.retention.positions_days` (default 90) and fires
 * {@see PositionHistoryPruned} with the deleted-row count. Self-scheduled daily
 * by the provider when history is enabled.
 */
class PrunePositions implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle(Dispatcher $events): void
    {
        $now = CarbonImmutable::now();
        $cutoff = $now->subDays($this->retentionDays());

        $deleted = DevicePositionRecord::query()
            ->where('recorded_at', '<', $cutoff)
            ->delete();

        $count = is_int($deleted) ? $deleted : 0;

        $events->dispatch(new PositionHistoryPruned($count, $now));
    }

    private function retentionDays(): int
    {
        $value = config('velocity-fleet.retention.positions_days', 90);

        if (is_int($value)) {
            return $value > 0 ? $value : 90;
        }

        if (is_string($value) && is_numeric($value)) {
            $days = (int) $value;

            return $days > 0 ? $days : 90;
        }

        return 90;
    }
}

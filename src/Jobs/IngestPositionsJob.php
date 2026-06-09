<?php

declare(strict_types=1);

namespace ChrisJohnLeah\VelocityFleet\Laravel\Jobs;

use Carbon\CarbonImmutable;
use ChrisJohnLeah\VelocityFleet\Laravel\Models\DevicePositionRecord;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Persists a batch of pre-mapped position rows for one customer into the history
 * table. The write is an idempotent upsert keyed on the
 * (customer_id, device_id, recorded_at) unique index, so re-delivering the same
 * tick (at-least-once queues, retries, overlapping polls) never duplicates rows.
 *
 * Rows are pre-mapped (rather than carrying a fat {@see \ChrisJohnLeah\VelocityFleet\Data\DevicePositions})
 * to keep the serialized payload small and the encryption/persistence boundary clean.
 */
final class IngestPositionsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param  list<array<string, scalar|null>>  $rows
     */
    public function __construct(
        public readonly string $customerId,
        public readonly array $rows,
    ) {
    }

    public function handle(): void
    {
        if ($this->rows === []) {
            return;
        }

        $now = CarbonImmutable::now()->toDateTimeString();

        $rows = array_map(
            static function (array $row) use ($now): array {
                $row['created_at'] = $now;
                $row['updated_at'] = $now;

                return $row;
            },
            $this->rows,
        );

        // Idempotent: on conflict against the unique index we refresh the mutable
        // telemetry columns rather than inserting a duplicate row.
        DevicePositionRecord::query()->upsert(
            $rows,
            ['customer_id', 'device_id', 'recorded_at'],
            [
                'service_id',
                'lat',
                'lon',
                'speed',
                'direction',
                'ignition',
                'driver_name',
                'vehicle_registration',
                'mobile_phone',
                'signal_strength_color',
                'raw',
                'updated_at',
            ],
        );
    }
}

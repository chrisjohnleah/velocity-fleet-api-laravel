<?php

declare(strict_types=1);

use ChrisJohnLeah\VelocityFleet\Laravel\Events\PositionHistoryPruned;
use ChrisJohnLeah\VelocityFleet\Laravel\Jobs\PrunePositions;
use ChrisJohnLeah\VelocityFleet\Laravel\Models\DevicePositionRecord;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

/**
 * Ensures the position-history table exists for these tests even when the
 * sibling history module's migration is not part of the run. Mirrors the
 * `recorded_at` retention column the prune job filters on.
 */
function lifecycleEnsurePositionsTable(): string
{
    /** @var DevicePositionRecord $model */
    $model = new DevicePositionRecord();
    $table = $model->getTable();

    if (! Schema::hasTable($table)) {
        Schema::create($table, function (Blueprint $blueprint): void {
            $blueprint->id();
            $blueprint->string('customer_id')->nullable();
            $blueprint->integer('device_id')->nullable();
            $blueprint->timestamp('recorded_at')->nullable();
            $blueprint->timestamps();
        });
    }

    return $table;
}

it('prunes position history older than the retention window and fires the event', function () {
    config()->set('velocity-fleet.retention.positions_days', 30);
    lifecycleEnsurePositionsTable();
    Event::fake([PositionHistoryPruned::class]);

    $old = DevicePositionRecord::query()->create([
        'customer_id' => 'cust-1',
        'device_id' => 1,
        'recorded_at' => now()->subDays(45),
    ]);

    $recent = DevicePositionRecord::query()->create([
        'customer_id' => 'cust-1',
        'device_id' => 2,
        'recorded_at' => now()->subDays(5),
    ]);

    PrunePositions::dispatchSync();

    expect(DevicePositionRecord::query()->whereKey($old->getKey())->exists())->toBeFalse()
        ->and(DevicePositionRecord::query()->whereKey($recent->getKey())->exists())->toBeTrue();

    Event::assertDispatched(
        PositionHistoryPruned::class,
        static fn (PositionHistoryPruned $event): bool => $event->deleted === 1,
    );
});

it('reports the pruned count via the prune-positions command', function () {
    config()->set('velocity-fleet.retention.positions_days', 30);
    lifecycleEnsurePositionsTable();

    DevicePositionRecord::query()->create([
        'customer_id' => 'cust-2',
        'device_id' => 9,
        'recorded_at' => now()->subDays(120),
    ]);

    $this->artisan('velocity-fleet:prune-positions')
        ->expectsOutputToContain('Pruned 1 position-history row(s).')
        ->assertSuccessful();
});

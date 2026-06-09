<?php

declare(strict_types=1);

namespace ChrisJohnLeah\VelocityFleet\Laravel\Commands;

use ChrisJohnLeah\VelocityFleet\Laravel\Events\PositionHistoryPruned;
use ChrisJohnLeah\VelocityFleet\Laravel\Jobs\PrunePositions;
use Illuminate\Console\Command;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Runs the {@see PrunePositions} retention job synchronously and reports how
 * many stored position-history rows were deleted. The count is read from the
 * {@see PositionHistoryPruned} event the job fires.
 */
class PrunePositionsCommand extends Command
{
    protected $signature = 'velocity-fleet:prune-positions';

    protected $description = 'Delete stored device-position history past the configured retention window.';

    public function handle(Dispatcher $events): int
    {
        $deleted = 0;

        $events->listen(
            PositionHistoryPruned::class,
            static function (PositionHistoryPruned $event) use (&$deleted): void {
                $deleted = $event->deleted;
            },
        );

        PrunePositions::dispatchSync();

        $this->info(sprintf('Pruned %d position-history row(s).', $deleted));

        return self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace ChrisJohnLeah\VelocityFleet\Laravel\Jobs;

use ChrisJohnLeah\VelocityFleet\Laravel\Polling\FleetPoller;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

/**
 * Queued poll of a single customer's fleet. Guarded by {@see WithoutOverlapping}
 * keyed on the customer id so two workers (or an overlapping schedule tick) never
 * diff the same customer concurrently and double-fire change events.
 */
final class PollFleetJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $customerId,
    ) {
    }

    public function handle(FleetPoller $poller): void
    {
        $poller->poll($this->customerId);
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->customerId))
                ->expireAfter(180)
                ->dontRelease(),
        ];
    }
}

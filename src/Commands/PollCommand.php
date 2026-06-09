<?php

declare(strict_types=1);

namespace ChrisJohnLeah\VelocityFleet\Laravel\Commands;

use ChrisJohnLeah\VelocityFleet\Data\Customer;
use ChrisJohnLeah\VelocityFleet\Exceptions\NotConnectedException;
use ChrisJohnLeah\VelocityFleet\Exceptions\VelocityFleetException;
use ChrisJohnLeah\VelocityFleet\Laravel\Jobs\PollFleetJob;
use ChrisJohnLeah\VelocityFleet\Laravel\Support\RedactsSecrets;
use ChrisJohnLeah\VelocityFleet\VelocityFleet;
use Illuminate\Console\Command;

/**
 * Fans a {@see PollFleetJob} onto the queue for each resolved customer. This is
 * the unit the scheduler runs on the resolver cadence; it can also be invoked
 * by hand for one customer (`velocity-fleet:poll 12345`) or for every linked
 * customer (`velocity-fleet:poll`, optionally narrowed by the
 * `polling.customers` allow-list).
 */
class PollCommand extends Command
{
    use RedactsSecrets;

    protected $signature = 'velocity-fleet:poll {customer? : A single customer id to poll; omit to poll all configured customers}';

    protected $description = 'Dispatch a fleet-polling job for one or all Velocity Fleet customers.';

    public function handle(VelocityFleet $velocity): int
    {
        try {
            $customerIds = $this->resolveCustomerIds($velocity);
        } catch (NotConnectedException $exception) {
            $this->error($this->redact($exception->getMessage()));
            $this->line('Run `php artisan velocity-fleet:connect` first.');

            return self::FAILURE;
        } catch (VelocityFleetException $exception) {
            $this->error('Velocity Fleet API error: '.$this->redact($exception->getMessage()));

            return self::FAILURE;
        }

        if ($customerIds === []) {
            $this->warn('No customers to poll.');

            return self::SUCCESS;
        }

        $queue = $this->queue();

        foreach ($customerIds as $customerId) {
            $pending = PollFleetJob::dispatch($customerId);

            if ($queue !== null) {
                $pending->onQueue($queue);
            }
        }

        $this->info(sprintf('Dispatched %d fleet-polling job(s).', count($customerIds)));

        return self::SUCCESS;
    }

    /**
     * The customer ids to poll: the argument when given, otherwise every linked
     * customer narrowed by the `polling.customers` allow-list.
     *
     * @return list<string>
     */
    private function resolveCustomerIds(VelocityFleet $velocity): array
    {
        $argument = $this->argument('customer');

        if (is_string($argument) && $argument !== '') {
            return [$argument];
        }

        $allowed = $this->allowedCustomers();

        if ($allowed !== null && ! in_array('*', $allowed, true)) {
            return $allowed;
        }

        $ids = [];

        foreach ($velocity->customers()->list() as $customer) {
            if ($customer instanceof Customer && is_string($customer->id) && $customer->id !== '') {
                $ids[] = $customer->id;
            }
        }

        return $ids;
    }

    /**
     * The configured `polling.customers` allow-list, or null when unset.
     *
     * @return list<string>|null
     */
    private function allowedCustomers(): ?array
    {
        $configured = config('velocity-fleet.polling.customers');

        if (! is_array($configured)) {
            return null;
        }

        $ids = [];

        foreach ($configured as $value) {
            if (is_string($value) && $value !== '') {
                $ids[] = $value;
            }
        }

        return $ids;
    }

    private function queue(): ?string
    {
        $queue = config('velocity-fleet.polling.queue');

        return is_string($queue) && $queue !== '' ? $queue : null;
    }
}

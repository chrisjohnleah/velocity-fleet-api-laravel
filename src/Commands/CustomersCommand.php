<?php

declare(strict_types=1);

namespace ChrisJohnLeah\VelocityFleet\Laravel\Commands;

use ChrisJohnLeah\VelocityFleet\Data\Customer;
use ChrisJohnLeah\VelocityFleet\Exceptions\NotConnectedException;
use ChrisJohnLeah\VelocityFleet\Exceptions\VelocityFleetException;
use ChrisJohnLeah\VelocityFleet\VelocityFleet;
use Illuminate\Console\Command;

class CustomersCommand extends Command
{
    protected $signature = 'velocity-fleet:customers';

    protected $description = 'List the customers linked to the authenticated Velocity Fleet user.';

    public function handle(VelocityFleet $velocity): int
    {
        try {
            $customers = $velocity->customers()->list();
        } catch (NotConnectedException $exception) {
            $this->error($exception->getMessage());
            $this->line('Run `php artisan velocity-fleet:connect` first.');

            return self::FAILURE;
        } catch (VelocityFleetException $exception) {
            $this->error('Velocity Fleet API error: '.$exception->getMessage());

            return self::FAILURE;
        }

        if ($customers === []) {
            $this->warn('No customers are linked to this user.');

            return self::SUCCESS;
        }

        $this->table(['ID', 'Number', 'Name', 'Product'], array_map(
            static fn (Customer $customer): array => [
                $customer->id ?? '',
                $customer->number ?? '',
                $customer->name ?? '',
                $customer->product ?? '',
            ],
            $customers,
        ));

        return self::SUCCESS;
    }
}

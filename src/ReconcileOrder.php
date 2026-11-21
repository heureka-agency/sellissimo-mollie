<?php

namespace Sellissimo\Mollie;

use Illuminate\Console\Command;

class ReconcileOrder extends Command
{
    protected $signature = 'sellissimo:mollie:reconcile {order : Sellissimo order ID}';

    protected $description = 'Recover Mollie refunds and chargebacks for an order and its additional payments';

    public function handle(PaymentEvents $events): int
    {
        $events->order($this->argument('order'));
        $this->info('Mollie refunds and chargebacks are up to date.');

        return self::SUCCESS;
    }
}

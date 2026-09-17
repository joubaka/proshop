<?php

namespace App\Console\Commands;

use App\Shop\OrderOperationsService;
use Illuminate\Console\Command;

class ExpireShopReservations extends Command
{
    protected $signature = 'shop:expire-reservations';
    protected $description = 'Release expired unpaid online-shop stock reservations';

    public function handle(OrderOperationsService $orders): int
    {
        $this->info($orders->expireReservations().' expired shop order(s) released.');
        return self::SUCCESS;
    }
}

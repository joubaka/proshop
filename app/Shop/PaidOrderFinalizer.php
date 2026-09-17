<?php

namespace App\Shop;

interface PaidOrderFinalizer
{
    public function finalize(Order $order, Payment $payment): void;
}

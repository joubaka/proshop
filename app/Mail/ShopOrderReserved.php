<?php

namespace App\Mail;

use App\Shop\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ShopOrderReserved extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Order $order, public string $orderUrl) {}

    public function build(): self
    {
        return $this->subject('Your ProShop order '.$this->order->order_number)
            ->view('shop.emails.order-reserved');
    }
}

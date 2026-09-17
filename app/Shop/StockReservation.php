<?php

namespace App\Shop;

use Illuminate\Database\Eloquent\Model;

class StockReservation extends Model
{
    protected $table = 'shop_stock_reservations';
    protected $guarded = ['id'];
    protected $casts = ['expires_at' => 'datetime', 'confirmed_at' => 'datetime', 'released_at' => 'datetime'];
}

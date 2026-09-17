<?php

namespace App\Shop;

use Illuminate\Database\Eloquent\Model;

class OrderEvent extends Model
{
    public $timestamps = false;
    protected $table = 'shop_order_events';
    protected $guarded = ['id'];
    protected $casts = ['metadata' => 'array', 'created_at' => 'datetime'];
}

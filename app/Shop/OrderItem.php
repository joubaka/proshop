<?php

namespace App\Shop;

use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    protected $table = 'shop_order_items';
    protected $guarded = ['id'];
}

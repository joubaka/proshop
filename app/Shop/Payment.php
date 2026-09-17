<?php

namespace App\Shop;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $table = 'shop_payments';
    protected $guarded = ['id'];
    protected $casts = [
        'signature_verified' => 'boolean',
        'server_verified' => 'boolean',
        'received_at' => 'datetime',
        'finalized_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class, 'shop_order_id');
    }
}

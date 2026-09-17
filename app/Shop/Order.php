<?php

namespace App\Shop;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $table = 'shop_orders';
    protected $guarded = ['id'];
    protected $casts = [
        'billing_address' => 'array', 'delivery_address' => 'array', 'delivery_quote' => 'array',
        'reservation_expires_at' => 'datetime', 'paid_at' => 'datetime', 'cancelled_at' => 'datetime',
    ];

    public function channel() { return $this->belongsTo(Channel::class, 'shop_channel_id'); }
    public function items() { return $this->hasMany(OrderItem::class, 'shop_order_id'); }
    public function reservations() { return $this->hasMany(StockReservation::class, 'shop_order_id'); }
    public function events() { return $this->hasMany(OrderEvent::class, 'shop_order_id'); }
    public function payments() { return $this->hasMany(Payment::class, 'shop_order_id'); }
}

<?php

namespace App\Shop;

use App\Transaction;
use Illuminate\Database\Eloquent\Model;

class AccountPaymentAttempt extends Model
{
    protected $table = 'shop_account_payment_attempts';
    protected $keyType = 'string';
    public $incrementing = false;
    protected $guarded = [];
    protected $casts = ['received_at' => 'datetime', 'completed_at' => 'datetime'];

    public function customer() { return $this->belongsTo(Customer::class, 'shop_customer_id'); }
    public function transaction() { return $this->belongsTo(Transaction::class); }
}

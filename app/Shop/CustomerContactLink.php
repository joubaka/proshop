<?php

namespace App\Shop;

use App\Contact;
use Illuminate\Database\Eloquent\Model;

class CustomerContactLink extends Model
{
    protected $table = 'shop_customer_contact_links';
    protected $guarded = ['id'];
    protected $casts = ['verified_at' => 'datetime', 'revoked_at' => 'datetime'];

    public function customer() { return $this->belongsTo(Customer::class, 'shop_customer_id'); }
    public function contact() { return $this->belongsTo(Contact::class, 'contact_id'); }
}

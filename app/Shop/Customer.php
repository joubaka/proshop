<?php

namespace App\Shop;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class Customer extends Authenticatable implements MustVerifyEmail
{
    use Notifiable;

    protected $table = 'shop_customers';
    protected $guarded = ['id'];
    protected $hidden = ['password', 'remember_token'];
    protected $casts = [
        'active' => 'boolean',
        'email_verified_at' => 'datetime',
        'last_login_at' => 'datetime',
    ];

    public function links() { return $this->hasMany(CustomerContactLink::class, 'shop_customer_id'); }
    public function orders() { return $this->hasMany(Order::class, 'shop_customer_id'); }
    public function payments() { return $this->hasMany(AccountPaymentAttempt::class, 'shop_customer_id'); }

    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new \App\Notifications\ShopCustomerVerifyEmail);
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new \App\Notifications\ShopCustomerResetPassword($token));
    }
}

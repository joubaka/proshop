<?php

namespace App\Lights;

use Illuminate\Foundation\Auth\User;
use Illuminate\Notifications\Notifiable;

class Member extends User
{
    use Notifiable;
    protected $connection = 'lights';
    protected $table = 'lights_users';
    protected $fillable = ['name', 'email', 'password'];
    protected $hidden = ['password', 'remember_token'];
    protected $casts = ['is_admin' => 'boolean', 'active' => 'boolean', 'balance_cents' => 'integer',
        'email_verified_at' => 'integer', 'terms_accepted_at' => 'integer', 'last_login_at' => 'integer'];
}

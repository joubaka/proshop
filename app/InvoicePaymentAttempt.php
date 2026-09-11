<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class InvoicePaymentAttempt extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
}

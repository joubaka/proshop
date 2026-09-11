<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class InvoiceScan extends Model
{
    protected $fillable = [
        'uuid', 'business_id', 'location_id', 'supplier_id', 'created_by', 'reviewed_by',
        'purchase_transaction_id', 'status', 'provider', 'provider_reference', 'document_hash',
        'supplier_name', 'supplier_tax_number', 'invoice_number', 'invoice_date', 'currency',
        'subtotal', 'discount_total', 'tax_total', 'freight_total', 'invoice_total', 'confidence',
        'extracted_payload', 'failure_message', 'processed_at', 'reviewed_at', 'posted_at',
    ];

    protected $casts = [
        'invoice_date' => 'date',
        'extracted_payload' => 'array',
        'processed_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'posted_at' => 'datetime',
    ];

    public function documents() { return $this->hasMany(InvoiceScanDocument::class); }
    public function lines() { return $this->hasMany(InvoiceScanLine::class)->orderBy('line_order'); }
    public function supplier() { return $this->belongsTo(Contact::class, 'supplier_id'); }
    public function location() { return $this->belongsTo(BusinessLocation::class, 'location_id'); }
    public function purchase() { return $this->belongsTo(Transaction::class, 'purchase_transaction_id'); }
}

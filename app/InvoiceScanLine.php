<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class InvoiceScanLine extends Model
{
    protected $fillable = [
        'invoice_scan_id', 'variation_id', 'supplier_item_code', 'description', 'quantity', 'unit',
        'pack_size', 'unit_price', 'price_includes_tax', 'tax_rate', 'line_total', 'confidence',
        'match_method', 'match_confidence', 'proposed_sell_price', 'price_change_approved', 'line_order',
        'purchase_order_line_id', 'price_change_reason', 'price_approved_by',
    ];
    protected $casts = ['price_includes_tax' => 'boolean', 'price_change_approved' => 'boolean'];
    public function scan() { return $this->belongsTo(InvoiceScan::class, 'invoice_scan_id'); }
    public function variation() { return $this->belongsTo(Variation::class); }
    public function purchaseOrderLine() { return $this->belongsTo(PurchaseLine::class, 'purchase_order_line_id'); }
}

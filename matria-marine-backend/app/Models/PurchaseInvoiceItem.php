<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseInvoiceItem extends Model
{
    protected $fillable = [
        'purchase_invoice_id',
        'purchase_order_item_id',
        'rfq_item_id',
        'description',
        'unit',
        'qty',
        'unit_cost',
        'line_total',
        'account_code',
        'po_qty',
        'po_unit_cost',
        'sort',
    ];

    protected $casts = [
        'qty' => 'decimal:3',
        'unit_cost' => 'decimal:4',
        'line_total' => 'decimal:4',
        'po_qty' => 'decimal:3',
        'po_unit_cost' => 'decimal:4',
    ];

    public function purchaseInvoice()
    {
        return $this->belongsTo(PurchaseInvoice::class);
    }
}

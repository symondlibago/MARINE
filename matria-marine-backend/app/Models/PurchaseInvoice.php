<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseInvoice extends Model
{
    protected $fillable = [
        'reference',
        'vendor_invoice_no',
        'purchase_order_id',
        'rfq_id',
        'vendor_id',
        'currency',
        'base_currency',
        'exchange_rate',
        'status',
        'invoice_date',
        'due_date',
        'received_date',
        'subtotal',
        'other_charges',
        'total',
        'notes',
        'exported_at',
        'created_by',
    ];

    protected $casts = [
        'invoice_date' => 'date',
        'due_date' => 'date',
        'received_date' => 'date',
        'exported_at' => 'datetime',
        'exchange_rate' => 'decimal:8',
        'subtotal' => 'decimal:4',
        'other_charges' => 'decimal:4',
        'total' => 'decimal:4',
    ];

    public function items()
    {
        return $this->hasMany(PurchaseInvoiceItem::class)->orderBy('sort')->orderBy('id');
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function rfq()
    {
        return $this->belongsTo(Rfq::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Recompute subtotal (from lines) and total (subtotal + other charges). */
    public function recalcTotals(): void
    {
        $subtotal = (float) $this->items()->sum('line_total');
        $this->update([
            'subtotal' => $subtotal,
            'total' => $subtotal + (float) $this->other_charges,
        ]);
    }
}

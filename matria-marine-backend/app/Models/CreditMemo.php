<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Credit memo — credits part of an issued customer invoice back (damaged /
 * not delivered / price error). Net sales = invoice − issued credit memos.
 */
class CreditMemo extends Model
{
    protected $fillable = [
        'cm_number',
        'customer_invoice_id',
        'rfq_id',
        'customer_id',
        'customer_name',
        'customer_address',
        'currency',
        'status',
        'memo_date',
        'reason',
        'subtotal',
        'tax_rate',
        'tax_amount',
        'grand_total',
        'issued_at',
        'created_by',
    ];

    protected $casts = [
        'memo_date' => 'date',
        'issued_at' => 'datetime',
        'subtotal' => 'decimal:2',
        'tax_rate' => 'decimal:3',
        'tax_amount' => 'decimal:2',
        'grand_total' => 'decimal:2',
    ];

    public function items()
    {
        return $this->hasMany(CreditMemoItem::class)->orderBy('sort')->orderBy('id');
    }

    public function invoice()
    {
        return $this->belongsTo(CustomerInvoice::class, 'customer_invoice_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Recompute the credited totals; GST is credited at the invoice's rate. */
    public function recalcTotals(): void
    {
        $subtotal = round((float) $this->items()->sum('line_total'), 2);
        $tax = round($subtotal * (float) $this->tax_rate / 100, 2);

        $this->update([
            'subtotal' => $subtotal,
            'tax_amount' => $tax,
            'grand_total' => round($subtotal + $tax, 2),
        ]);
    }
}

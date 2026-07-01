<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CustomerInvoice extends Model
{
    protected $fillable = [
        'invoice_number',
        'token',
        'rfq_id',
        'offer_id',
        'delivery_order_id',
        'customer_id',
        'customer_name',
        'customer_address',
        'customer_reference',
        'currency',
        'status',
        'subtotal',
        'packing_cost',
        'transportation_cost',
        'tax_amount',
        'grand_total',
        'issue_date',
        'due_date',
        'payment_terms',
        'delivery_terms',
        'origin_type',
        'notes',
        'sent_at',
        'paid_at',
        'created_by',
    ];

    protected $casts = [
        'issue_date' => 'date',
        'due_date' => 'date',
        'sent_at' => 'datetime',
        'paid_at' => 'datetime',
        'subtotal' => 'decimal:2',
        'packing_cost' => 'decimal:2',
        'transportation_cost' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'grand_total' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (CustomerInvoice $invoice) {
            if (empty($invoice->token)) {
                $invoice->token = Str::random(48);
            }
        });
    }

    public function items()
    {
        return $this->hasMany(CustomerInvoiceItem::class)->orderBy('sort')->orderBy('id');
    }

    public function rfq()
    {
        return $this->belongsTo(Rfq::class);
    }

    public function offer()
    {
        return $this->belongsTo(Offer::class);
    }

    public function deliveryOrder()
    {
        return $this->belongsTo(DeliveryOrder::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Recompute the line subtotal and the grand total (subtotal + delivery + tax). */
    public function recalcTotals(): void
    {
        $subtotal = $this->items()->where('is_heading', false)->get()->sum(fn ($i) => (float) $i->line_total);
        $delivery = (float) $this->packing_cost + (float) $this->transportation_cost;

        $this->update([
            'subtotal' => round($subtotal, 2),
            'grand_total' => round($subtotal + $delivery + (float) $this->tax_amount, 2),
        ]);
    }
}

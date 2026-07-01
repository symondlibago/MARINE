<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PurchaseOrder extends Model
{
    protected $fillable = [
        'po_number',
        'invoice_number',
        'token',
        'rfq_id',
        'vendor_id',
        'ship_name',
        'delivery_port',
        'delivery_address',
        'currency',
        'base_currency',
        'exchange_rate',
        'status',
        'issued_date',
        'expected_date',
        'opened_at',
        'accepted_at',
        'accepted_by_name',
        'acceptance_note',
        'subtotal',
        'receipt_amount',
        'expenses',
        'expense_items',
        'expense_currency',
        'expense_rate',
        'paid_at',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'issued_date' => 'date',
        'expected_date' => 'date',
        'opened_at' => 'datetime',
        'accepted_at' => 'datetime',
        'paid_at' => 'datetime',
        'exchange_rate' => 'decimal:8',
        'expense_rate' => 'decimal:8',
        'subtotal' => 'decimal:4',
        'expense_items' => 'array',
    ];

    protected static function booted(): void
    {
        // Every PO gets a unique vendor magic-link token at creation.
        static::creating(function (PurchaseOrder $po) {
            if (empty($po->token)) {
                $po->token = Str::random(48);
            }
        });
    }

    public function items()
    {
        return $this->hasMany(PurchaseOrderItem::class)->orderBy('sort')->orderBy('id');
    }

    public function returnNote()
    {
        return $this->hasOne(ReturnNote::class);
    }

    public function attachments()
    {
        return $this->hasMany(PurchaseOrderAttachment::class)->latest();
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function rfq()
    {
        return $this->belongsTo(Rfq::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Recompute and persist the subtotal from the current line items (PO currency). */
    public function recalcSubtotal(): void
    {
        $this->update(['subtotal' => $this->items()->sum('line_total')]);
    }
}

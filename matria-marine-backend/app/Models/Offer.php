<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Offer extends Model
{
    protected $fillable = [
        'offer_number',
        // Assigned the first time a pro-forma is downloaded, then kept.
        'proforma_number',
        'token',
        'rfq_id',
        'customer_id',
        'customer_name',
        'customer_address',
        'currency',
        'status',
        'valid_until',
        'payment_terms',
        'delivery_terms',
        'origin_type',
        'customer_po_number',
        'base_total',
        'subtotal',
        'markup_total',
        // A discount over the whole quotation, separate from the per-line one.
        'discount_pct',
        'discount_amount',
        'packing_cost',
        'transportation_cost',
        'tax_rate',
        'tax_amount',
        'grand_total',
        'notes',
        'opened_at',
        'accepted_at',
        'accepted_by_name',
        'acceptance_note',
        'created_by',
    ];

    protected $casts = [
        'valid_until' => 'date',
        'opened_at' => 'datetime',
        'accepted_at' => 'datetime',
        'base_total' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'markup_total' => 'decimal:2',
        'discount_pct' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'packing_cost' => 'decimal:2',
        'transportation_cost' => 'decimal:2',
        'tax_rate' => 'decimal:3',
        'tax_amount' => 'decimal:2',
        'grand_total' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (Offer $offer) {
            if (empty($offer->token)) {
                $offer->token = Str::random(48);
            }
        });
    }

    public function items()
    {
        return $this->hasMany(OfferItem::class)->orderBy('sort')->orderBy('id');
    }

    public function rfq()
    {
        return $this->belongsTo(Rfq::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Recompute customer subtotal, internal base total and the markup (profit). */
    public function recalcTotals(): void
    {
        $lines = $this->items()->where('is_heading', false)->get();

        $base = $lines->sum(fn ($i) => (float) $i->base_price * (float) $i->qty);
        $subtotal = $lines->sum(fn ($i) => (float) $i->line_total);
        $delivery = (float) $this->packing_cost + (float) $this->transportation_cost;

        // A discount across the whole quotation, on top of anything already
        // taken off line by line (those are inside line_total above). Applied
        // to the items only, not to delivery: knocking 10% off the goods is not
        // an invitation to under-recover the freight.
        $discount = round($subtotal * (float) $this->discount_pct / 100, 2);
        $net = $subtotal - $discount;

        // GST follows the discounted figure — the supply is what is charged.
        $tax = round(($net + $delivery) * (float) $this->tax_rate / 100, 2);

        $this->update([
            'base_total' => round($base, 2),
            'subtotal' => round($subtotal, 2),
            // The mark-up we actually keep, once the discount is given away.
            'markup_total' => round($subtotal - $base - $discount, 2),
            'discount_amount' => $discount,
            'tax_amount' => $tax,
            'grand_total' => round($net + $delivery + $tax, 2),
        ]);
    }
}

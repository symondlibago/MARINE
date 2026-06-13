<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Offer extends Model
{
    protected $fillable = [
        'offer_number',
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
        'base_total',
        'subtotal',
        'markup_total',
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

        $this->update([
            'base_total' => round($base, 2),
            'subtotal' => round($subtotal, 2),
            'markup_total' => round($subtotal - $base, 2),
        ]);
    }
}

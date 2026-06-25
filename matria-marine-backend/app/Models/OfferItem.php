<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OfferItem extends Model
{
    protected $fillable = [
        'offer_id',
        'rfq_item_id',
        'award_id',
        'is_heading',
        'description',
        'code',
        'customs_code',
        'unit',
        'qty',
        'base_price',
        'markup_pct',
        'unit_price',
        'discount_pct',
        'discount_amount',
        'markup_amount',
        'line_total',
        'lead_time',
        'delivery_location',
        'remarks',
        'sort',
    ];

    protected $casts = [
        'is_heading' => 'boolean',
        'qty' => 'decimal:3',
        'base_price' => 'decimal:4',
        'markup_pct' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'discount_pct' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'markup_amount' => 'decimal:2',
        'line_total' => 'decimal:2',
    ];

    public function offer()
    {
        return $this->belongsTo(Offer::class);
    }
}

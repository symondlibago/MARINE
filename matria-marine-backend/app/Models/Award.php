<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Award extends Model
{
    protected $fillable = [
        'rfq_item_id',
        'vendor_id',
        'quote_item_id',
        'qty_to_buy',
        'unit_cost',
    ];

    protected $casts = [
        'qty_to_buy' => 'decimal:3',
        'unit_cost' => 'decimal:4',
    ];

    public function rfqItem()
    {
        return $this->belongsTo(RfqItem::class);
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function quoteItem()
    {
        return $this->belongsTo(QuoteItem::class);
    }
}

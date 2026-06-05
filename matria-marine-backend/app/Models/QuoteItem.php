<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuoteItem extends Model
{
    protected $fillable = [
        'quote_id',
        'rfq_item_id',
        'unit_cost',
        'qty_quoted',
        'remarks',
    ];

    protected $casts = [
        'unit_cost' => 'decimal:4',
        'qty_quoted' => 'decimal:3',
    ];

    public function quote()
    {
        return $this->belongsTo(Quote::class);
    }

    public function rfqItem()
    {
        return $this->belongsTo(RfqItem::class);
    }
}

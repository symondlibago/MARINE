<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RfqItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'rfq_id',
        'catalogue_item_id',
        'description',
        'qty',
        'unit',
        'sort',
    ];

    protected $casts = [
        'qty' => 'decimal:3',
    ];

    public function rfq()
    {
        return $this->belongsTo(Rfq::class);
    }

    public function quoteItems()
    {
        return $this->hasMany(QuoteItem::class);
    }

    /**
     * A line can be split across vendors — one award per vendor.
     * This is the relation to use everywhere.
     */
    public function awards()
    {
        return $this->hasMany(Award::class);
    }

    /**
     * The single award on a line that was not split.
     *
     * Kept for the many places that only ever deal with one vendor per line;
     * on a split line it returns the first award, so always prefer awards().
     */
    public function award()
    {
        return $this->hasOne(Award::class);
    }

    /** Total quantity awarded across every vendor on this line. */
    public function awardedQty(): float
    {
        return (float) $this->awards->sum(fn (Award $a) => (float) $a->qty_to_buy);
    }
}

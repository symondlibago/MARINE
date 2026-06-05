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

    public function award()
    {
        return $this->hasOne(Award::class);
    }
}

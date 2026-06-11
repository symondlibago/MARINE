<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentItem extends Model
{
    protected $fillable = [
        'document_id',
        'is_heading',
        'description',
        'unit',
        'qty',
        'unit_price',
        'amount',
        'sort',
    ];

    protected $casts = [
        'is_heading' => 'boolean',
        'qty' => 'decimal:3',
        'unit_price' => 'decimal:2',
        'amount' => 'decimal:2',
    ];

    public function document()
    {
        return $this->belongsTo(Document::class);
    }
}

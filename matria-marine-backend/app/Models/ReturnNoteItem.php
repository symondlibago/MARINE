<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReturnNoteItem extends Model
{
    protected $fillable = [
        'return_note_id',
        'purchase_order_item_id',
        'description',
        'unit',
        'qty',
        'unit_cost',
        'line_total',
        'reason',
        'sort',
    ];

    protected $casts = [
        'qty' => 'decimal:3',
        'unit_cost' => 'decimal:4',
        'line_total' => 'decimal:4',
    ];

    public function returnNote()
    {
        return $this->belongsTo(ReturnNote::class);
    }

    public function purchaseOrderItem()
    {
        return $this->belongsTo(PurchaseOrderItem::class);
    }
}

<?php

namespace App\Models\Inventory;

use Illuminate\Database\Eloquent\Model;

/** One line of a delivery: what was ordered vs what actually arrived. */
class ReceiptItem extends Model
{
    protected $table = 'provision_receipt_items';

    protected $fillable = [
        'receipt_id', 'item_id', 'ordered_qty', 'received_qty', 'unit_price', 'expires_on', 'notes', 'sort',
    ];

    protected $casts = [
        'ordered_qty' => 'decimal:3',
        'received_qty' => 'decimal:3',
        'unit_price' => 'decimal:4',
        'expires_on' => 'date',
        'sort' => 'integer',
    ];

    public function item()
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    public function receipt()
    {
        return $this->belongsTo(Receipt::class, 'receipt_id');
    }

    /** Short by how much (positive) or over-delivered (negative). */
    public function difference(): float
    {
        return round((float) $this->received_qty - (float) $this->ordered_qty, 3);
    }
}

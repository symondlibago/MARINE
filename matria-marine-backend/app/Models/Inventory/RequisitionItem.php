<?php

namespace App\Models\Inventory;

use Illuminate\Database\Eloquent\Model;

/**
 * A requisition line. Description, unit and price are snapshotted so a saved
 * order still prints correctly after the catalogue changes.
 */
class RequisitionItem extends Model
{
    protected $table = 'provision_requisition_items';

    protected $fillable = [
        'requisition_id',
        'item_id',
        'code',
        'description',
        'unit',
        'rob_at_issue',
        'target_qty',
        'order_qty',
        'unit_price',
        'line_total',
        'sort',
    ];

    protected $casts = [
        'rob_at_issue' => 'decimal:3',
        'target_qty' => 'decimal:3',
        'order_qty' => 'decimal:3',
        'unit_price' => 'decimal:4',
        'line_total' => 'decimal:2',
        'sort' => 'integer',
    ];

    public function item()
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    public function requisition()
    {
        return $this->belongsTo(Requisition::class, 'requisition_id');
    }
}

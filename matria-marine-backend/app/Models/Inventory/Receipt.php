<?php

namespace App\Models\Inventory;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** A delivery received on board. Confirming it raises ROB. */
class Receipt extends Model
{
    protected $table = 'provision_receipts';

    protected $fillable = [
        'vessel_id',
        'supplier_id',
        'requisition_id',
        'reference',
        'received_date',
        'status',
        'confirmed_at',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'received_date' => 'date',
        'confirmed_at' => 'datetime',
    ];

    public function items()
    {
        return $this->hasMany(ReceiptItem::class, 'receipt_id')->orderBy('sort')->orderBy('id');
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function requisition()
    {
        return $this->belongsTo(Requisition::class, 'requisition_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isConfirmed(): bool
    {
        return $this->status === 'confirmed';
    }

    /** Value of what arrived, using the receipt's own prices where given. */
    public function totalValue(): float
    {
        return round($this->items->sum(fn (ReceiptItem $i) => (float) $i->received_qty * (float) ($i->unit_price ?? 0)), 2);
    }
}

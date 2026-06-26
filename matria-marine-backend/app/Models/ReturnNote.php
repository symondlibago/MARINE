<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReturnNote extends Model
{
    protected $fillable = [
        'rtn_number',
        'purchase_order_id',
        'rfq_id',
        'vendor_id',
        'vendor_name',
        'currency',
        'status',
        'return_date',
        'subtotal',
        'notes',
        'issued_at',
        'created_by',
    ];

    protected $casts = [
        'return_date' => 'date',
        'issued_at' => 'datetime',
        'subtotal' => 'decimal:4',
    ];

    public function items()
    {
        return $this->hasMany(ReturnNoteItem::class)->orderBy('sort')->orderBy('id');
    }

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Recompute and persist the total credited back from the current return lines. */
    public function recalcSubtotal(): void
    {
        $this->update(['subtotal' => $this->items()->sum('line_total')]);
    }
}

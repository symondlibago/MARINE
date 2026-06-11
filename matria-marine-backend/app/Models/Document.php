<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Document extends Model
{
    protected $fillable = [
        'type',
        'number',
        'party_kind',
        'customer_id',
        'vendor_id',
        'party_name',
        'party_address',
        'party_email',
        'date',
        'currency',
        'order_number',
        'terms',
        'subtotal',
        'gst_rate',
        'gst_amount',
        'total',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'date' => 'date',
        'subtotal' => 'decimal:2',
        'gst_rate' => 'decimal:2',
        'gst_amount' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    public function items()
    {
        return $this->hasMany(DocumentItem::class)->orderBy('sort')->orderBy('id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    /** Recompute subtotal (priced lines only), GST and total. */
    public function recalcTotals(): void
    {
        $subtotal = (float) $this->items()->where('is_heading', false)->sum('amount');
        $gst = round($subtotal * (float) $this->gst_rate / 100, 2);
        $this->update([
            'subtotal' => $subtotal,
            'gst_amount' => $gst,
            'total' => $subtotal + $gst,
        ]);
    }
}

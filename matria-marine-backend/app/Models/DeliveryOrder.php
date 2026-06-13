<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeliveryOrder extends Model
{
    protected $fillable = [
        'do_number',
        'offer_id',
        'rfq_id',
        'customer_id',
        'customer_name',
        'customer_address',
        'delivery_address',
        'customer_reference',
        'currency',
        'status',
        'order_date',
        'readiness_date',
        'subtotal',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'order_date' => 'date',
        'readiness_date' => 'date',
        'subtotal' => 'decimal:2',
    ];

    public function items()
    {
        return $this->hasMany(DeliveryOrderItem::class)->orderBy('sort')->orderBy('id');
    }

    public function offer()
    {
        return $this->belongsTo(Offer::class);
    }

    public function rfq()
    {
        return $this->belongsTo(Rfq::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function recalcTotals(): void
    {
        $this->update(['subtotal' => round($this->items()->sum('line_total'), 2)]);
    }
}

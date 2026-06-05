<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Rfq extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference',
        'ship_name',
        'requested_by',
        'delivery_port',
        'received_date',
        'base_currency',
        'status',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'received_date' => 'date',
    ];

    public function items()
    {
        return $this->hasMany(RfqItem::class)->orderBy('sort')->orderBy('id');
    }

    public function rfqVendors()
    {
        return $this->hasMany(RfqVendor::class);
    }

    public function vendors()
    {
        return $this->belongsToMany(Vendor::class, 'rfq_vendors')
            ->withPivot(['token', 'status', 'sent_at', 'opened_at', 'responded_at'])
            ->withTimestamps();
    }

    public function quotes()
    {
        return $this->hasMany(Quote::class);
    }

    public function purchaseOrders()
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public function purchaseInvoices()
    {
        return $this->hasMany(PurchaseInvoice::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Rfq extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference',
        'customer_id',
        'customer_reference',
        'priority',
        'requirements',
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
        'requirements' => 'array',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

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

    /**
     * The customer quotation built from this enquiry. One per enquiry — see
     * OfferController::generate(), which returns the existing one rather than
     * making a second. Carries the money, so the enquiry list can show what a
     * job is worth without opening it.
     */
    public function offer()
    {
        return $this->hasOne(Offer::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Customer files staff attached to this enquiry — internal only. */
    public function attachments()
    {
        return $this->hasMany(RfqAttachment::class);
    }
}

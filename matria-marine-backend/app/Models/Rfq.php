<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Rfq extends Model
{
    use Concerns\StampsOrigin, HasFactory;

    /**
     * The head of the chain: an enquiry has no parent document, so its origin
     * is whatever raised it. The bridge from a vessel's requisition says so
     * explicitly; everything else is the chandling trade.
     */
    protected array $originParents = [];

    protected $fillable = [
        'origin',
        'reference',
        'requisition_id',
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

    /**
     * The vessel's requisition this enquiry was raised from, where there was
     * one. Null for the chandling trade, which is most of them.
     */
    public function requisition()
    {
        return $this->belongsTo(\App\Models\Inventory\Requisition::class, 'requisition_id');
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

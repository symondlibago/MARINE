<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RfqVendor extends Model
{
    protected $table = 'rfq_vendors';

    protected $fillable = [
        'rfq_id',
        'vendor_id',
        'token',
        'seq',
        'sent_at',
        'opened_at',
        'responded_at',
        'status',
        'channel',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'opened_at' => 'datetime',
        'responded_at' => 'datetime',
    ];

    public function rfq()
    {
        return $this->belongsTo(Rfq::class);
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * The line items this vendor was asked to quote on. EMPTY means the vendor
     * was asked for every item on the enquiry (the default when no subset was
     * chosen at send time).
     */
    public function items()
    {
        return $this->belongsToMany(RfqItem::class, 'rfq_vendor_items');
    }
}

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
}

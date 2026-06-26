<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Quote extends Model
{
    use HasFactory;

    protected $fillable = [
        'rfq_id',
        'vendor_id',
        'quotation_number',
        'currency',
        'exchange_rate',
        'valid_until',
        'status',
        'submitted_at',
    ];

    protected $casts = [
        'valid_until' => 'date',
        'submitted_at' => 'datetime',
        'exchange_rate' => 'decimal:8',
    ];

    public function rfq()
    {
        return $this->belongsTo(Rfq::class);
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function items()
    {
        return $this->hasMany(QuoteItem::class);
    }

    public function attachments()
    {
        return $this->hasMany(QuoteAttachment::class);
    }
}

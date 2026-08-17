<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Proof filed against a payment — the bank slip, the invoice copy, whatever
 * the accountant needs to retrieve later when reconciling. Private on R2 and
 * only ever served through a short-lived signed URL.
 */
class PaymentAttachment extends Model
{
    protected $fillable = [
        'payment_id',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size',
        'kind',
        'uploaded_by',
    ];

    protected $casts = [
        'size' => 'integer',
    ];

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }
}

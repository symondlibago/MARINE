<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A file staff attached to an enquiry.
 *
 * Internal by default — the customer's own paperwork must never reach a
 * supplier. Ticking share_with_vendors marks a file (a drawing or spec sheet)
 * to ride along with the enquiry email to vendors.
 */
class RfqAttachment extends Model
{
    protected $fillable = [
        'rfq_id',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size',
        'share_with_vendors',
        'uploaded_by',
    ];

    protected $casts = [
        'size' => 'integer',
        'share_with_vendors' => 'boolean',
    ];

    /** Files that go out with the enquiry email. */
    public function scopeSharedWithVendors($query)
    {
        return $query->where('share_with_vendors', true);
    }

    public function rfq()
    {
        return $this->belongsTo(Rfq::class);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}

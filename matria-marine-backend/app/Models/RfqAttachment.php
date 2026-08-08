<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A file staff attached to an enquiry as a whole — the customer's paperwork.
 *
 * Strictly internal: nothing here is ever emailed to a supplier. Files meant
 * for vendors belong on the line they describe, as RfqItemAttachment, and go
 * out with whichever vendors are asked to quote that line.
 *
 * (The share_with_vendors column is left in place from the earlier design so
 * no data is destroyed, but nothing reads it any more.)
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
        'uploaded_by',
    ];

    protected $casts = [
        'size' => 'integer',
    ];

    public function rfq()
    {
        return $this->belongsTo(Rfq::class);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}

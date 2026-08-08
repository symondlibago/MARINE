<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A file belonging to one enquiry line — the photo, drawing or spec sheet for
 * that specific part. Unlike enquiry-wide files these are vendor-facing: a
 * vendor asked to quote the line receives them with the enquiry email.
 */
class RfqItemAttachment extends Model
{
    protected $fillable = [
        'rfq_item_id',
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

    public function item()
    {
        return $this->belongsTo(RfqItem::class, 'rfq_item_id');
    }
}

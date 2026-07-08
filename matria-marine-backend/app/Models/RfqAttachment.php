<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A customer file staff attached to an enquiry (internal only). */
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

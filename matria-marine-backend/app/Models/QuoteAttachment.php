<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuoteAttachment extends Model
{
    protected $fillable = [
        'quote_id',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size',
    ];

    protected $casts = [
        'size' => 'integer',
    ];

    public function quote()
    {
        return $this->belongsTo(Quote::class);
    }
}

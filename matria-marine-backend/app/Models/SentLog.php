<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SentLog extends Model
{
    protected $fillable = [
        'type',
        'reference',
        'recipient_name',
        'recipient_email',
        'subject',
        'status',
        'error',
        'sent_by',
        'sent_by_name',
    ];

    /** Record a send (or a failed send) for the audit trail. */
    public static function record(array $attrs): self
    {
        return static::create($attrs);
    }
}

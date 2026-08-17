<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A record of one change to a document numbering series. */
class DocumentCounterAudit extends Model
{
    protected $fillable = [
        'key',
        'from_seq',
        'to_seq',
        'reason',
        'changed_by',
    ];

    protected $casts = [
        'from_seq' => 'integer',
        'to_seq' => 'integer',
    ];

    public function changedBy()
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}

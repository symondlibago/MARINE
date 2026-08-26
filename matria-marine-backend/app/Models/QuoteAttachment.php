<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuoteAttachment extends Model
{
    /**
     * Files staff upload on a vendor's behalf are stored under this sub-folder.
     * The folder IS the marker — no column, no migration. Anything under it is
     * internal: it never appears on the vendor's own quote link, and the vendor
     * cannot delete it. Move the folder and you break that separation, so both
     * sides go through staffPathFor() / isInternal() and nothing else.
     */
    public const STAFF_DIR = 'staff';

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

    /** Where a staff upload for this quote is stored. */
    public static function staffPathFor(int $quoteId): string
    {
        return 'quotes/'.$quoteId.'/'.self::STAFF_DIR;
    }

    /** True when staff filed this themselves, so the vendor must not see it. */
    public function isInternal(): bool
    {
        return str_contains((string) $this->path, '/'.self::STAFF_DIR.'/');
    }
}

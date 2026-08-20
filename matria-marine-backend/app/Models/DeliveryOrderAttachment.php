<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A file filed against a delivery order — normally the copy the vessel signed.
 *
 * Internal evidence only: nothing here is ever sent to a customer.
 */
class DeliveryOrderAttachment extends Model
{
    /** The kinds the office can file, and how they read on screen. */
    public const KINDS = [
        'signed_do' => 'Signed delivery order',
        'packing_list' => 'Packing list',
        'photo' => 'Photo',
        'other' => 'Other',
    ];

    protected $fillable = [
        'delivery_order_id', 'disk', 'path', 'original_name',
        'mime_type', 'size', 'kind', 'note', 'uploaded_by',
    ];

    public function deliveryOrder()
    {
        return $this->belongsTo(DeliveryOrder::class);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}

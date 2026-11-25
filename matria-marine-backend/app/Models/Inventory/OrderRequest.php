<?php

namespace App\Models\Inventory;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Something the cook wants on the next order, whatever the calculation says.
 *
 * Deliberately not part of the calculation. The requisition lists what is
 * below target because that is the honest default; this is the cook's reason
 * for wanting something anyway, and the two are kept apart so neither spoils
 * the other. Merged into the next order and marked as asked for, then cleared.
 */
class OrderRequest extends Model
{
    protected $table = 'provision_order_requests';

    protected $fillable = [
        'vessel_id',
        'item_id',
        'quantity',
        'note',
        'created_by',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
    ];

    public function item()
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

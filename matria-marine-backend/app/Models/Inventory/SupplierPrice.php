<?php

namespace App\Models\Inventory;

use Illuminate\Database\Eloquent\Model;

/** One supplier's quoted price for one item, in the supplier's own currency. */
class SupplierPrice extends Model
{
    protected $table = 'provision_supplier_prices';

    protected $fillable = ['supplier_id', 'item_id', 'price', 'quoted_at'];

    protected $casts = [
        'price' => 'decimal:4',
        'quoted_at' => 'date',
    ];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function item()
    {
        return $this->belongsTo(Item::class, 'item_id');
    }
}

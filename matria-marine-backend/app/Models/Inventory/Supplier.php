<?php

namespace App\Models\Inventory;

use Illuminate\Database\Eloquent\Model;

/** A provision supplier at a port; prices are compared across suppliers. */
class Supplier extends Model
{
    protected $table = 'provision_suppliers';

    protected $fillable = [
        'vessel_id',
        'name',
        'port',
        'contact_name',
        'email',
        'phone',
        'currency',
        'exchange_rate',
        'notes',
        'is_active',
    ];

    protected $casts = [
        'exchange_rate' => 'decimal:6',
        'is_active' => 'boolean',
    ];

    public function prices()
    {
        return $this->hasMany(SupplierPrice::class, 'supplier_id');
    }
}

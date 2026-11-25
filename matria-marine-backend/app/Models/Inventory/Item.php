<?php

namespace App\Models\Inventory;

use Illuminate\Database\Eloquent\Model;

/**
 * A provision item. Holds inputs only — target quantity, order quantity,
 * order value and status are computed by ProvisionCalculator on read.
 */
class Item extends Model
{
    protected $table = 'provision_items';

    protected $fillable = [
        'vessel_id',
        'category_id',
        'code',
        'name',
        'variant',
        'unit',
        'unit_price',
        'shelf_life_days',
        'current_stock',
        'baseline_consumption',
        'baseline_period_days',
        'manual_target',
        'notes',
        'is_active',
        'sort',
    ];

    protected $casts = [
        'unit_price' => 'decimal:4',
        'shelf_life_days' => 'integer',
        'current_stock' => 'decimal:3',
        'baseline_consumption' => 'decimal:3',
        'baseline_period_days' => 'integer',
        'manual_target' => 'decimal:3',
        'is_active' => 'boolean',
        'sort' => 'integer',
    ];

    public function category()
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function vessel()
    {
        return $this->belongsTo(Vessel::class, 'vessel_id');
    }

    public function supplierPrices()
    {
        return $this->hasMany(SupplierPrice::class, 'item_id');
    }

    /** Full label for print-outs: "Beef Tenderloin (Norway)". */
    public function label(): string
    {
        return trim($this->name.' '.(string) $this->variant);
    }
}

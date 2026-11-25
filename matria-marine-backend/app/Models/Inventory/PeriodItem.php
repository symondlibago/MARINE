<?php

namespace App\Models\Inventory;

use Illuminate\Database\Eloquent\Model;

/**
 * One item's movement inside a supply period.
 * Consumption = beginning_qty + supplied_qty - ending_qty.
 */
class PeriodItem extends Model
{
    protected $table = 'provision_period_items';

    protected $fillable = ['period_id', 'item_id', 'beginning_qty', 'supplied_qty', 'ending_qty'];

    protected $casts = [
        'beginning_qty' => 'decimal:3',
        'supplied_qty' => 'decimal:3',
        'ending_qty' => 'decimal:3',
    ];

    public function period()
    {
        return $this->belongsTo(SupplyPeriod::class, 'period_id');
    }

    public function item()
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    /** Measured usage over the period; null while the period is still open. */
    public function consumption(): ?float
    {
        if ($this->ending_qty === null) {
            return null;
        }

        return max(0, (float) $this->beginning_qty + (float) $this->supplied_qty - (float) $this->ending_qty);
    }
}

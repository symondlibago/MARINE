<?php

namespace App\Models\Inventory;

use Illuminate\Database\Eloquent\Model;

/**
 * A vessel and the reference values that drive every provision calculation.
 * Today there is one row (OEL VARUN); the schema already supports a fleet.
 */
class Vessel extends Model
{
    protected $table = 'provision_vessels';

    protected $fillable = [
        'name',
        'imo',
        // The ordinary customer record that stands for her when the office
        // buys on her behalf — see App\Support\Inventory\RequisitionBridge.
        'customer_id',
        'crew_size',
        'previous_crew_size',
        'supply_period_days',
        'safety_margin_pct',
        'budget',
        'ration_rate',
        'visitor_meal_rate',
        'charterer_meal_rate',
        'currency',
        'exchange_rate',
        'supply_date',
        'next_supply_date',
        'is_active',
    ];

    protected $casts = [
        'crew_size' => 'integer',
        'previous_crew_size' => 'integer',
        'supply_period_days' => 'integer',
        'safety_margin_pct' => 'decimal:2',
        'budget' => 'decimal:2',
        'ration_rate' => 'decimal:2',
        'visitor_meal_rate' => 'decimal:2',
        'charterer_meal_rate' => 'decimal:2',
        'exchange_rate' => 'decimal:6',
        'supply_date' => 'date',
        'next_supply_date' => 'date',
        'is_active' => 'boolean',
    ];

    public function items()
    {
        return $this->hasMany(Item::class, 'vessel_id');
    }

    public function periods()
    {
        return $this->hasMany(SupplyPeriod::class, 'vessel_id');
    }

    public function crewRations()
    {
        return $this->hasMany(CrewRation::class, 'vessel_id');
    }

    /** The roster — who is aboard, and under which ration category. */
    public function crew()
    {
        return $this->hasMany(CrewMember::class, 'vessel_id');
    }

    public function suppliers()
    {
        return $this->hasMany(Supplier::class, 'vessel_id');
    }

    public function requisitions()
    {
        return $this->hasMany(Requisition::class, 'vessel_id');
    }

    public function receipts()
    {
        return $this->hasMany(Receipt::class, 'vessel_id');
    }

    /**
     * The accounts that sail on her — normally two, the master and the cook.
     *
     * Deliberately not restricted to those two ranks: an office account is
     * never given a vessel_id, so anything here is on board by construction,
     * and a relief master during a handover should not break the list.
     */
    public function accounts()
    {
        return $this->hasMany(\App\Models\User::class, 'vessel_id');
    }

    /**
     * Whether this vessel has anything worth keeping.
     *
     * A vessel added by mistake can be deleted outright; one that has ordered,
     * received, counted or fed anybody is part of the fleet's record and is
     * archived instead, so its figures stay readable.
     */
    public function hasHistory(): bool
    {
        return $this->requisitions()->exists()
            || $this->receipts()->exists()
            || $this->periods()->exists()
            || $this->crewRations()->exists();
    }

    /**
     * The first vessel, for a module that has not been told which to use.
     *
     * Kept only for seeding and for tests that predate the fleet. Controllers
     * must take the vessel from the request — see InventoryController::vessel.
     */
    public static function current(): self
    {
        return static::query()->orderBy('id')->firstOr(fn () => static::create([
            'name' => 'OEL VARUN',
            'crew_size' => 20,
            'supply_period_days' => 30,
            'safety_margin_pct' => 10,
            'currency' => 'USD',
            'exchange_rate' => 1,
        ]));
    }
}

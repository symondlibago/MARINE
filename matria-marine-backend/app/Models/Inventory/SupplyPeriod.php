<?php

namespace App\Models\Inventory;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * A supply period (roughly a month). Closing one records the counted ending
 * stock per item, which is what makes consumption measurable.
 */
class SupplyPeriod extends Model
{
    protected $table = 'provision_supply_periods';

    protected $fillable = [
        'vessel_id',
        'label',
        'start_date',
        'end_date',
        'days',
        'crew_size',
        'status',
        'closed_at',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'days' => 'integer',
        'crew_size' => 'integer',
        'closed_at' => 'datetime',
    ];

    public function items()
    {
        return $this->hasMany(PeriodItem::class, 'period_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function vessel()
    {
        return $this->belongsTo(Vessel::class, 'vessel_id');
    }

    /** The period currently collecting supplies, if any. */
    public static function open(int $vesselId): ?self
    {
        return static::where('vessel_id', $vesselId)->where('status', 'open')->latest('id')->first();
    }

    /** The most recently closed period — the one that drives ordering. */
    public static function lastClosed(int $vesselId): ?self
    {
        return static::where('vessel_id', $vesselId)
            ->where('status', 'closed')
            ->orderByDesc('end_date')
            ->orderByDesc('id')
            ->first();
    }
}

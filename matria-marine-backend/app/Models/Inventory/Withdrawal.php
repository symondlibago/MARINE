<?php

namespace App\Models\Inventory;

use App\Models\User;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Something taken out of the store: eaten, thrown away, or landed.
 *
 * `cost` is what FIFO charged at the moment it was taken, and it is stored
 * rather than recomputed. That is the one exception to this module's rule that
 * nothing derived is kept — see the migration for why: the answer depends on
 * which lots were on board that day, and re-deriving it after the next
 * delivery would move last month's figures.
 */
class Withdrawal extends Model
{
    protected $table = 'provision_withdrawals';

    /** Why it left the store. Only the galley actually fed anybody. */
    public const GALLEY = 'galley';
    public const WASTE = 'waste';
    public const TRANSFER = 'transfer';

    public const REASONS = [
        self::GALLEY => 'To the galley',
        self::WASTE => 'Spoiled or expired',
        self::TRANSFER => 'Landed or transferred',
    ];

    protected $fillable = [
        'vessel_id',
        'item_id',
        'period_id',
        'date',
        'quantity',
        'cost',
        'reason',
        'note',
        'created_by',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'cost' => 'decimal:4',
    ];

    /**
     * A date, held as a date.
     *
     * Not the plain 'date' cast, which writes "2026-06-01 00:00:00" into a DATE
     * column. MySQL truncates that and hides the problem; SQLite keeps it, so a
     * lookup by date stops matching and a range query drops its last day.
     */
    protected function date(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value === null ? null : Carbon::parse($value)->startOfDay(),
            set: fn ($value) => $value === null ? null : Carbon::parse($value)->toDateString(),
        );
    }

    public function vessel()
    {
        return $this->belongsTo(Vessel::class, 'vessel_id');
    }

    public function item()
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    public function period()
    {
        return $this->belongsTo(SupplyPeriod::class, 'period_id');
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Which lots it came out of, and at what price. */
    public function lots()
    {
        return $this->hasMany(WithdrawalLot::class, 'withdrawal_id');
    }
}

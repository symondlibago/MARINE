<?php

namespace App\Models\Inventory;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A batch of one item: a quantity, at the price it cost, with the day it goes
 * off. Holds inputs only — what it is worth and whether it has expired are
 * read off it, never stored.
 *
 * Created by receiving, eaten by withdrawals, and never edited in place except
 * for `remaining`. Movements go through App\Support\Inventory\StockLedger,
 * which is the only thing that writes to this table.
 */
class StockLot extends Model
{
    protected $table = 'provision_stock_lots';

    /** Where a lot came from. */
    public const FROM_RECEIPT = 'receipt';
    public const FROM_OPENING = 'opening';
    public const FROM_ADJUSTMENT = 'adjustment';

    protected $fillable = [
        'vessel_id',
        'item_id',
        'receipt_id',
        'received_on',
        'expires_on',
        'unit_cost',
        'quantity',
        'remaining',
        'source',
        'note',
    ];

    protected $casts = [
        'received_on' => 'date',
        'expires_on' => 'date',
        'unit_cost' => 'decimal:4',
        'quantity' => 'decimal:3',
        'remaining' => 'decimal:3',
    ];

    public function item()
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    public function receipt()
    {
        return $this->belongsTo(Receipt::class, 'receipt_id');
    }

    /**
     * Oldest first, and only lots with something left in them.
     *
     * This is FIFO, in both senses that matter: the order the food was bought
     * and the order its cost is charged. Ordering by id after the date keeps
     * two deliveries on the same day in the sequence they were booked.
     */
    public function scopeFifo($query)
    {
        return $query->where('remaining', '>', 0)
            ->orderBy('received_on')
            ->orderBy('id');
    }

    /** What is left of this lot is worth this much. */
    public function value(): float
    {
        return round((float) $this->remaining * (float) $this->unit_cost, 4);
    }

    /**
     * When this batch expires — the date off the packet, or the item's shelf
     * life counted from the day it arrived.
     *
     * THE ONE DEFINITION. The batch board, the inventory list and the dashboard
     * all read expiry through here, so a date cannot say one thing on the board
     * and another on the card beside it.
     *
     * Derived rather than stored, which is the point. A shelf life used to be
     * stamped onto the batch at the moment of receiving: set the shelf life
     * afterwards and every batch already aboard stayed blank for ever, because
     * the stamp had already been taken. Shelf life is a statement about how long
     * the food keeps, so it has to describe the food that is already on the
     * shelf as well as the next delivery. Set 15 days on an item today and every
     * undated batch of it is dated this afternoon; change it to 20 and they all
     * move.
     *
     * Precedence is not negotiable: a date somebody read off the packet always
     * wins. The shelf life is only ever the answer to "nobody wrote one down".
     *
     * Only a batch that was BOOKED IN gets the fallback. An opening batch has a
     * received date meaning "when this ship started keeping records", not when
     * the food arrived — counting a shelf life from that invents a warning the
     * crew would act on, which is worse than the blank.
     *
     * @param  int|null  $shelfLifeDays  the item's, passed in rather than loaded
     */
    public function expiry(?int $shelfLifeDays = null): ?Carbon
    {
        if ($this->expires_on) {
            return Carbon::parse($this->expires_on);
        }

        $shelfLifeDays ??= $this->relationLoaded('item') ? $this->item?->shelf_life_days : null;

        return self::shelfLifeExpiry($this->source, $this->received_on, $shelfLifeDays);
    }

    /**
     * The same rule, for callers holding rows rather than models — the
     * calculator scores a whole catalogue from one query and never builds one
     * of these.
     *
     * @param  mixed  $receivedOn
     */
    public static function shelfLifeExpiry(?string $source, $receivedOn, ?int $shelfLifeDays): ?Carbon
    {
        if (! $shelfLifeDays || $shelfLifeDays <= 0 || ! $receivedOn) {
            return null;
        }

        if ($source !== self::FROM_RECEIPT) {
            return null;
        }

        return Carbon::parse($receivedOn)->startOfDay()->addDays($shelfLifeDays);
    }

    /** Whether the date above was read off a packet or worked out from a rule. */
    public function expiryIsEstimated(): bool
    {
        return ! $this->expires_on;
    }

    /** Days until it expires; negative once it has. Null if undated. */
    public function daysLeft(?Carbon $asOf = null, ?int $shelfLifeDays = null): ?int
    {
        $expiry = $this->expiry($shelfLifeDays);

        if (! $expiry) {
            return null;
        }

        return ($asOf ?? Carbon::today())->startOfDay()->diffInDays($expiry, false);
    }

    public function hasExpired(?Carbon $asOf = null): bool
    {
        $days = $this->daysLeft($asOf);

        return $days !== null && $days < 0;
    }
}

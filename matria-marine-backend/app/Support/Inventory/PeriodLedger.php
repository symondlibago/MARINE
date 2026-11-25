<?php

namespace App\Support\Inventory;

use App\Models\Inventory\PeriodItem;
use App\Models\Inventory\SupplyPeriod;
use App\Models\Inventory\Vessel;
use Illuminate\Support\Carbon;

/**
 * Keeps the period ledger honest.
 *
 * Consumption is only measurable if every delivery is recorded as "supplied"
 * against the period it arrived in, alongside the stock the item started with.
 * Receiving calls this so the crew never has to think about periods.
 */
class PeriodLedger
{
    /** The period currently collecting supplies, opened on demand. */
    public static function currentPeriod(Vessel $vessel): SupplyPeriod
    {
        $open = SupplyPeriod::open($vessel->id);

        if ($open) {
            return $open;
        }

        $start = Carbon::today();

        return SupplyPeriod::create([
            'vessel_id' => $vessel->id,
            'label' => $start->format('F Y'),
            'start_date' => $start->toDateString(),
            'days' => max(1, (int) ($vessel->supply_period_days ?: 30)),
            'crew_size' => $vessel->crew_size,
            'status' => 'open',
        ]);
    }

    /**
     * Record a delivery against the open period.
     *
     * @param  array<int, array{qty: float, rob_before: float}>  $lines  keyed by item id
     */
    public static function recordSupply(Vessel $vessel, array $lines): SupplyPeriod
    {
        $period = self::currentPeriod($vessel);

        foreach ($lines as $itemId => $line) {
            $row = PeriodItem::firstOrNew([
                'period_id' => $period->id,
                'item_id' => (int) $itemId,
            ]);

            // The first time an item appears in a period, its beginning stock is
            // whatever was on board just before this delivery landed.
            if (! $row->exists) {
                $row->beginning_qty = $line['rob_before'];
                $row->supplied_qty = 0;
            }

            $row->supplied_qty = (float) $row->supplied_qty + (float) $line['qty'];
            $row->save();
        }

        return $period;
    }

    /**
     * Undo a delivery's effect on the ledger — used when a confirmed receipt is
     * reopened so the supplied quantities do not get counted twice.
     *
     * @param  array<int, float>  $lines  qty keyed by item id
     */
    public static function reverseSupply(Vessel $vessel, array $lines): void
    {
        $period = SupplyPeriod::open($vessel->id);

        if (! $period) {
            return;
        }

        foreach ($lines as $itemId => $qty) {
            $row = PeriodItem::where('period_id', $period->id)->where('item_id', (int) $itemId)->first();

            if ($row) {
                $row->supplied_qty = max(0, (float) $row->supplied_qty - (float) $qty);
                $row->save();
            }
        }
    }
}

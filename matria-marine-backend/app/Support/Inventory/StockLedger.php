<?php

namespace App\Support\Inventory;

use App\Models\Inventory\Item;
use App\Models\Inventory\Receipt;
use App\Models\Inventory\StockLot;
use App\Models\Inventory\SupplyPeriod;
use App\Models\Inventory\Withdrawal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Every movement of stock, in one place.
 *
 * Nothing else writes to provision_stock_lots. Six things can happen to the
 * store — goods arrive, goods are taken out, a withdrawal is undone, a
 * delivery is unpicked, a stocktake disagrees with the books, and somebody
 * asks what is on board — and all six go through here so quantity and cost can
 * never drift apart.
 *
 * Two numbers describe the same stock and both are kept in step on purpose:
 *
 *   item.current_stock   what is on board. Every calculation in the module
 *                        already reads it, and a stocktake sets it outright.
 *   SUM(lots.remaining)  the same quantity, broken into what each part cost.
 *
 * The count is the authority on HOW MUCH; the lots are the authority on WHAT
 * IT COST. When a count disagrees with the lots the count wins and the gap is
 * booked as a movement at FIFO cost, because the shelf is real and the
 * paperwork is not.
 *
 * Quantities are decimal(12,3), so three decimals is the unit of truth here:
 * everything is rounded to three and anything under half a thousandth counts
 * as nothing. Without that an 0.0000001 remainder keeps a spent lot alive
 * forever and the FIFO scan never reaches the next one.
 */
class StockLedger
{
    /** Below this, a quantity is nothing. Half of the last stored decimal. */
    private const EPSILON = 0.0005;

    /**
     * Goods arrive: a new lot at the price they were bought for.
     *
     * The lot keeps the price this delivery actually paid, which is the whole
     * point of lots. The item's standing unit_price is left alone — that is the
     * price the office agreed for buying more, and a delivery note is not a
     * quotation. Given no cost at all, the standing price is the best guess
     * available and the lot opens at it.
     */
    public static function receive(
        Item $item,
        float $quantity,
        ?float $unitCost = null,
        ?string $expiresOn = null,
        ?Receipt $receipt = null,
        ?string $receivedOn = null,
        string $source = StockLot::FROM_RECEIPT,
        ?string $note = null,
    ): ?StockLot {
        $quantity = round($quantity, 3);

        if ($quantity <= self::EPSILON) {
            return null;
        }

        $cost = $unitCost ?? ($item->unit_price === null ? 0.0 : (float) $item->unit_price);

        return DB::transaction(function () use ($item, $quantity, $cost, $expiresOn, $receipt, $receivedOn, $source, $note) {
            // Re-read under a lock: two deliveries of the same item confirmed
            // at once would otherwise both add to the stock they each read.
            $item = Item::whereKey($item->id)->lockForUpdate()->first() ?? $item;

            $lot = StockLot::create([
                'vessel_id' => $item->vessel_id,
                'item_id' => $item->id,
                'receipt_id' => $receipt?->id,
                'received_on' => $receivedOn ?? Carbon::today()->toDateString(),
                // Only what somebody actually read off the packet. The shelf
                // life is NOT stamped on here any more: it is a statement about
                // how long the food keeps, and stamping it froze that statement
                // at the moment of delivery — set an item's shelf life a week
                // later and every batch already aboard stayed blank for ever.
                // StockLot::expiry() applies it when the date is read instead.
                'expires_on' => $expiresOn ?: null,
                'unit_cost' => round($cost, 4),
                'quantity' => $quantity,
                'remaining' => $quantity,
                'source' => $source,
                'note' => $note,
            ]);

            self::raiseStock($item, $quantity);

            return $lot;
        });
    }

    /**
     * Something leaves the store, costed against the oldest lots first.
     *
     * $options: date, reason, note, period_id, lot_id, user_id. A lot_id pins
     * the withdrawal to one batch instead of walking FIFO, which is what
     * throwing away a specific expired batch needs.
     */
    public static function withdraw(Item $item, float $quantity, array $options = []): ?Withdrawal
    {
        $quantity = round($quantity, 3);

        if ($quantity <= self::EPSILON) {
            return null;
        }

        return DB::transaction(function () use ($item, $quantity, $options) {
            $item = Item::whereKey($item->id)->lockForUpdate()->first() ?? $item;
            $date = Carbon::parse($options['date'] ?? Carbon::today());
            $taken = self::consume($item, $quantity, $options['lot_id'] ?? null, $date);

            $withdrawal = Withdrawal::create([
                'vessel_id' => $item->vessel_id,
                'item_id' => $item->id,
                'period_id' => $options['period_id'] ?? self::periodFor($item, $date),
                'date' => $date->toDateString(),
                'quantity' => $quantity,
                'cost' => round(array_sum(array_map(
                    fn ($line) => $line['quantity'] * $line['unit_cost'],
                    $taken
                )), 4),
                'reason' => $options['reason'] ?? Withdrawal::GALLEY,
                'note' => $options['note'] ?? null,
                'created_by' => $options['user_id'] ?? null,
            ]);

            foreach ($taken as $line) {
                $withdrawal->lots()->create([
                    'stock_lot_id' => $line['lot_id'],
                    'quantity' => $line['quantity'],
                    'unit_cost' => $line['unit_cost'],
                ]);
            }

            self::lowerStock($item, $quantity);

            return $withdrawal;
        });
    }

    /**
     * Undo a withdrawal, putting each quantity back on the lot it came from.
     *
     * The lot lines are why this is possible at all: without them, giving the
     * stock back would mean guessing which batches had been drawn down.
     *
     * A lot this module opened to cover a shortfall is left in place rather
     * than deleted. It records a belief — that the stock was on board,
     * undocumented — and undoing the withdrawal does not make that belief
     * false. Deleting it would also lose the quantity, leaving current_stock
     * and the lots disagreeing by exactly the amount being returned.
     */
    public static function undo(Withdrawal $withdrawal): void
    {
        DB::transaction(function () use ($withdrawal) {
            $withdrawal->load('lots');

            foreach ($withdrawal->lots as $line) {
                $lot = StockLot::whereKey($line->stock_lot_id)->lockForUpdate()->first();

                if (! $lot) {
                    continue;
                }

                // Never above what the lot held: two undos of the same line, or
                // a hand-edited row, must not invent stock.
                $lot->remaining = min(
                    (float) $lot->quantity,
                    round((float) $lot->remaining + (float) $line->quantity, 3)
                );
                $lot->save();
            }

            if ($item = Item::whereKey($withdrawal->item_id)->lockForUpdate()->first()) {
                self::raiseStock($item, (float) $withdrawal->quantity);
            }

            $withdrawal->delete();
        });
    }

    /**
     * A stocktake found this much. Make the books say so.
     *
     * The count wins, and the difference is booked rather than papered over:
     * short, and the missing quantity leaves the oldest lots at their own cost;
     * over, and the surplus arrives as a lot at the newest price known. Either
     * way the movement is visible afterwards, which is the difference between
     * a correction and a quiet overwrite.
     *
     * Returns what it booked, or null when the count already agreed.
     */
    public static function reconcile(Item $item, float $counted, array $options = []): Withdrawal|StockLot|null
    {
        $counted = round(max(0, $counted), 3);

        return DB::transaction(function () use ($item, $counted, $options) {
            $item = Item::whereKey($item->id)->lockForUpdate()->first();
            $onBooks = self::onHand($item);
            $gap = round($counted - $onBooks, 3);

            // Set outright, not nudged: the count is the figure, and arriving
            // at it by addition is how a rounding error becomes permanent.
            $item->current_stock = $counted;
            $item->save();

            if (abs($gap) <= self::EPSILON) {
                return null;
            }

            $date = Carbon::parse($options['date'] ?? Carbon::today());

            if ($gap < 0) {
                $taken = self::consume($item, abs($gap), null, $date);

                // Booked as galley use, not as a category of its own.
                //
                // On a ship where the cook counts weekly and logs nothing, the
                // shortfall IS the month's consumption — it is what was eaten.
                // Giving it its own reason would split consumption across two
                // buckets and leave every report having to add them back
                // together. The note says where the figure came from.
                $withdrawal = Withdrawal::create([
                    'vessel_id' => $item->vessel_id,
                    'item_id' => $item->id,
                    'period_id' => $options['period_id'] ?? self::periodFor($item, $date),
                    'date' => $date->toDateString(),
                    'quantity' => abs($gap),
                    'cost' => round(array_sum(array_map(
                        fn ($line) => $line['quantity'] * $line['unit_cost'],
                        $taken
                    )), 4),
                    'reason' => Withdrawal::GALLEY,
                    'note' => $options['note'] ?? 'Counted short at stocktake',
                    'created_by' => $options['user_id'] ?? null,
                ]);

                foreach ($taken as $line) {
                    $withdrawal->lots()->create([
                        'stock_lot_id' => $line['lot_id'],
                        'quantity' => $line['quantity'],
                        'unit_cost' => $line['unit_cost'],
                    ]);
                }

                return $withdrawal;
            }

            $lot = StockLot::create([
                'vessel_id' => $item->vessel_id,
                'item_id' => $item->id,
                'received_on' => $date->toDateString(),
                // A stocktake found more on the shelf than the batches account
                // for. Nobody knows when it arrived — "today" is only when it
                // was noticed — so no shelf life is counted from it and no
                // expiry is guessed. Same reasoning as an opening batch.
                'expires_on' => null,
                'unit_cost' => self::latestCost($item),
                'quantity' => $gap,
                'remaining' => $gap,
                'source' => StockLot::FROM_ADJUSTMENT,
                'note' => $options['note'] ?? 'Counted over at stocktake',
            ]);

            return $lot;
        });
    }

    /**
     * Unpick a confirmed delivery.
     *
     * Only safe while nothing has been drawn from it. Once the galley has
     * started on a delivery there is no honest way to take it back out — the
     * cost of what was eaten came from these lots — so the caller is told
     * which items are already in use and the delivery stays as it is.
     *
     * @return array<string> the names of items already drawn down, empty when done
     */
    public static function unreceive(Receipt $receipt): array
    {
        return DB::transaction(function () use ($receipt) {
            $lots = StockLot::with('item:id,name,variant')
                ->where('receipt_id', $receipt->id)
                ->lockForUpdate()
                ->get();
            $started = [];

            foreach ($lots as $lot) {
                if (round((float) $lot->remaining, 3) < round((float) $lot->quantity, 3)) {
                    $started[] = $lot->item?->label() ?? "item #{$lot->item_id}";
                }
            }

            if ($started) {
                return array_values(array_unique($started));
            }

            foreach ($lots as $lot) {
                if ($item = Item::whereKey($lot->item_id)->lockForUpdate()->first()) {
                    self::lowerStock($item, (float) $lot->remaining);
                }

                $lot->delete();
            }

            return [];
        });
    }

    /** What the lots say is on board. */
    public static function onHand(Item $item): float
    {
        return round((float) StockLot::where('item_id', $item->id)->sum('remaining'), 3);
    }

    /** What it is worth, at what each part of it actually cost. */
    public static function value(Item $item): float
    {
        return round((float) StockLot::where('item_id', $item->id)
            ->selectRaw('COALESCE(SUM(remaining * unit_cost), 0) as v')
            ->value('v'), 4);
    }

    /**
     * The weighted average of what is on board — for display only.
     *
     * Never used to cost a withdrawal. That is the difference between FIFO and
     * average costing, and mixing the two is how the figures stop adding up.
     */
    public static function averageCost(Item $item): ?float
    {
        $onHand = self::onHand($item);

        return $onHand > self::EPSILON ? round(self::value($item) / $onHand, 4) : null;
    }

    /* ----------------------------------------------------------------------- */

    /**
     * Take a quantity off the lots, oldest first.
     *
     * @return array<array{lot_id: int, quantity: float, unit_cost: float}>
     */
    private static function consume(Item $item, float $quantity, ?int $lotId, Carbon $date): array
    {
        $need = round($quantity, 3);
        $taken = [];

        $lots = StockLot::where('item_id', $item->id)
            ->when($lotId, fn ($q) => $q->whereKey($lotId))
            ->fifo()
            ->lockForUpdate()
            ->get();

        foreach ($lots as $lot) {
            if ($need <= self::EPSILON) {
                break;
            }

            $take = round(min($need, (float) $lot->remaining), 3);

            if ($take <= self::EPSILON) {
                continue;
            }

            $lot->remaining = round((float) $lot->remaining - $take, 3);
            $lot->save();

            $taken[] = [
                'lot_id' => $lot->id,
                'quantity' => $take,
                'unit_cost' => (float) $lot->unit_cost,
            ];

            $need = round($need - $take, 3);
        }

        if ($need > self::EPSILON) {
            $taken[] = self::coverShortfall($item, $need, $date, $lotId !== null);
        }

        return $taken;
    }

    /**
     * More was taken than the lots knew about.
     *
     * This is the normal case on the day lots are switched on: the ship has
     * 879 items with stock on board and no record of what any of it cost.
     * Refusing the withdrawal would stop a cook working; charging it at zero
     * would understate the month. So a lot is opened for the shortfall at the
     * best price available and immediately spent, and it says on its face that
     * nobody booked it in — which is true, and visible, rather than a silent
     * hole in the costing.
     */
    private static function coverShortfall(Item $item, float $quantity, Carbon $date, bool $pinnedToOneLot): array
    {
        $lot = StockLot::create([
            'vessel_id' => $item->vessel_id,
            'item_id' => $item->id,
            'received_on' => $date->toDateString(),
            'unit_cost' => self::latestCost($item),
            'quantity' => $quantity,
            'remaining' => 0,
            'source' => StockLot::FROM_OPENING,
            'note' => $pinnedToOneLot
                ? 'More taken than that batch held — no record of where the rest came from'
                : 'Taken before any delivery was booked in',
        ]);

        return [
            'lot_id' => $lot->id,
            'quantity' => $quantity,
            'unit_cost' => (float) $lot->unit_cost,
        ];
    }

    /**
     * The best price available for stock nobody booked in: the newest lot's,
     * falling back to the item's standing price, then zero.
     */
    private static function latestCost(Item $item): float
    {
        $lot = StockLot::where('item_id', $item->id)
            ->orderByDesc('received_on')->orderByDesc('id')
            ->first(['unit_cost']);

        return round((float) ($lot?->unit_cost ?? $item->unit_price ?? 0), 4);
    }

    /** When a delivery will go off, if the item says how long it keeps. */

    /**
     * The stocktake window a movement falls in.
     *
     * Only the open one, and only if the date is inside it. A withdrawal typed
     * in for last month belongs to last month's closed period or to none at
     * all — it must not land on the period that is still being counted.
     */
    private static function periodFor(Item $item, Carbon $date): ?int
    {
        $period = SupplyPeriod::where('vessel_id', $item->vessel_id)
            ->where('status', 'open')
            ->orderByDesc('start_date')
            ->first(['id', 'start_date']);

        if (! $period) {
            return null;
        }

        return $period->start_date && $date->lt($period->start_date) ? null : $period->id;
    }

    private static function raiseStock(Item $item, float $quantity): void
    {
        $item->current_stock = round((float) $item->current_stock + round($quantity, 3), 3);
        $item->save();
    }

    private static function lowerStock(Item $item, float $quantity): void
    {
        $item->current_stock = max(0, round((float) $item->current_stock - round($quantity, 3), 3));
        $item->save();
    }
}

<?php

namespace App\Support\Inventory;

use App\Models\Inventory\Item;
use App\Models\Inventory\StockLot;
use App\Models\Inventory\SupplyPeriod;
use App\Models\Inventory\Vessel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The whole "brain" of the provision module.
 *
 * Every calculated value in the system comes from here and nowhere else —
 * consumption, target quantity, order quantity, order value and the
 * GOOD / LOW / ORDER NOW status. None of it is stored: it is computed on read
 * so it can never drift away from the inputs.
 *
 *   1. Consumption  = beginning stock + supplied - ending stock   (measured)
 *   2. Target qty   = daily rate x days to next supply x (1 + safety %) x crew factor
 *   3. Order qty    = target - ROB (never below zero);  value = order qty x price
 *   4. Status       = GOOD at/above target, LOW above half, ORDER NOW below half
 *
 * Build it once per request with ProvisionCalculator::for($vessel): the
 * consumption of every item is loaded in a single query, so scoring the whole
 * 900-item catalogue costs two queries in total.
 */
class ProvisionCalculator
{
    public const GOOD = 'GOOD';
    public const LOW = 'LOW';
    public const ORDER_NOW = 'ORDER NOW';

    /**
     * How far ahead "going off soon" looks, in days.
     *
     * Long enough that a galley can plan a menu around it, short enough that it
     * is not crying wolf over half the store. The batch board offers the crew
     * other windows; this is the one every other screen warns on, so that a
     * dashboard card and an inventory row can never disagree about which items
     * need eating.
     */
    public const EXPIRY_WINDOW_DAYS = 14;

    /** item_id => ['qty' => float, 'days' => int] measured over the last closed period. */
    private array $measured = [];

    /**
     * item_id => ['cost' => float, 'value' => float, 'expires_on' => ?string,
     * 'at_risk' => float, 'at_risk_value' => float] for the stock actually on
     * board: what the next unit out of the store costs, what the whole shelf is
     * worth, and when the soonest batch of it goes off. Loaded in one query
     * beside the consumption, same reason.
     */
    private array $onBoard = [];

    private float $crewFactor = 1.0;

    private int $daysToNextSupply;

    private float $safetyMultiplier;

    private function __construct(private Vessel $vessel, private ?SupplyPeriod $lastClosed, ?int $days = null)
    {
        // Normally the vessel's own supply period. An order written for a
        // different stretch — a long leg, a yard period — says so, and then
        // every target on it is scaled to that instead.
        $this->daysToNextSupply = max(1, (int) ($days ?: $vessel->supply_period_days ?: 30));
        $this->safetyMultiplier = 1 + ((float) $vessel->safety_margin_pct / 100);

        // Scale by crew change: a bigger crew for the coming period eats more.
        $previousCrew = (int) ($lastClosed?->crew_size ?: $vessel->previous_crew_size);
        $crew = (int) $vessel->crew_size;
        if ($previousCrew > 0 && $crew > 0) {
            $this->crewFactor = $crew / $previousCrew;
        }
    }

    /**
     * Load the vessel's measured consumption once, ready to score any item.
     *
     * $days overrides how long the next supply has to last, for an order
     * written to cover something other than the usual period.
     */
    public static function for(Vessel $vessel, ?int $days = null): self
    {
        $lastClosed = SupplyPeriod::lastClosed($vessel->id);
        $calc = new self($vessel, $lastClosed, $days);

        if ($lastClosed) {
            $days = max(1, (int) $lastClosed->days);
            $rows = DB::table('provision_period_items')
                ->where('period_id', $lastClosed->id)
                ->whereNotNull('ending_qty')
                ->select('item_id', 'beginning_qty', 'supplied_qty', 'ending_qty')
                ->get();

            foreach ($rows as $row) {
                $beginning = (float) $row->beginning_qty;
                $supplied = (float) $row->supplied_qty;
                $ending = (float) $row->ending_qty;

                // Nothing on board, nothing delivered, nothing left is an absence
                // of information — not a measurement of zero usage. Those items
                // keep their baseline instead of silently dropping to "GOOD".
                if ($beginning == 0.0 && $supplied == 0.0 && $ending == 0.0) {
                    continue;
                }

                $calc->measured[(int) $row->item_id] = [
                    'qty' => max(0, $beginning + $supplied - $ending),
                    'days' => $days,
                ];
            }
        }

        // The batches on the shelf, oldest first — the same order StockLot's
        // fifo scope walks them in, so the first row seen for an item is the
        // batch the next withdrawal will come out of, and its price is what a
        // unit of this item costs right now.
        // source and shelf_life_days ride along because an undated batch takes
        // its expiry from the item's shelf life, counted from the day it came
        // aboard — StockLot::shelfLifeExpiry, the same rule the batch board
        // reads by, so a date cannot differ between the two screens.
        $lots = DB::table('provision_stock_lots as l')
            ->join('provision_items as i', 'i.id', '=', 'l.item_id')
            ->where('l.vessel_id', $vessel->id)
            ->where('l.remaining', '>', 0)
            ->orderBy('l.item_id')->orderBy('l.received_on')->orderBy('l.id')
            ->get([
                'l.item_id', 'l.remaining', 'l.unit_cost', 'l.expires_on',
                'l.received_on', 'l.source', 'i.shelf_life_days',
            ]);

        $today = Carbon::today();
        $deadline = $today->copy()->addDays(self::EXPIRY_WINDOW_DAYS);

        foreach ($lots as $lot) {
            $id = (int) $lot->item_id;
            $cost = (float) $lot->unit_cost;
            $remaining = (float) $lot->remaining;

            if (! isset($calc->onBoard[$id])) {
                $calc->onBoard[$id] = [
                    'cost' => $cost,
                    'value' => 0.0,
                    'expires_on' => null,
                    'at_risk' => 0.0,
                    'at_risk_value' => 0.0,
                    'batches' => 0,
                    'low' => $cost,
                    'high' => $cost,
                ];
            }

            $calc->onBoard[$id]['value'] += $remaining * $cost;

            // How many prices are on this shelf, and how far apart they are.
            // One number cannot describe a shelf holding $20 stock and $25
            // stock, and a screen that shows only the FIFO figure leaves the
            // other one to be discovered on the batch board. The row says so
            // instead.
            $calc->onBoard[$id]['batches']++;
            $calc->onBoard[$id]['low'] = min($calc->onBoard[$id]['low'], $cost);
            $calc->onBoard[$id]['high'] = max($calc->onBoard[$id]['high'], $cost);

            $expires = $lot->expires_on
                ? Carbon::parse($lot->expires_on)->startOfDay()
                : StockLot::shelfLifeExpiry(
                    $lot->source,
                    $lot->received_on,
                    $lot->shelf_life_days === null ? null : (int) $lot->shelf_life_days
                );

            if (! $expires) {
                continue;   // keeps, or nobody wrote it down and no shelf life
            }

            // The soonest date wins, because that is the batch to eat next —
            // not the oldest delivery, which is a different question when a
            // long-life batch arrives before a short-life one.
            $soonest = $calc->onBoard[$id]['expires_on'];
            if ($soonest === null || $expires->lt(Carbon::parse($soonest))) {
                $calc->onBoard[$id]['expires_on'] = $expires->toDateString();
            }

            // Already gone off, or going off inside the window: either way it
            // is food that needs dealing with rather than counting.
            if ($expires->lte($deadline)) {
                $calc->onBoard[$id]['at_risk'] += $remaining;
                $calc->onBoard[$id]['at_risk_value'] += $remaining * $cost;
            }
        }

        return $calc;
    }

    public function vessel(): Vessel
    {
        return $this->vessel;
    }

    public function lastClosedPeriod(): ?SupplyPeriod
    {
        return $this->lastClosed;
    }

    /**
     * Every calculated field for one item, ready to be merged into an API row.
     */
    public function calculate(Item $item): array
    {
        [$consumption, $days, $source] = $this->consumptionFor($item);

        $dailyRate = $days > 0 ? $consumption / $days : 0.0;
        $target = $this->target($item, $dailyRate, $source);

        $rob = (float) $item->current_stock;
        $orderQty = max(0.0, ceil($target - $rob));

        // What the office has agreed to pay, which is a quotation's figure and
        // may be months old or missing altogether.
        $standing = $item->unit_price === null ? null : (float) $item->unit_price;

        // What a unit of this item actually costs, which is a stronger claim:
        // the price of the batch the next withdrawal will come out of. A batch
        // opened without a price is no information, so it does not count.
        $onBoard = $this->onBoard[$item->id] ?? null;
        $stockCost = $onBoard && $onBoard['cost'] > 0 ? round($onBoard['cost'], 4) : null;

        // The price every screen shows and every order is valued at. The stock
        // on board wins, because a price actually paid for the food currently
        // in the store beats a reference figure from a quotation — and because
        // this is the figure the crew can check against the batch board.
        $price = $stockCost ?? $standing;
        $orderValue = $price === null ? 0.0 : round($orderQty * $price, 2);

        // When the soonest batch of this item goes off, and how much is sitting
        // in batches that have gone off or are about to. Negative days_left is
        // food already past its date and still on the shelf.
        $expiresOn = $onBoard['expires_on'] ?? null;
        $daysLeft = $expiresOn === null
            ? null
            : Carbon::today()->startOfDay()->diffInDays(Carbon::parse($expiresOn), false);

        return [
            'unit_price' => $price,
            'standing_price' => $standing,       // the stored one, for the edit form
            'stock_cost' => $stockCost,
            'stock_value' => $onBoard ? round($onBoard['value'], 2) : 0.0,
            'stock_batches' => $onBoard['batches'] ?? 0,
            // Equal to stock_cost when the shelf holds one price. Different
            // means the figure above is the oldest batch's, not the only one.
            'stock_cost_low' => $onBoard && $onBoard['low'] > 0 ? round($onBoard['low'], 4) : null,
            'stock_cost_high' => $onBoard && $onBoard['high'] > 0 ? round($onBoard['high'], 4) : null,
            'expires_on' => $expiresOn,
            'days_left' => $daysLeft,
            'expiring_qty' => round($onBoard['at_risk'] ?? 0.0, 3),
            'expiring_value' => round($onBoard['at_risk_value'] ?? 0.0, 2),
            // Carried on the row so a screen never has to decide for itself
            // what "soon" means, and the row and the card always agree.
            'expiry_window_days' => self::EXPIRY_WINDOW_DAYS,
            'consumption' => round($consumption, 3),
            'consumption_days' => $days,
            'consumption_source' => $source,     // measured | baseline | manual | none
            'daily_rate' => round($dailyRate, 4),
            'target_qty' => $target,
            'order_qty' => $orderQty,
            'order_value' => $orderValue,
            'status' => $this->status($rob, $target),
            'price_missing' => $price === null && $orderQty > 0,
        ];
    }

    /**
     * Aggregate figures for the dashboard, computed in the same single pass so
     * the cards can never disagree with the inventory table.
     *
     * @param  iterable<Item>  $items
     */
    public function summary(iterable $items): array
    {
        $rows = [];
        foreach ($items as $item) {
            $rows[] = $this->calculate($item);
        }

        return $this->summarize($rows);
    }

    /**
     * Same aggregate, but over rows that were already scored — so a screen that
     * lists items never pays for the calculation twice.
     *
     * @param  array<int, array>  $rows
     */
    public function summarize(array $rows): array
    {
        $counts = [self::GOOD => 0, self::LOW => 0, self::ORDER_NOW => 0];
        $orderValue = 0.0;
        $toOrder = 0;
        $missingPrices = 0;
        $total = 0;
        $expired = 0;
        $expiring = 0;
        $expiringValue = 0.0;
        $soonest = null;

        foreach ($rows as $calc) {
            $total++;
            $counts[$calc['status']]++;
            $orderValue += $calc['order_value'];
            if ($calc['order_qty'] > 0) {
                $toOrder++;
            }
            if ($calc['price_missing']) {
                $missingPrices++;
            }

            // Counted by item, not by batch: the cook is deciding what to cook,
            // and two batches of the same thing going off is still one dish to
            // plan. The batch board counts batches, for throwing away.
            $days = $calc['days_left'] ?? null;
            if ($days !== null && $days < 0) {
                $expired++;
            } elseif ($days !== null && $days <= self::EXPIRY_WINDOW_DAYS) {
                $expiring++;
            }

            if ($days !== null && ($soonest === null || $days < $soonest)) {
                $soonest = $days;
            }

            $expiringValue += $calc['expiring_value'] ?? 0.0;
        }

        $budget = (float) $this->vessel->budget;
        $orderValue = round($orderValue, 2);

        return [
            'items_total' => $total,
            'items_to_order' => $toOrder,
            'order_value' => $orderValue,
            'budget' => $budget,
            'budget_balance' => round($budget - $orderValue, 2),
            'over_budget' => $budget > 0 && $orderValue > $budget,
            'missing_prices' => $missingPrices,
            'good' => $counts[self::GOOD],
            'low' => $counts[self::LOW],
            'order_now' => $counts[self::ORDER_NOW],
            'expired_items' => $expired,
            'expiring_items' => $expiring,
            'expiring_value' => round($expiringValue, 2),
            'expiry_window_days' => self::EXPIRY_WINDOW_DAYS,
            'soonest_days_left' => $soonest,
            'currency' => 'USD',
            'days_to_next_supply' => $this->daysToNextSupply,
            'safety_margin_pct' => (float) $this->vessel->safety_margin_pct,
            'crew_size' => (int) $this->vessel->crew_size,
            'crew_factor' => round($this->crewFactor, 4),
            'last_closed_period' => $this->lastClosed?->only(['id', 'label', 'start_date', 'end_date', 'days']),
        ];
    }

    /**
     * Measured usage wins; the workbook baseline covers items that have not
     * lived through a closed period yet.
     *
     * @return array{0: float, 1: int, 2: string}
     */
    private function consumptionFor(Item $item): array
    {
        if (isset($this->measured[$item->id])) {
            $m = $this->measured[$item->id];

            return [(float) $m['qty'], (int) $m['days'], 'measured'];
        }

        if ($item->baseline_consumption !== null && (float) $item->baseline_consumption > 0) {
            $days = (int) ($item->baseline_period_days ?: $this->vessel->supply_period_days ?: 30);

            return [(float) $item->baseline_consumption, max(1, $days), 'baseline'];
        }

        return [0.0, 0, $item->manual_target !== null ? 'manual' : 'none'];
    }

    /** How much to carry until the next supply, rounded up to a whole unit. */
    private function target(Item $item, float $dailyRate, string $source): float
    {
        if ($dailyRate > 0) {
            return ceil($dailyRate * $this->daysToNextSupply * $this->safetyMultiplier * $this->crewFactor);
        }

        // No usage history — fall back to the one-off quantity the office typed.
        if ($item->manual_target !== null) {
            return ceil((float) $item->manual_target);
        }

        return 0.0;
    }

    private function status(float $rob, float $target): string
    {
        if ($target <= 0 || $rob >= $target) {
            return self::GOOD;
        }

        return $rob >= $target / 2 ? self::LOW : self::ORDER_NOW;
    }
}

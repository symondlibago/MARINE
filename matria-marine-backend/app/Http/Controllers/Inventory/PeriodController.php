<?php

namespace App\Http\Controllers\Inventory;

use App\Models\Inventory\Category;
use App\Models\Inventory\Item;
use App\Models\Inventory\PeriodItem;
use App\Models\Inventory\SupplyPeriod;
use App\Support\Inventory\StockLedger;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Supply periods and the stocktake that closes them.
 *
 * Closing a period writes the counted ending stock for every item, which is
 * what turns "beginning + supplied - ending" into real, measured consumption
 * for the next order. A fresh period opens automatically, carrying the ending
 * counts forward as the new beginning stock.
 */
class PeriodController extends InventoryController
{
    public function index(Request $request)
    {
        $vessel = $this->vessel($request);

        $periods = SupplyPeriod::where('vessel_id', $vessel->id)
            ->withCount('items')
            ->orderByDesc('start_date')->orderByDesc('id')
            ->get();

        return $this->ok([
            'periods' => $periods,
            'open' => SupplyPeriod::open($vessel->id),
        ]);
    }

    /**
     * The stocktake sheet: every item with its beginning stock, what was
     * supplied during the period, and the ending count to confirm.
     */
    public function show(Request $request, SupplyPeriod $period)
    {
        $names = Category::pluck('name', 'id');

        $ledger = PeriodItem::where('period_id', $period->id)->get()->keyBy('item_id');

        $items = Item::where('vessel_id', $period->vessel_id)
            ->where('is_active', true)
            ->orderBy('category_id')->orderBy('sort')->orderBy('name')
            ->get(['id', 'code', 'name', 'variant', 'unit', 'category_id', 'current_stock']);

        $lines = $items->map(function (Item $item) use ($ledger, $names, $period) {
            $row = $ledger->get($item->id);
            $beginning = $row ? (float) $row->beginning_qty : 0.0;
            $supplied = $row ? (float) $row->supplied_qty : 0.0;
            // While the period is open, the counted ending stock defaults to
            // whatever is on board right now.
            $ending = $row && $row->ending_qty !== null
                ? (float) $row->ending_qty
                : ($period->status === 'closed' ? 0.0 : (float) $item->current_stock);

            return [
                'item_id' => $item->id,
                'code' => $item->code,
                'name' => $item->name,
                'variant' => $item->variant,
                'unit' => $item->unit,
                'category' => $names[$item->category_id] ?? null,
                'beginning_qty' => $beginning,
                'supplied_qty' => $supplied,
                'ending_qty' => $ending,
                'consumption' => max(0, $beginning + $supplied - $ending),
                'current_stock' => (float) $item->current_stock,
            ];
        });

        return $this->ok(['period' => $period, 'lines' => $lines]);
    }

    /** Start a new period; the beginning stock is today's ROB. */
    public function store(Request $request)
    {
        $vessel = $this->vessel($request);

        if ($open = SupplyPeriod::open($vessel->id)) {
            return response()->json([
                'success' => false,
                'message' => "A period is already open ({$open->label}). Close it before starting another.",
            ], 422);
        }

        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:255'],
            'start_date' => ['nullable', 'date'],
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $start = Carbon::parse($data['start_date'] ?? Carbon::today());

        $period = DB::transaction(function () use ($vessel, $data, $start, $request) {
            $period = SupplyPeriod::create([
                'vessel_id' => $vessel->id,
                'label' => $data['label'] ?? $start->format('F Y'),
                'start_date' => $start->toDateString(),
                'days' => $data['days'] ?? max(1, (int) $vessel->supply_period_days),
                'crew_size' => $vessel->crew_size,
                'status' => 'open',
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()?->id,
            ]);

            $this->seedBeginningStock($period);

            return $period;
        });

        return $this->ok(['period' => $period], 'Period opened.', 201);
    }

    /** Save counts without closing — the crew can count over several days. */
    public function saveCounts(Request $request, SupplyPeriod $period)
    {
        if ($period->status === 'closed') {
            return response()->json(['success' => false, 'message' => 'This period is closed.'], 422);
        }

        $data = $request->validate([
            'lines' => ['present', 'array'],
            'lines.*.item_id' => ['required', 'integer'],
            'lines.*.ending_qty' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'lines.*.beginning_qty' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'lines.*.supplied_qty' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
        ]);

        DB::transaction(function () use ($period, $data) {
            foreach ($data['lines'] as $line) {
                $row = PeriodItem::firstOrNew(['period_id' => $period->id, 'item_id' => $line['item_id']]);

                if (array_key_exists('beginning_qty', $line) && $line['beginning_qty'] !== null) {
                    $row->beginning_qty = $line['beginning_qty'];
                }
                if (array_key_exists('supplied_qty', $line) && $line['supplied_qty'] !== null) {
                    $row->supplied_qty = $line['supplied_qty'];
                }
                if (array_key_exists('ending_qty', $line)) {
                    $row->ending_qty = $line['ending_qty'];
                }

                $row->save();
            }
        });

        return $this->ok([], 'Counts saved.');
    }

    /**
     * Close the period: the ending counts become each item's ROB, consumption
     * becomes measurable, and the next period opens carrying stock forward.
     */
    public function close(Request $request, SupplyPeriod $period)
    {
        if ($period->status === 'closed') {
            return response()->json(['success' => false, 'message' => 'This period is already closed.'], 422);
        }

        $data = $request->validate([
            'end_date' => ['nullable', 'date'],
            'lines' => ['nullable', 'array'],
            'lines.*.item_id' => ['required', 'integer'],
            'lines.*.ending_qty' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'apply_to_stock' => ['nullable', 'boolean'],
            'open_next' => ['nullable', 'boolean'],
        ]);

        $applyToStock = $request->boolean('apply_to_stock', true);
        $openNext = $request->boolean('open_next', true);
        $end = Carbon::parse($data['end_date'] ?? Carbon::today());

        // A period cannot end before it began.
        //
        // Closing one opens the next on the following day, so a period closed
        // on the day it was opened — the second close of any one day — had an
        // end date a day behind its start. The day count hid it, because an
        // absolute difference of one day reads the same forwards or backwards.
        if ($period->start_date && $end->lt($period->start_date)) {
            $end = $period->start_date->copy();
        }

        $next = DB::transaction(function () use ($period, $data, $end, $applyToStock, $openNext, $request) {
            // Anything not counted keeps whatever is on board as its ending stock.
            $counted = collect($data['lines'] ?? [])->pluck('ending_qty', 'item_id');

            $items = Item::where('vessel_id', $period->vessel_id)->get();

            foreach ($items as $item) {
                $ending = $counted->has($item->id) ? (float) $counted[$item->id] : (float) $item->current_stock;

                $row = PeriodItem::firstOrNew(['period_id' => $period->id, 'item_id' => $item->id]);
                if (! $row->exists) {
                    $row->beginning_qty = 0;
                    $row->supplied_qty = 0;
                }
                $row->ending_qty = $ending;
                $row->save();

                if ($applyToStock && $counted->has($item->id)) {
                    // The stocktake is the count that matters most, so it is
                    // booked like any other: the figure becomes the stock, and
                    // whatever the lots were still carrying above it leaves
                    // them at FIFO cost. That is what turns a month of counting
                    // into a month of costed consumption.
                    StockLedger::reconcile($item, $ending, [
                        'date' => $end->toDateString(),
                        'period_id' => $period->id,
                        'user_id' => $request->user()?->id,
                        'note' => "Counted at the close of {$period->label}",
                    ]);
                }
            }

            $days = max(1, $period->start_date ? $period->start_date->diffInDays($end) : (int) $period->days);

            $period->update([
                'end_date' => $end->toDateString(),
                'days' => $days,
                'status' => 'closed',
                'closed_at' => now(),
            ]);

            if (! $openNext) {
                return null;
            }

            $vessel = $period->vessel;
            $next = SupplyPeriod::create([
                'vessel_id' => $period->vessel_id,
                'label' => $end->copy()->addDay()->format('F Y'),
                'start_date' => $end->copy()->addDay()->toDateString(),
                'days' => max(1, (int) ($vessel->supply_period_days ?: 30)),
                'crew_size' => $vessel->crew_size,
                'status' => 'open',
                'created_by' => $request->user()?->id,
            ]);

            $this->seedBeginningStock($next);

            // Remember the crew the closed period ran with, so the next order
            // scales correctly if the crew changed.
            $vessel->update(['previous_crew_size' => $period->crew_size ?: $vessel->crew_size]);

            return $next;
        });

        return $this->ok(
            ['period' => $period->fresh(), 'next' => $next],
            'Period closed — consumption is now measured from real counts.'
        );
    }

    public function destroy(SupplyPeriod $period)
    {
        if ($period->status === 'closed') {
            return response()->json([
                'success' => false,
                'message' => 'A closed period is part of the vessel\'s history and cannot be deleted.',
            ], 422);
        }

        $period->delete();

        return $this->ok([], 'Period deleted.');
    }

    /** Capture today's ROB as the starting point of a period. */
    private function seedBeginningStock(SupplyPeriod $period): void
    {
        $now = now();

        Item::where('vessel_id', $period->vessel_id)
            ->where('is_active', true)
            ->select(['id', 'current_stock'])
            ->chunkById(500, function ($chunk) use ($period, $now) {
                $rows = $chunk->map(fn ($item) => [
                    'period_id' => $period->id,
                    'item_id' => $item->id,
                    'beginning_qty' => $item->current_stock,
                    'supplied_qty' => 0,
                    'ending_qty' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();

                DB::table('provision_period_items')->insertOrIgnore($rows);
            });
    }
}

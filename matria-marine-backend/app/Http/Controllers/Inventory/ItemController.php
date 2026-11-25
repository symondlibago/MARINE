<?php

namespace App\Http\Controllers\Inventory;

use App\Models\Inventory\Category;
use App\Models\Inventory\Item;
use App\Models\Offer;
use App\Support\Inventory\OfferPrices;
use App\Support\Inventory\ProvisionCalculator;
use App\Support\Inventory\StockLedger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The main working screen: the provision catalogue with its calculated
 * target / order quantities and colour status.
 */
class ItemController extends InventoryController
{
    /**
     * The whole catalogue, grouped by category.
     *
     * Two queries total regardless of catalogue size: one for the items, one
     * inside the calculator for measured consumption.
     */
    public function index(Request $request)
    {
        $vessel = $this->vessel($request);
        $calc = ProvisionCalculator::for($vessel);

        $categories = Category::orderBy('sort')->orderBy('name')->get(['id', 'name', 'sort']);
        $names = $categories->pluck('name', 'id');

        // One query for the catalogue, scored once. Filtering happens in memory
        // because status and order quantity are calculated, not stored.
        $items = Item::where('vessel_id', $vessel->id)
            ->when($request->boolean('active_only', true), fn ($q) => $q->where('is_active', true))
            ->orderBy('category_id')->orderBy('sort')->orderBy('name')
            ->get();

        $scored = [];
        $activeRows = [];
        foreach ($items as $item) {
            $row = $this->presentItem($item, $calc, $names[$item->category_id] ?? null);
            $scored[] = $row;
            if ($row['is_active']) {
                $activeRows[] = $row;
            }
        }

        $rows = $scored;

        if ($categoryId = $request->integer('category_id')) {
            $rows = array_filter($rows, fn ($r) => $r['category_id'] === $categoryId);
        }

        if ($term = trim((string) $request->query('search', ''))) {
            $needle = mb_strtolower($term);
            $rows = array_filter($rows, function ($r) use ($needle) {
                return str_contains(mb_strtolower($r['name']), $needle)
                    || str_contains(mb_strtolower((string) $r['variant']), $needle)
                    || str_contains(mb_strtolower($r['code']), $needle);
            });
        }

        if ($request->filled('status')) {
            $wanted = strtoupper((string) $request->query('status'));
            $rows = array_filter($rows, fn ($r) => $r['status'] === $wanted);
        }

        // The money comes off here, after the filtering and the counting and
        // after the summary above was totalled from the real figures — a cook's
        // cards must still say how many items need ordering, which is counted
        // from the same prices he is not shown.
        $grouped = [];
        foreach ($this->presentItems(array_values($rows)) as $row) {
            $grouped[$row['category_id']][] = $row;
        }

        $groups = $categories
            ->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'items' => $grouped[$c->id] ?? [],
                'count' => count($grouped[$c->id] ?? []),
                'to_order' => count(array_filter($grouped[$c->id] ?? [], fn ($r) => $r['order_qty'] > 0)),
            ])
            ->filter(fn ($g) => $g['count'] > 0)
            ->values();

        return $this->ok([
            'vessel' => $this->vesselPayload($vessel),
            'categories' => $categories,
            'groups' => $groups,
            // Always the whole active catalogue, so the cards stay stable while
            // the user searches or filters the table below them.
            'summary' => $this->presentSummary($calc->summarize($activeRows)),
        ]);
    }

    /** Categories only — for dropdowns, without shipping the catalogue. */
    public function categories()
    {
        return $this->ok(['categories' => Category::orderBy('sort')->orderBy('name')->get(['id', 'name', 'sort'])]);
    }

    public function store(Request $request)
    {
        $vessel = $this->vessel($request);
        $data = $this->validated($request, $vessel->id);

        // Created with nothing on board, then given its stock as an opening
        // lot — so the costing starts out agreeing with the quantity instead of
        // owing it an explanation.
        $opening = (float) ($data['current_stock'] ?? 0);
        $item = Item::create(['current_stock' => 0] + $data + ['vessel_id' => $vessel->id]);

        if ($opening > 0) {
            StockLedger::receive(
                item: $item,
                quantity: $opening,
                source: \App\Models\Inventory\StockLot::FROM_OPENING,
                note: 'On board when the item was added',
            );
        }

        return $this->ok(
            ['item' => $this->presentItems([$this->presentItem($item->fresh('category'), ProvisionCalculator::for($vessel))])[0]],
            'Item added.',
            201
        );
    }

    public function update(Request $request, Item $item)
    {
        $data = $this->validated($request, $item->vessel_id, $item->id);

        // The stock figure on this form is a count like any other, so it goes
        // through the ledger rather than straight onto the column.
        $counted = array_key_exists('current_stock', $data) ? $data['current_stock'] : null;
        unset($data['current_stock']);

        $item->update($data);

        if ($counted !== null) {
            StockLedger::reconcile($item, (float) $counted, [
                'user_id' => $request->user()?->id,
                'note' => 'Counted while editing the item',
            ]);
        }

        return $this->ok(
            ['item' => $this->presentItems([$this->presentItem($item->fresh('category'), ProvisionCalculator::for($item->vessel))])[0]],
            'Item updated.'
        );
    }

    public function destroy(Item $item)
    {
        $item->delete();

        return $this->ok([], 'Item removed.');
    }

    /**
     * The crew's most-used action: update one item's ROB and get its freshly
     * calculated fields straight back so the row re-colours instantly.
     */
    public function updateStock(Request $request, Item $item)
    {
        $data = $request->validate([
            'current_stock' => ['required', 'numeric', 'min:0', 'max:9999999'],
        ]);

        // Typing a figure in here is a count, so it is booked as one: the
        // number is taken as the truth and the difference against the stock
        // lots is costed out at FIFO. Setting current_stock on its own would
        // leave the costing behind, and the galley's spend with it.
        StockLedger::reconcile($item, (float) $data['current_stock'], [
            'user_id' => $request->user()?->id,
            'note' => 'Counted on the inventory screen',
        ]);

        $item = $item->fresh();
        $calc = ProvisionCalculator::for($item->vessel);

        // The refreshed summary rides along so the screen can update its cards
        // without refetching the whole catalogue after every single count.
        return $this->ok([
            'item' => $this->presentItems([$this->presentItem($item->fresh('category'), $calc)])[0],
            'summary' => $this->summaryFor($item->vessel_id, $calc),
        ]);
    }

    /**
     * Save a whole stock count in one request — the crew types several rows
     * then presses Save once.
     */
    public function bulkStock(Request $request)
    {
        $vessel = $this->vessel($request);
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer'],
            'items.*.current_stock' => ['required', 'numeric', 'min:0', 'max:9999999'],
        ]);

        $ids = array_column($data['items'], 'id');
        $items = Item::where('vessel_id', $vessel->id)->whereIn('id', $ids)->get()->keyBy('id');
        $owned = $items->keys()->all();

        DB::transaction(function () use ($data, $items, $request) {
            foreach ($data['items'] as $row) {
                $item = $items->get($row['id']);

                if (! $item) {
                    continue; // silently skip anything outside this vessel
                }

                // Each row is a count, so each is reconciled — see updateStock.
                StockLedger::reconcile($item, (float) $row['current_stock'], [
                    'user_id' => $request->user()?->id,
                    'note' => 'Counted on the inventory screen',
                ]);
            }
        });

        $calc = ProvisionCalculator::for($vessel);
        $items = Item::with('category:id,name')->whereIn('id', $owned)->get()
            ->map(fn (Item $i) => $this->presentItem($i, $calc))
            ->values()
            ->all();

        $items = $this->presentItems($items);

        return $this->ok([
            'items' => $items,
            'summary' => $this->summaryFor($vessel->id, $calc),
        ], 'Stock updated.');
    }

    /**
     * Take the prices off an offer the office quoted to this vessel.
     *
     * The catalogue came out of a workbook and most of it has no price, while
     * the office already knows them — it quoted them. Typing them in a second
     * time is how they stay untyped, so they are copied across instead.
     *
     * Matched by the ship's own catalogue code, never by description.
     */
    public function pricesFromOffers(Request $request)
    {
        $vessel = $this->vessel($request);

        $data = $request->validate([
            'offer_id' => ['nullable', 'integer', 'exists:offers,id'],
        ]);

        $offers = Offer::query()
            ->where('origin', \App\Support\DocumentOrigin::INVENTORY)
            ->when($data['offer_id'] ?? null, fn ($q, $id) => $q->whereKey($id))
            ->orderBy('id')
            ->get();

        $applied = 0;
        $skipped = 0;
        $unmatched = [];
        $used = 0;

        foreach ($offers as $offer) {
            if (OfferPrices::vesselFor($offer)?->id !== $vessel->id) {
                continue;
            }

            $result = OfferPrices::applyTo($offer);
            $applied += $result['applied'];
            $skipped += $result['skipped'];
            $unmatched = array_merge($unmatched, $result['unmatched']);
            $used++;
        }

        if ($used === 0) {
            return response()->json([
                'success' => false,
                'message' => "No quotations have been sent to {$vessel->name} yet. "
                    .'Send one of her requisitions to procurement, get prices from vendors, and the offer that goes '
                    .'back to her can fill the catalogue in.',
            ], 422);
        }

        $calc = ProvisionCalculator::for($vessel->fresh());

        return $this->ok(
            [
                'applied' => $applied,
                'skipped' => $skipped,
                'unmatched' => array_slice(array_values(array_unique($unmatched)), 0, 5),
                'summary' => $this->summaryFor($vessel->id, $calc),
            ],
            $applied === 0
                ? 'Nothing to copy — every line on those quotations was either unpriced or already matched.'
                : "{$applied} prices taken from {$used} quotation".($used === 1 ? '' : 's').'.'
                    .($skipped ? " {$skipped} line".($skipped === 1 ? '' : 's').' had no price to take.' : '')
        );
    }

    /** Office screen: fill in the prices the workbook never had. */
    public function bulkPrices(Request $request)
    {
        $vessel = $this->vessel($request);
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
        ]);

        $owned = Item::where('vessel_id', $vessel->id)
            ->whereIn('id', array_column($data['items'], 'id'))
            ->pluck('id')->flip();

        DB::transaction(function () use ($data, $owned) {
            foreach ($data['items'] as $row) {
                if (! isset($owned[$row['id']])) {
                    continue;
                }
                Item::whereKey($row['id'])->update(['unit_price' => $row['unit_price']]);
            }
        });

        return $this->ok([], 'Prices updated.');
    }

    private function validated(Request $request, int $vesselId, ?int $ignoreId = null): array
    {
        $data = $request->validate([
            'code' => [
                'required', 'string', 'max:20',
                Rule::unique('provision_items', 'code')
                    ->where(fn ($q) => $q->where('vessel_id', $vesselId))
                    ->ignore($ignoreId),
            ],
            'category_id' => ['required', 'integer', 'exists:provision_categories,id'],
            'name' => ['required', 'string', 'max:255'],
            'variant' => ['nullable', 'string', 'max:255'],
            'unit' => ['required', 'string', 'max:16'],
            'unit_price' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'shelf_life_days' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'current_stock' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'manual_target' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'baseline_consumption' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'baseline_period_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'notes' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        // An account that is not shown a price does not set one either. Dropped
        // rather than refused, the same way the supplier is on a receipt: the
        // key is simply absent, so a cook correcting an item's unit or shelf
        // life does not wipe the price the office agreed on his way past.
        if (! $this->seesMoney($request)) {
            unset($data['unit_price']);
        }

        return $data;
    }

    /** Aggregates over the vessel's whole active catalogue. */
    private function summaryFor(int $vesselId, ProvisionCalculator $calc): array
    {
        return $calc->summary(
            Item::where('vessel_id', $vesselId)->where('is_active', true)->get()
        );
    }

    private function vesselPayload($vessel): array
    {
        return [
            'id' => $vessel->id,
            'name' => $vessel->name,
            'crew_size' => (int) $vessel->crew_size,
            'supply_period_days' => (int) $vessel->supply_period_days,
            'safety_margin_pct' => (float) $vessel->safety_margin_pct,
            'currency' => $vessel->currency,
        ];
    }
}

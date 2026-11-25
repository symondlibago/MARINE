<?php

namespace App\Http\Controllers\Inventory;

use App\Models\Inventory\Category;
use App\Models\Inventory\Item;
use App\Models\Inventory\Supplier;
use App\Models\Inventory\SupplierPrice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Suppliers and the price comparison — office use.
 *
 * Prices are held in each supplier's own currency and converted to USD with
 * that supplier's rate before they are compared, so the cheapest column is a
 * like-for-like number.
 */
class SupplierController extends InventoryController
{
    public function index(Request $request)
    {
        $vessel = $this->vessel($request);

        $suppliers = Supplier::withCount('prices')
            ->where(fn ($q) => $q->where('vessel_id', $vessel->id)->orWhereNull('vessel_id'))
            ->orderBy('name')
            ->get();

        return $this->ok(['suppliers' => $suppliers]);
    }

    public function store(Request $request)
    {
        $vessel = $this->vessel($request);
        $supplier = Supplier::create($this->validated($request) + ['vessel_id' => $vessel->id]);

        return $this->ok(['supplier' => $supplier], 'Supplier added.', 201);
    }

    public function update(Request $request, Supplier $supplier)
    {
        $supplier->update($this->validated($request));

        return $this->ok(['supplier' => $supplier->fresh()], 'Supplier updated.');
    }

    public function destroy(Supplier $supplier)
    {
        $supplier->delete(); // prices cascade

        return $this->ok([], 'Supplier removed.');
    }

    /**
     * Price comparison: one row per item, one column per supplier, with the
     * cheapest supplier flagged. Scoped to a category (or a search) because
     * the whole catalogue times five suppliers is not a readable table.
     */
    public function comparison(Request $request)
    {
        $vessel = $this->vessel($request);

        $suppliers = Supplier::where(fn ($q) => $q->where('vessel_id', $vessel->id)->orWhereNull('vessel_id'))
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'port', 'currency', 'exchange_rate']);

        $items = Item::where('vessel_id', $vessel->id)
            ->where('is_active', true)
            ->when($request->integer('category_id'), fn ($q, $id) => $q->where('category_id', $id))
            ->when(trim((string) $request->query('search', '')), function ($q, $term) {
                $q->where(fn ($w) => $w->where('name', 'like', "%{$term}%")->orWhere('code', 'like', "%{$term}%"));
            })
            ->orderBy('name')
            ->limit(400)
            ->get(['id', 'code', 'name', 'variant', 'unit', 'unit_price', 'category_id']);

        $prices = SupplierPrice::whereIn('item_id', $items->pluck('id'))
            ->get(['supplier_id', 'item_id', 'price'])
            ->groupBy('item_id');

        $rates = $suppliers->pluck('exchange_rate', 'id');

        $rows = $items->map(function (Item $item) use ($prices, $rates) {
            $bySupplier = [];
            $bestId = null;
            $bestUsd = null;

            foreach ($prices[$item->id] ?? [] as $price) {
                $usd = round((float) $price->price * (float) ($rates[$price->supplier_id] ?? 1), 4);
                $bySupplier[$price->supplier_id] = ['price' => (float) $price->price, 'usd' => $usd];

                if ($bestUsd === null || $usd < $bestUsd) {
                    $bestUsd = $usd;
                    $bestId = $price->supplier_id;
                }
            }

            return [
                'id' => $item->id,
                'code' => $item->code,
                'name' => $item->name,
                'variant' => $item->variant,
                'unit' => $item->unit,
                'unit_price' => $item->unit_price === null ? null : (float) $item->unit_price,
                'prices' => $bySupplier,
                'best_supplier_id' => $bestId,
                'best_price_usd' => $bestUsd,
            ];
        });

        return $this->ok([
            'suppliers' => $suppliers,
            'categories' => Category::orderBy('sort')->orderBy('name')->get(['id', 'name']),
            'rows' => $rows,
        ]);
    }

    /** Save a supplier's quoted prices — blanks remove the quote. */
    public function savePrices(Request $request, Supplier $supplier)
    {
        $data = $request->validate([
            'prices' => ['present', 'array'],
            'prices.*.item_id' => ['required', 'integer', 'exists:provision_items,id'],
            'prices.*.price' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'quoted_at' => ['nullable', 'date'],
        ]);

        DB::transaction(function () use ($supplier, $data) {
            foreach ($data['prices'] as $row) {
                if ($row['price'] === null || $row['price'] === '') {
                    SupplierPrice::where('supplier_id', $supplier->id)->where('item_id', $row['item_id'])->delete();

                    continue;
                }

                SupplierPrice::updateOrCreate(
                    ['supplier_id' => $supplier->id, 'item_id' => $row['item_id']],
                    ['price' => $row['price'], 'quoted_at' => $data['quoted_at'] ?? now()->toDateString()],
                );
            }
        });

        return $this->ok([], 'Prices saved.');
    }

    /**
     * Adopt a supplier's prices as the catalogue's unit price — the number the
     * order value is calculated from.
     */
    public function applyPrices(Request $request, Supplier $supplier)
    {
        $rate = (float) ($supplier->exchange_rate ?: 1);
        $updated = 0;

        SupplierPrice::where('supplier_id', $supplier->id)
            ->chunkById(500, function ($chunk) use ($rate, &$updated) {
                foreach ($chunk as $price) {
                    $updated += Item::whereKey($price->item_id)
                        ->update(['unit_price' => round((float) $price->price * $rate, 4)]);
                }
            });

        return $this->ok(['updated' => $updated], "{$updated} prices applied from {$supplier->name}.");
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'port' => ['nullable', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:64'],
            'currency' => ['required', 'string', 'max:8'],
            'exchange_rate' => ['required', 'numeric', 'min:0.000001', 'max:1000000'],
            'notes' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }
}

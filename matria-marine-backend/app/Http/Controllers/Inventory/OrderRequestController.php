<?php

namespace App\Http\Controllers\Inventory;

use App\Models\Inventory\Item;
use App\Models\Inventory\OrderRequest;
use Illuminate\Http\Request;

/**
 * "Order some of this anyway."
 *
 * The requisition is calculated, and that is right: it lists what is below
 * target so nobody has to remember. But a cook looking at an item marked GOOD
 * still has reasons to want some — the charterer is aboard next week, this is
 * the last of it, the next port has nothing worth buying — and the arithmetic
 * cannot know any of them.
 *
 * Before this his only options were to leave it off the order, or to distort
 * the item's target until the calculator raised it for him. The second is what
 * people actually do, and it corrupts the figure every future order rests on.
 *
 * So the ask is kept here, apart from the calculation, and merged into the next
 * order marked as his. The sums stay honest and the cook gets his beef.
 */
class OrderRequestController extends InventoryController
{
    /** What is currently asked for, beyond what the calculation raised. */
    public function index(Request $request)
    {
        $vessel = $this->vessel($request);

        $rows = OrderRequest::with(['item:id,code,name,variant,unit,unit_price,current_stock', 'author:id,name'])
            ->where('vessel_id', $vessel->id)
            ->get()
            ->map(fn (OrderRequest $r) => [
                'id' => $r->id,
                'item_id' => $r->item_id,
                'code' => $r->item?->code,
                'name' => $r->item?->label(),
                'unit' => $r->item?->unit,
                'quantity' => (float) $r->quantity,
                'current_stock' => (float) ($r->item?->current_stock ?? 0),
                'unit_price' => $r->item?->unit_price === null ? null : (float) $r->item->unit_price,
                'note' => $r->note,
                'by' => $r->author?->name,
            ]);

        return $this->ok(['requests' => $rows->values()]);
    }

    /**
     * Ask for an item, at a quantity.
     *
     * Asking twice replaces the figure rather than adding to it: 5 kg and then
     * 3 kg is somebody changing their mind, not wanting 8.
     */
    public function store(Request $request)
    {
        $vessel = $this->vessel($request);

        $data = $request->validate([
            'item_id' => ['required', 'integer'],
            'quantity' => ['required', 'numeric', 'min:0.001', 'max:9999999'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $item = Item::where('vessel_id', $vessel->id)->whereKey($data['item_id'])->first();

        if (! $item) {
            return response()->json([
                'success' => false,
                'message' => 'That item is not on this vessel.',
            ], 422);
        }

        OrderRequest::updateOrCreate(
            ['vessel_id' => $vessel->id, 'item_id' => $item->id],
            [
                'quantity' => round((float) $data['quantity'], 3),
                'note' => $data['note'] ?? null,
                'created_by' => $request->user()?->id,
            ]
        );

        return $this->ok(
            ['count' => OrderRequest::where('vessel_id', $vessel->id)->count()],
            "{$item->label()} added to the next order."
        );
    }

    public function destroy(Request $request, OrderRequest $orderRequest)
    {
        if ((int) $orderRequest->vessel_id !== $this->vessel($request)->id) {
            abort(404, 'That request is not this vessel\'s.');
        }

        $orderRequest->delete();

        return $this->ok(
            ['count' => OrderRequest::where('vessel_id', $orderRequest->vessel_id)->count()],
            'Taken off the next order.'
        );
    }
}

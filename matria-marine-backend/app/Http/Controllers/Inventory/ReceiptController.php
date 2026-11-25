<?php

namespace App\Http\Controllers\Inventory;

use App\Models\Inventory\Item;
use App\Models\Inventory\Receipt;
use App\Models\Inventory\Requisition;
use App\Support\Inventory\MoneyVeil;
use App\Support\Inventory\PeriodLedger;
use App\Support\Inventory\StockLedger;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Receiving — record what actually arrived.
 *
 * A receipt is a draft while the crew counts. Confirming it is the only thing
 * that touches stock: it adds the received quantities to ROB and records them
 * as supplied on the open supply period.
 */
class ReceiptController extends InventoryController
{
    public function index(Request $request)
    {
        $vessel = $this->vessel($request);

        $rows = Receipt::with('supplier:id,name')
            ->withCount('items')
            ->where('vessel_id', $vessel->id)
            ->latest('received_date')->latest('id')
            ->get();

        return $this->ok([
            'receipts' => $rows,
            'expected' => $this->expected($vessel),
        ]);
    }

    /**
     * Orders the office has placed that the ship has not booked in yet.
     *
     * Until now nothing told a ship that something was on its way. The order
     * left the galley, was approved, was bought, and the next the cook knew of
     * it was a pallet on the quay — by which time he had to remember which
     * order it belonged to and go and find it. The sheet was always there to
     * be pre-filled; there was just nothing saying it existed.
     *
     * Issued is the honest line: the office has sent the order out to vendors,
     * so goods are coming. Approved is not — that is the ship waiting on the
     * office, which is a different thing and not a delivery to prepare for.
     *
     * An order with a confirmed delivery against it has arrived and drops off
     * the list on its own, whether or not anybody remembered to close it.
     *
     * @return array<int, array>
     */
    private function expected($vessel): array
    {
        return Requisition::withCount('items')
            ->with(['receipts:id,requisition_id,status'])
            ->where('vessel_id', $vessel->id)
            ->where('status', Requisition::ISSUED)
            ->whereDoesntHave('receipts', fn ($q) => $q->where('status', 'confirmed'))
            ->orderBy('req_date')->orderBy('id')
            ->get()
            ->map(function (Requisition $req) {
                // A draft already started means "carry on counting" rather than
                // "start", so the cook never ends up with two sheets for one
                // delivery.
                $draft = $req->receipts->firstWhere('status', 'draft');

                return [
                    'id' => $req->id,
                    'req_number' => $req->req_number,
                    'req_date' => $req->req_date?->toDateString(),
                    'required_by' => $req->required_by?->toDateString(),
                    'days_of_supply' => $req->days_of_supply,
                    'items_count' => $req->items_count,
                    'receipt_id' => $draft?->id,
                ];
            })
            ->values()
            ->all();
    }

    public function show(Request $request, Receipt $receipt)
    {
        $receipt->load(['items.item:id,code,name,variant,unit', 'supplier:id,name', 'requisition:id,req_number']);

        $payload = ['receipt' => $this->withAsked($receipt)];

        // The price still travels onto the batch when this is confirmed; the
        // cook simply is not shown it. His job at the gangway is to count what
        // came off the lorry against what was bought.
        return $this->ok($this->seesMoney($request)
            ? $payload
            : MoneyVeil::hide($payload, MoneyVeil::RECEIPT));
    }

    /**
     * The receipt with what the ship originally asked for on each line.
     *
     * Derived here rather than stored on the line, because it is not this
     * document's fact: the requisition already holds it, and a figure copied
     * into a second table is a figure that can disagree with the first. The
     * module's rule throughout — nothing calculated is kept.
     *
     * Absent for a delivery raised by hand, which answers to no order.
     */
    private function withAsked(Receipt $receipt): array
    {
        $payload = $receipt->toArray();

        if (! $receipt->requisition_id) {
            return $payload;
        }

        $asked = DB::table('provision_requisition_items')
            ->where('requisition_id', $receipt->requisition_id)
            ->pluck('order_qty', 'item_id');

        $payload['items'] = array_map(function (array $line) use ($asked) {
            $line['asked_qty'] = isset($asked[$line['item_id']])
                ? (float) $asked[$line['item_id']]
                : null;

            return $line;
        }, $payload['items'] ?? []);

        return $payload;
    }

    public function store(Request $request)
    {
        $vessel = $this->vessel($request);
        $data = $this->validated($request);

        // A sheet raised against an order is priced and quantified by the
        // server, from the quotation, whoever started it. The cook is not shown
        // prices and so cannot send them back; leaving the line blank would
        // open the batch at zero. The quantity ordered is settled ashore too,
        // and is read-only on the sheet, so neither figure is the browser's to
        // decide. A line that was never on the order has neither, and keeps
        // whatever it was given.
        $order = ($data['requisition_id'] ?? null)
            ? $this->orderPrices(Requisition::findOrFail($data['requisition_id']))
            : ['prices' => [], 'bought' => []];

        $held = $order['prices'];
        $bought = $order['bought'];

        $receipt = DB::transaction(function () use ($vessel, $data, $request, $held, $bought) {
            $receipt = Receipt::create([
                'vessel_id' => $vessel->id,
                'supplier_id' => $data['supplier_id'] ?? null,
                'requisition_id' => $data['requisition_id'] ?? null,
                'reference' => $data['reference'] ?? null,
                'received_date' => $data['received_date'] ?? Carbon::today()->toDateString(),
                'status' => 'draft',
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()?->id,
            ]);

            $lines = array_map(function (array $line) use ($held, $bought) {
                $id = (int) ($line['item_id'] ?? 0);

                if (($line['unit_price'] ?? null) === null && isset($held[$id])) {
                    $line['unit_price'] = $held[$id];
                }

                if (isset($bought[$id])) {
                    $line['ordered_qty'] = $bought[$id];
                }

                return $line;
            }, $data['items'] ?? []);

            $this->syncItems($receipt, $lines);

            return $receipt;
        });

        return $this->ok(['receipt' => $receipt->fresh('items')], 'Delivery saved as draft.', 201);
    }

    public function update(Request $request, Receipt $receipt)
    {
        if ($receipt->isConfirmed()) {
            return $this->locked();
        }

        $data = $this->validated($request);
        $fromOffice = $this->fromOffice($request);

        DB::transaction(function () use ($receipt, $data, $fromOffice) {
            $receipt->update([
                // array_key_exists, not ??: an absent key means the sender was
                // never shown the field, which is not the same as clearing it.
                'supplier_id' => array_key_exists('supplier_id', $data) ? $data['supplier_id'] : $receipt->supplier_id,
                'requisition_id' => $data['requisition_id'] ?? null,
                'reference' => $data['reference'] ?? null,
                'received_date' => $data['received_date'] ?? $receipt->received_date,
                'notes' => $data['notes'] ?? null,
            ]);

            $held = $this->held($receipt, $fromOffice);

            $receipt->items()->delete();
            $this->syncItems($receipt, $data['items'] ?? [], $held);
        });

        return $this->ok(['receipt' => $receipt->fresh('items')], 'Delivery updated.');
    }

    /**
     * Confirm the delivery: the only place ROB goes up.
     * Guarded so a double click can never add the same goods twice.
     */
    public function confirm(Request $request, Receipt $receipt)
    {
        if ($receipt->isConfirmed()) {
            return $this->locked('This delivery is already confirmed.');
        }

        $receipt->load('items');

        if ($receipt->items->isEmpty()) {
            return response()->json(['success' => false, 'message' => 'Add at least one line before confirming.'], 422);
        }

        $vessel = $receipt->vessel_id ? \App\Models\Inventory\Vessel::find($receipt->vessel_id) : $this->vessel($request);

        DB::transaction(function () use ($receipt, $vessel) {
            $ledger = [];

            foreach ($receipt->items as $line) {
                $qty = (float) $line->received_qty;

                if ($qty <= 0 || ! $line->item_id) {
                    continue;
                }

                $item = Item::whereKey($line->item_id)->lockForUpdate()->first();

                if (! $item) {
                    continue;
                }

                $ledger[$item->id] = ['qty' => $qty, 'rob_before' => (float) $item->current_stock];

                // Deliberately does NOT touch the item's standing price.
                //
                // What a delivery charged and what an item is worth are two
                // different figures and the store holds both. The lot below
                // keeps this delivery's price, so the galley is costed against
                // what each batch really cost. The item's unit_price stays the
                // price the office agreed with a vendor, which is what prices
                // the next order — and it comes from a quotation, not from a
                // delivery note.
                //
                // Overwriting it made a 3 kg top-up at a one-off price rewrite
                // the price of the 3 kg still on the shelf from the month
                // before: the shelf had not changed, only the paperwork had.
                // The office edits the standing price by hand, or fills it
                // from the month's quotations (Support\Inventory\OfferPrices).

                // The only place goods enter the store. Raises ROB and opens a
                // lot at this delivery's own price and expiry date.
                StockLedger::receive(
                    item: $item,
                    quantity: $qty,
                    unitCost: $line->unit_price === null ? null : (float) $line->unit_price,
                    expiresOn: $line->expires_on?->toDateString(),
                    receipt: $receipt,
                    receivedOn: $receipt->received_date
                        ? Carbon::parse($receipt->received_date)->toDateString()
                        : null,
                );
            }

            if ($ledger) {
                PeriodLedger::recordSupply($vessel, $ledger);
            }

            $receipt->update(['status' => 'confirmed', 'confirmed_at' => now()]);
        });

        return $this->ok(['receipt' => $receipt->fresh('items')], 'Delivery confirmed — stock updated.');
    }

    /**
     * Reopen a confirmed delivery, taking the goods back out of stock.
     *
     * Only while none of it has been used. Once the galley has started on a
     * delivery its cost has already been charged out of these lots, and there
     * is no honest way to unpick that — so the crew are told which items are
     * in use and asked to correct it with a withdrawal instead.
     */
    public function reopen(Request $request, Receipt $receipt)
    {
        if (! $receipt->isConfirmed()) {
            return response()->json(['success' => false, 'message' => 'This delivery is still a draft.'], 422);
        }

        $receipt->load('items');
        $vessel = \App\Models\Inventory\Vessel::find($receipt->vessel_id);

        $inUse = StockLedger::unreceive($receipt);

        if ($inUse) {
            return response()->json([
                'success' => false,
                'message' => 'This delivery has already been drawn on ('
                    .implode(', ', array_slice($inUse, 0, 3))
                    .(count($inUse) > 3 ? ', and others' : '')
                    .'), so it cannot be taken back out of stock. Correct the quantities with a withdrawal instead.',
            ], 422);
        }

        DB::transaction(function () use ($receipt, $vessel) {
            $ledger = [];

            foreach ($receipt->items as $line) {
                $qty = (float) $line->received_qty;

                if ($qty > 0 && $line->item_id) {
                    $ledger[$line->item_id] = $qty;
                }
            }

            if ($ledger && $vessel) {
                PeriodLedger::reverseSupply($vessel, $ledger);
            }

            $receipt->update(['status' => 'draft', 'confirmed_at' => null]);
        });

        return $this->ok(['receipt' => $receipt->fresh('items')], 'Delivery reopened — stock reversed.');
    }

    public function destroy(Receipt $receipt)
    {
        if ($receipt->isConfirmed()) {
            return $this->locked('Reopen the delivery before deleting it.');
        }

        $receipt->delete();

        return $this->ok([], 'Delivery deleted.');
    }

    /**
     * What each line of an order was bought at, and how much of it, by item.
     *
     * Three quantities exist on the way to a delivery and only two of them used
     * to be anywhere. The ship ASKED for a figure. The office went to vendors
     * and BOUGHT a figure, which is not always the same one — an order that
     * comes back over the vessel's budget gets trimmed on the quotation before
     * anybody buys it. Then the ship RECEIVED a figure.
     *
     * Counting a delivery against what was asked for rather than against what
     * was bought turned a halved order into a 450 Kg shortfall on the ship's
     * own record: a short delivery that never happened, against a chandler who
     * delivered in full.
     *
     * Prices follow the same rule and for the same reason. The quotation first,
     * because that is what the vendors actually charged; the order's own price
     * only as a fallback, and that is whatever the catalogue held the day the
     * order was written — for most lines nothing, because finding out what they
     * cost is why the order went to vendors at all.
     *
     * Resolved here rather than taken from the browser, because the cook is not
     * shown prices and so cannot echo them back. A sheet he starts has to be
     * priced by the server or not priced at all.
     *
     * @return array{prices: array<int, float>, bought: array<int, float>, asked: array<int, float>, offer: ?string}
     */
    private function orderPrices(Requisition $requisition): array
    {
        $requisition->loadMissing(['items', 'rfq']);

        $quoted = \App\Support\Inventory\OfferPrices::quotedFor($requisition);
        $prices = [];
        $bought = [];
        $asked = [];

        foreach ($requisition->items as $line) {
            if (! $line->item_id) {
                continue;
            }

            $id = (int) $line->item_id;

            $asked[$id] = (float) $line->order_qty;
            // No quotation, or a quotation that does not carry this line, means
            // nobody has revised it — so what was asked for is what was bought.
            $bought[$id] = array_key_exists($line->code, $quoted['quantities'])
                ? (float) $quoted['quantities'][$line->code]
                : (float) $line->order_qty;

            $price = $quoted['prices'][$line->code]
                ?? ($line->unit_price === null ? null : (float) $line->unit_price);

            if ($price !== null) {
                $prices[$id] = (float) $price;
            }
        }

        return ['prices' => $prices, 'bought' => $bought, 'asked' => $asked, 'offer' => $quoted['offer']];
    }

    /** Pre-fill a receiving sheet from a saved requisition. */
    public function fromRequisition(Request $request, Requisition $requisition)
    {
        $requisition->load(['items', 'rfq']);
        $priced = $this->orderPrices($requisition);

        $lines = $requisition->items->map(fn ($line) => [
            'item_id' => $line->item_id,
            'code' => $line->code,
            'description' => $line->description,
            'unit' => $line->unit,
            // What the ship asked for, kept beside what was bought so a trimmed
            // order reads as a trimmed order rather than as a short delivery.
            'asked_qty' => (float) $line->order_qty,
            'ordered_qty' => $priced['bought'][(int) $line->item_id] ?? (float) $line->order_qty,
            'received_qty' => $priced['bought'][(int) $line->item_id] ?? (float) $line->order_qty,
            'unit_price' => $priced['prices'][(int) $line->item_id] ?? null,
        ])->values()->all();

        $money = $this->seesMoney($request);

        return $this->ok([
            'requisition' => $requisition->only(['id', 'req_number', 'req_date', 'supplier_id']),
            // Blank for a cook, and it makes no difference to the batch: store()
            // prices a requisition's sheet from the same quotation on the way in.
            'lines' => $money ? $lines : MoneyVeil::hide($lines, MoneyVeil::RECEIPT),
            // Named so whoever does see the figures knows where they came from,
            // rather than finding numbers already in the boxes and wondering
            // who typed them.
            'quotation' => $money ? $priced['offer'] : null,
        ]);
    }

    /**
     * The figures on a saved sheet that a re-save is not allowed to move.
     *
     * Saving rebuilds every line from what the browser sent, which is fine for
     * the count — that is the whole point of the screen — and wrong for the
     * other two columns.
     *
     * What was ordered is the order's figure. Nobody types it, because the
     * moment it can be typed the Difference column stops meaning anything: a
     * short delivery is hidden by editing the order down to match, and the
     * record of the shortfall — the only reason anyone compares the two — is
     * gone.
     *
     * What it costs is the quotation's figure, and the ship does not negotiate
     * prices; she counts boxes. The office may still correct a line against the
     * invoice, because the office is answerable for the money and is the one
     * holding the invoice.
     *
     * @return array<int, array{ordered_qty?: float, unit_price?: float|null}>
     */
    private function held(Receipt $receipt, bool $fromOffice): array
    {
        $held = [];

        foreach ($receipt->items()->get(['item_id', 'ordered_qty', 'unit_price']) as $line) {
            $keep = ['ordered_qty' => (float) $line->ordered_qty];

            if (! $fromOffice) {
                $keep['unit_price'] = $line->unit_price === null ? null : (float) $line->unit_price;
            }

            $held[(int) $line->item_id] = $keep;
        }

        return $held;
    }

    /**
     * @param  array<int, array{ordered_qty?: float, unit_price?: float|null}>  $held
     */
    private function syncItems(Receipt $receipt, array $items, array $held = []): void
    {
        $sort = 0;
        $seen = [];

        foreach ($items as $line) {
            $itemId = (int) ($line['item_id'] ?? 0);

            if (! $itemId || isset($seen[$itemId])) {
                continue; // one line per item — the table has a unique key
            }

            $seen[$itemId] = true;

            // A line already on the sheet keeps its protected figures. A line
            // added by hand has none to keep, so it takes what it was given —
            // for a direct delivery there is no order and no quotation behind
            // it, and the price comes off the catalogue.
            //
            // array_key_exists throughout: a held price of null is a line
            // nobody has priced, which is a figure of its own and not a gap for
            // the payload to fill.
            $keep = $held[$itemId] ?? [];

            $receipt->items()->create([
                'item_id' => $itemId,
                'ordered_qty' => array_key_exists('ordered_qty', $keep)
                    ? $keep['ordered_qty']
                    : ($line['ordered_qty'] ?? 0),
                'received_qty' => $line['received_qty'] ?? 0,
                'unit_price' => array_key_exists('unit_price', $keep)
                    ? $keep['unit_price']
                    : ($line['unit_price'] ?? null),
                // The delivery note is where an expiry date is known; it
                // travels onto the lot when the receipt is confirmed.
                'expires_on' => $line['expires_on'] ?? null,
                'notes' => $line['notes'] ?? null,
                'sort' => $sort++,
            ]);
        }
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'supplier_id' => ['nullable', 'integer', 'exists:provision_suppliers,id'],
            'requisition_id' => ['nullable', 'integer', 'exists:provision_requisitions,id'],
            'reference' => ['nullable', 'string', 'max:255'],
            'received_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:255'],
            'items' => ['present', 'array'],
            'items.*.item_id' => ['required', 'integer', 'exists:provision_items,id'],
            'items.*.ordered_qty' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'items.*.received_qty' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'items.*.expires_on' => ['nullable', 'date'],
            'items.*.notes' => ['nullable', 'string', 'max:255'],
        ]);

        // A vessel account cannot read the supplier list, so it is not naming
        // one either. Dropped rather than refused: the key is simply absent,
        // and the callers below leave whatever the office already recorded
        // alone — a cook correcting the quantities on an office-entered draft
        // must not blank the chandler's name on his way past.
        if (! $this->fromOffice($request)) {
            unset($data['supplier_id']);
        }

        return $data;
    }

    private function locked(string $message = 'This delivery is confirmed and can no longer be edited.')
    {
        return response()->json(['success' => false, 'message' => $message], 422);
    }
}

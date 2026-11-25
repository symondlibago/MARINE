<?php

namespace App\Http\Controllers\Inventory;

use App\Models\Inventory\Category;
use App\Models\Inventory\Item;
use App\Models\Inventory\OrderRequest;
use App\Models\Inventory\Requisition;
use App\Support\Inventory\BudgetCheck;
use App\Support\Inventory\MoneyVeil;
use App\Support\Inventory\ProvisionCalculator;
use App\Support\Inventory\ReqNumber;
use App\Support\Inventory\RequisitionBridge;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The requisition — every item whose order quantity is above zero.
 *
 * The live list is calculated, never stored. Issuing one snapshots those lines
 * into a requisition record so the office has order history to report on and
 * something to receive against.
 */
class RequisitionController extends InventoryController
{
    /**
     * The live "to order" list, straight from the calculator.
     *
     * `days` asks what the order would look like written to cover a different
     * stretch of time — which is the question behind the budget warning, so the
     * two travel together.
     */
    public function preview(Request $request)
    {
        $vessel = $this->vessel($request);
        $days = $this->days($request, $vessel);

        [$lines, $summary] = $this->buildLines($vessel, $days);
        $total = round(array_sum(array_column($lines, 'order_value')), 2);

        return $this->ok([
            'vessel' => $this->presentVessel($vessel),
            // $total above was summed from these lines with their prices on.
            // Only the copy going to the screen loses them.
            'lines' => $this->presentItems($lines),
            'summary' => $summary,
            'days_of_supply' => $days,
            'total_value' => $this->seesMoney($request) ? $total : null,
            'budget' => $this->verdict($request, $vessel, $total, $days),
        ]);
    }

    /**
     * The budget verdict, in the terms this account is allowed.
     *
     * The office and the master get the figures. The cook gets the finding —
     * that the order is too big and something has to come off — which is the
     * part that changes what he does. Both come out of BudgetCheck, so the
     * ship and the office are never warned on different arithmetic.
     */
    /**
     * What a set of ticked lines comes to, priced here rather than in the
     * browser.
     *
     * Scored through the calculator for the days the order covers, so it is the
     * same price the saved order would carry — the batch on the shelf if there
     * is one, the office's standing figure if there is not. Anything that is
     * not this vessel's, or has no price at all, contributes nothing: an order
     * cannot be judged over budget on the strength of a line nobody has priced.
     *
     * @param  array<int, array{item_id: int|string, order_qty: int|float|string}>  $lines
     */
    private function valueOf($vessel, array $lines, int $days): float
    {
        $calc = ProvisionCalculator::for($vessel, $days);

        $items = Item::where('vessel_id', $vessel->id)
            ->whereIn('id', array_map(fn ($l) => (int) $l['item_id'], $lines))
            ->get()
            ->keyBy('id');

        $total = 0.0;

        foreach ($lines as $line) {
            $item = $items->get((int) $line['item_id']);

            if (! $item) {
                continue;
            }

            $price = $calc->calculate($item)['unit_price'];

            if ($price !== null) {
                $total += (float) $line['order_qty'] * (float) $price;
            }
        }

        return round($total, 2);
    }

    private function verdict(Request $request, $vessel, float $value, ?int $days = null): array
    {
        $check = BudgetCheck::for($vessel, $value, $days);

        return $this->seesMoney($request) ? $check : BudgetCheck::withoutMoney($check);
    }

    /**
     * What the office came back with, for the account that answers for it.
     *
     * The requisition's own prices are hidden from the ship because they are a
     * guess — last month's catalogue, mostly blank. A quotation is not a guess.
     * It is what the vendors charged and what the vessel will be invoiced, and
     * by the time it exists the master is being asked to live with a real
     * figure. Hiding that from him was the gap: he could see the order he had
     * approved, could not see what it had turned out to cost, and so had
     * nothing to push back on.
     *
     * So: no prices before the quotation, every price after it. The line the
     * ship is kept on the office's side of is the vendors and the margin, never
     * the bill.
     *
     * The budget is checked against the quotation's grand total — delivery and
     * tax included — because that is the number that lands on the invoice, not
     * the subtotal of the lines.
     */
    private function quotation(Request $request, Requisition $requisition): ?array
    {
        if (! $this->seesMoney($request)) {
            return null;
        }

        $requisition->loadMissing('rfq');
        $quoted = \App\Support\Inventory\OfferPrices::quotedFor($requisition);

        if (! $quoted['number']) {
            return null;
        }

        return [
            'number' => $quoted['number'],
            'status' => $quoted['status'],
            'total' => $quoted['total'],
            'currency' => $quoted['currency'],
            // Per unit, by the ship's own catalogue code — the same match the
            // receiving sheet and the catalogue fill are built on, never by
            // description.
            'prices' => $quoted['prices'],
            'budget' => $quoted['total'] === null
                ? null
                : BudgetCheck::for($requisition->vessel, (float) $quoted['total'], $requisition->days()),
        ];
    }

    /**
     * An order as this account is allowed to read it.
     *
     * Two different rules on one document, which is why they are applied in two
     * steps. The line prices come off for anybody on board, master included,
     * because a requisition is a request for quantities and its prices are a
     * guess until vendors answer. The total and the budget come off for the
     * cook alone, because the master is the one who signs it.
     */
    private function presentRequisition(Request $request, array $requisition): array
    {
        if ($this->fromOffice($request)) {
            return $requisition;
        }

        $requisition = MoneyVeil::hide($requisition, MoneyVeil::REQUISITION_LINES);

        return $this->seesMoney($request)
            ? $requisition
            : MoneyVeil::hide($requisition, MoneyVeil::REQUISITION_TOTALS);
    }

    /**
     * The budget verdict on an arbitrary figure.
     *
     * The screen lets the cook untick lines and retype quantities, so the
     * total it is warning about is not the one the preview calculated. Rather
     * than keep a second copy of the rules in JavaScript — which is how two
     * answers to "are we over budget" come about — it asks the one class that
     * decides.
     */
    public function budget(Request $request)
    {
        $vessel = $this->vessel($request);

        $data = $request->validate([
            'value' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'lines' => ['nullable', 'array', 'max:2000'],
            'lines.*.item_id' => ['required_with:lines', 'integer'],
            'lines.*.order_qty' => ['required_with:lines', 'numeric', 'min:0', 'max:9999999'],
        ]);

        $days = $this->days($request, $vessel);

        // Two ways to ask, and the lines are the better one.
        //
        // The screen used to total the order itself and send the figure. That
        // only works for somebody who can see prices, and the cook cannot —
        // his lines come down with the money taken out, so his total was zero
        // and he was told every order was comfortably inside the budget. Which
        // is the opposite of the one thing he is supposed to still be told.
        //
        // So he sends what he is ordering and the server prices it. Same
        // endpoint, same arithmetic, and the ship never needs a price to find
        // out that she is asking for too much.
        $value = ! empty($data['lines'])
            ? $this->valueOf($vessel, $data['lines'], $days)
            : (float) ($data['value'] ?? 0);

        return $this->ok([
            'budget' => $this->verdict($request, $vessel, $value, $days),
        ]);
    }

    public function index(Request $request)
    {
        $vessel = $this->vessel($request);

        $rows = Requisition::with(['supplier:id,name', 'approver:id,name', 'submitter:id,name'])
            ->where('vessel_id', $vessel->id)
            ->withCount('items')
            ->latest('req_date')->latest('id')
            ->get();

        return $this->ok([
            'requisitions' => $rows
                ->map(fn (Requisition $r) => $this->presentRequisition($request, $this->summarise($r)))
                ->values(),
            'statuses' => collect(Requisition::STATUSES)
                ->map(fn ($label, $value) => ['value' => $value, 'label' => $label])
                ->values(),
            // So the ship can see at a glance what is sitting on the master's
            // desk without reading down the list.
            'waiting' => $rows->where('status', Requisition::PENDING)->count(),
        ]);
    }

    public function show(Request $request, Requisition $requisition)
    {
        $this->confine($request, $requisition->vessel);

        $requisition->load([
            'items', 'supplier:id,name', 'creator:id,name,email',
            'submitter:id,name', 'approver:id,name', 'vessel:id,name,budget,supply_period_days',
        ]);

        return $this->ok([
            'requisition' => $this->presentRequisition($request, $requisition->toArray()),
            'status_label' => $requisition->statusLabel(),
            'editable' => $requisition->isEditable(),
            'budget' => $this->verdict(
                $request,
                $requisition->vessel,
                (float) $requisition->total_value,
                $requisition->days()
            ),
            'quotation' => $this->quotation($request, $requisition),
        ]);
    }

    /* --------------------------- the approval chain -------------------------- */

    /**
     * The cook sends it up.
     *
     * Nothing about the order changes; it simply stops being his to edit and
     * starts being the master's to answer. An order sent back and resubmitted
     * clears the refusal so the master is not reading a stale reason.
     */
    public function submit(Request $request, Requisition $requisition)
    {
        $this->confine($request, $requisition->vessel);

        if (! $requisition->isEditable()) {
            return $this->refuse(
                "{$requisition->req_number} is already {$requisition->statusLabel()} — it cannot be sent up again."
            );
        }

        if ($requisition->items()->count() === 0) {
            return $this->refuse('There is nothing on this order to approve.');
        }

        $requisition->update([
            'status' => Requisition::PENDING,
            'submitted_at' => now(),
            'submitted_by' => $request->user()?->id,
            'declined_at' => null,
            'decline_reason' => null,
        ]);

        return $this->ok(
            ['requisition' => $this->summarise($requisition->fresh())],
            "{$requisition->req_number} sent to the master for approval."
        );
    }

    /**
     * The cook takes his own order back before anybody has acted on it.
     *
     * Not a step in the chain so much as an undo of one. A cook who sent an
     * order up a minute early had no way back without asking the master to
     * decline it, which puts a refusal and a made-up reason on the record of an
     * order nobody objected to. This leaves no trace, because nothing happened.
     *
     * Only while it is still waiting: once the master has approved it the
     * decision is his and it is on the office's list, and once the office has
     * bought against it there are vendors involved.
     */
    public function pullBack(Request $request, Requisition $requisition)
    {
        $this->confine($request, $requisition->vessel);

        if ($requisition->status !== Requisition::PENDING) {
            return $this->refuse(
                $requisition->isEditable()
                    ? "{$requisition->req_number} has not been sent up — it is still yours to edit."
                    : "{$requisition->req_number} is {$requisition->statusLabel()}. The master has already answered it."
            );
        }

        $requisition->update([
            'status' => Requisition::DRAFT,
            'submitted_at' => null,
            'submitted_by' => null,
        ]);

        return $this->ok(
            ['requisition' => $this->summarise($requisition->fresh())],
            "{$requisition->req_number} pulled back — it is yours to change again."
        );
    }

    /**
     * The master signs it off, and it appears on the office's list.
     *
     * The budget figure is frozen here rather than at the draft, because this
     * is the moment somebody took responsibility for the spend — if the office
     * changes the vessel's budget next week, what was approved against should
     * still read correctly.
     */
    public function approve(Request $request, Requisition $requisition)
    {
        $this->confine($request, $requisition->vessel);

        if ($requisition->status !== Requisition::PENDING) {
            return $this->refuse(
                $requisition->status === Requisition::APPROVED
                    ? "{$requisition->req_number} is already approved."
                    : "{$requisition->req_number} has not been sent up for approval yet."
            );
        }

        $requisition->update([
            'status' => Requisition::APPROVED,
            'approved_at' => now(),
            'approved_by' => $request->user()?->id,
            'budget_at_issue' => $requisition->vessel?->budget,
        ]);

        return $this->ok(
            ['requisition' => $this->summarise($requisition->fresh())],
            "{$requisition->req_number} approved — the office can see it now."
        );
    }

    /**
     * The master sends it back, with a reason.
     *
     * The reason is required. "Declined" on its own tells the cook to guess,
     * and he will guess that the master is being difficult.
     */
    public function decline(Request $request, Requisition $requisition)
    {
        $this->confine($request, $requisition->vessel);

        if ($requisition->status !== Requisition::PENDING) {
            return $this->refuse("{$requisition->req_number} is not waiting for approval.");
        }

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ], [
            'reason.required' => 'Say why, so the cook knows what to change.',
        ]);

        $requisition->update([
            'status' => Requisition::DECLINED,
            'declined_at' => now(),
            'decline_reason' => $data['reason'],
            'approved_at' => null,
            'approved_by' => null,
        ]);

        return $this->ok(
            ['requisition' => $this->summarise($requisition->fresh())],
            "{$requisition->req_number} sent back to the galley."
        );
    }

    /**
     * Hand an approved order to procurement.
     *
     * It becomes an ordinary enquiry — the same table, the same screens, the
     * same numbering as the chandling trade — stamped as having come from the
     * provisions side, so the office buys it the way it buys everything else
     * and the provisions view can still pick out its own.
     *
     * Pressing it twice returns the same enquiry. "Send to procurement" is a
     * button somebody will double-click, and a second enquiry carrying the
     * same ship's order would be bought twice.
     */
    public function toEnquiry(Request $request, Requisition $requisition)
    {
        if (! in_array($requisition->status, Requisition::AT_THE_OFFICE, true)) {
            return $this->refuse(
                "{$requisition->req_number} has not been approved on board yet, so there is nothing to buy."
            );
        }

        if ($requisition->items()->count() === 0) {
            return $this->refuse('There is nothing on this order.');
        }

        $existing = $requisition->rfq;
        $rfq = RequisitionBridge::enquiryFor($requisition, $request->user());

        return $this->ok(
            [
                'rfq' => $rfq->only(['id', 'reference', 'status', 'origin']),
                'requisition' => $this->summarise($requisition->fresh()),
            ],
            $existing
                ? "Already with procurement as enquiry {$rfq->reference}."
                : "{$requisition->req_number} is now enquiry {$rfq->reference} — ask vendors for prices from there."
        );
    }

    /**
     * Every approved order across the fleet — the office's own list.
     *
     * This is the handover: a requisition on this list has been approved on
     * board and is waiting to be bought. It is the only inventory screen that
     * reads across vessels, which is why it does not take one.
     */
    public function inbox(Request $request)
    {
        $rows = Requisition::with(['vessel:id,name', 'approver:id,name', 'supplier:id,name', 'rfq:id,requisition_id,reference,status'])
            ->whereIn('status', Requisition::AT_THE_OFFICE)
            ->when(
                $request->filled('status'),
                fn ($q) => $q->where('status', $request->query('status')),
                fn ($q) => $q->where('status', Requisition::APPROVED)
            )
            ->when($request->integer('vessel_id'), fn ($q, $id) => $q->where('vessel_id', $id))
            ->withCount('items')
            ->orderByDesc('approved_at')->orderByDesc('id')
            ->get();

        $counts = Requisition::selectRaw('status, COUNT(*) as n')
            ->whereIn('status', Requisition::AT_THE_OFFICE)
            ->groupBy('status')
            ->pluck('n', 'status');

        return $this->ok([
            'requisitions' => $rows->map(fn (Requisition $r) => $this->summarise($r) + [
                'vessel' => $r->vessel?->only(['id', 'name']),
            ])->values(),
            'counts' => [
                'approved' => (int) ($counts[Requisition::APPROVED] ?? 0),
                'issued' => (int) ($counts[Requisition::ISSUED] ?? 0),
                'closed' => (int) ($counts[Requisition::CLOSED] ?? 0),
            ],
        ]);
    }

    /**
     * Freeze the list into a saved order.
     *
     * The calculator decides what *needs* ordering; the office decides what
     * actually goes on the order. A reefer with no room, an item with no price
     * yet, a supplier who does not stock it — all ordinary reasons to drop a
     * line or change a quantity, and none of them something the arithmetic can
     * know about. So the screen sends its lines and they are honoured.
     *
     * Send no lines and the whole calculated list is saved, which is what the
     * screen did before it could pick.
     */
    public function store(Request $request)
    {
        $vessel = $this->vessel($request);
        $data = $request->validate([
            'supplier_id' => ['nullable', 'integer', 'exists:provision_suppliers,id'],
            'req_date' => ['nullable', 'date'],
            'required_by' => ['nullable', 'date'],
            'days_of_supply' => ['nullable', 'integer', 'min:1', 'max:365'],
            'notes' => ['nullable', 'string', 'max:255'],
            'lines' => ['sometimes', 'array'],
            'lines.*.item_id' => ['required', 'integer'],
            'lines.*.order_qty' => ['required', 'numeric', 'min:0.001', 'max:9999999'],
        ]);

        $days = $this->days($request, $vessel);

        [$lines] = $this->buildLines($vessel, $days);

        if ($request->has('lines')) {
            $lines = $this->chosen($lines, $data['lines'], $vessel);
        }

        if (! $lines) {
            return response()->json([
                'success' => false,
                'message' => $request->has('lines')
                    ? 'Nothing selected to order.'
                    : 'Nothing to order — every item is at or above its target quantity.',
            ], 422);
        }

        $requisition = DB::transaction(function () use ($vessel, $data, $lines, $request, $days) {
            $req = Requisition::create([
                'vessel_id' => $vessel->id,
                'supplier_id' => $data['supplier_id'] ?? null,
                'req_number' => ReqNumber::next(),
                'req_date' => $data['req_date'] ?? Carbon::today()->toDateString(),
                'required_by' => $data['required_by'] ?? $vessel->next_supply_date,
                'days_of_supply' => $days,
                'status' => Requisition::DRAFT,
                'currency' => 'USD',
                'budget_at_issue' => $vessel->budget,
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()?->id,
            ]);

            $sort = 0;
            foreach ($lines as $line) {
                $req->items()->create([
                    'item_id' => $line['id'],
                    'code' => $line['code'],
                    'description' => trim($line['name'].' '.(string) $line['variant']),
                    'unit' => $line['unit'],
                    'rob_at_issue' => $line['current_stock'],
                    'target_qty' => $line['target_qty'],
                    'order_qty' => $line['order_qty'],
                    'unit_price' => $line['unit_price'],
                    'line_total' => $line['order_value'],
                    'sort' => $sort++,
                ]);
            }

            $req->recalcTotal();

            // Cleared once they are on an order. A standing request that
            // survived being ordered would turn up on the next one as well.
            OrderRequest::where('vessel_id', $vessel->id)
                ->whereIn('item_id', array_column($lines, 'id'))
                ->delete();

            return $req;
        });

        $budget = $this->verdict($request, $vessel, (float) $requisition->total_value, $days);

        return $this->ok(
            [
                'requisition' => $this->presentRequisition($request, $requisition->fresh('items')->toArray()),
                'budget' => $budget,
            ],
            // The warning travels with the save, so the cook sees it on the
            // order he just wrote rather than having to go looking. Its wording
            // is already the one his account is allowed — see verdict() above.
            $budget['message']
                ? 'Requisition saved. '.$budget['message']
                : 'Requisition saved.',
            201
        );
    }

    public function update(Request $request, Requisition $requisition)
    {
        $this->confine($request, $requisition->vessel);

        $data = $request->validate([
            'supplier_id' => ['nullable', 'integer', 'exists:provision_suppliers,id'],
            'required_by' => ['nullable', 'date'],
            'days_of_supply' => ['nullable', 'integer', 'min:1', 'max:365'],
            'notes' => ['nullable', 'string', 'max:255'],
            // Rewriting what is on the order: a quantity changed, a line
            // dropped, an item remembered after it was saved. Sent whole rather
            // than as a patch per line, because that is what the screen holds.
            'lines' => ['sometimes', 'array'],
            'lines.*.item_id' => ['required', 'integer'],
            'lines.*.order_qty' => ['required', 'numeric', 'min:0.001', 'max:9999999'],
            // The office's end of the lifecycle only. How an order gets from
            // the galley to the office is the approval chain above, and going
            // round it by posting a status would be the one way to approve your
            // own order.
            'status' => ['nullable', 'in:issued,closed'],
        ]);

        if ($request->has('lines')) {
            // Only while the order is still the ship's. Once it is with the
            // master it is his to answer, and a cook who could still edit it
            // could change what was signed for after it was signed.
            if (! $requisition->isEditable()) {
                return $this->refuse(
                    $requisition->status === Requisition::PENDING
                        ? "{$requisition->req_number} is with the master. Ask him to send it back if it needs changing."
                        : "{$requisition->req_number} is {$requisition->statusLabel()} and can no longer be changed."
                );
            }

            if (! $data['lines']) {
                return $this->refuse('An order needs at least one line. Delete it instead if it is not wanted.');
            }
        }

        if (array_key_exists('status', $data) && $data['status'] !== null) {
            if (! $this->fromOffice($request)) {
                return $this->refuse('Only the office marks an order as sent out.');
            }

            if (! in_array($requisition->status, Requisition::AT_THE_OFFICE, true)) {
                return $this->refuse(
                    "{$requisition->req_number} has not been approved on board yet."
                );
            }

            if ($data['status'] === Requisition::ISSUED && ! $requisition->issued_at) {
                $data['issued_at'] = now();
            }
        }

        $lines = $data['lines'] ?? null;
        unset($data['lines']);          // not a column

        DB::transaction(function () use ($requisition, $data, $lines, $request) {
            $requisition->update($data);

            if ($lines === null) {
                return;
            }

            $vessel = $requisition->vessel;
            $days = $requisition->fresh()->days();

            // Every line is scored again against today's figures, not patched
            // in place. The order is being asked for afresh, so the ROB and the
            // target it carries should be the ones that were true when it was
            // last written — and a line added now has no older snapshot to keep
            // anyway. Half-fresh, half-stale would be the one unreadable state.
            [$all] = $this->buildLines($vessel, $days);
            $rebuilt = $this->chosen($all, $lines, $vessel);

            $requisition->items()->delete();

            $sort = 0;
            foreach ($rebuilt as $line) {
                $requisition->items()->create([
                    'item_id' => $line['id'],
                    'code' => $line['code'],
                    'description' => trim($line['name'].' '.(string) $line['variant']),
                    'unit' => $line['unit'],
                    'rob_at_issue' => $line['current_stock'],
                    'target_qty' => $line['target_qty'],
                    'order_qty' => $line['order_qty'],
                    'unit_price' => $line['unit_price'],
                    'line_total' => $line['order_value'],
                    'sort' => $sort++,
                ]);
            }

            $requisition->recalcTotal();

            // Same as a fresh save: a standing request that survived being put
            // on an order would turn up on the next one too.
            OrderRequest::where('vessel_id', $vessel->id)
                ->whereIn('item_id', array_column($rebuilt, 'id'))
                ->delete();
        });

        $requisition = $requisition->fresh('items');

        return $this->ok(
            [
                'requisition' => $this->presentRequisition($request, $requisition->toArray()),
                'budget' => $this->verdict(
                    $request,
                    $requisition->vessel,
                    (float) $requisition->total_value,
                    $requisition->days()
                ),
            ],
            $lines === null
                ? 'Requisition updated.'
                : "{$requisition->req_number} updated — ".count($requisition->items)." lines."
        );
    }

    public function destroy(Request $request, Requisition $requisition)
    {
        $this->confine($request, $requisition->vessel);

        if (! $requisition->isEditable()) {
            return $this->refuse(
                $requisition->status === Requisition::PENDING
                    ? 'This order is with the master. Ask him to send it back if it needs changing.'
                    : "{$requisition->req_number} is {$requisition->statusLabel()} and is part of the vessel's record."
            );
        }

        $requisition->delete(); // items cascade

        return $this->ok([], 'Requisition deleted.');
    }

    /** Printable order — the office's replacement for the workbook print-out. */
    public function pdf(Request $request, Requisition $requisition)
    {
        $this->confine($request, $requisition->vessel);

        $requisition->load(['items', 'supplier', 'creator:id,name,email,phone', 'vessel']);

        $pdf = Pdf::loadView('pdf.inventory.requisition', [
            'req' => $requisition,
            'company' => config('procurement.company'),
            'logo' => $this->logo(),
            // The same rule the screen follows, and it is the document's rather
            // than the reader's: the ship's copy is a request for quantities,
            // the office's copy is that plus what we expect to pay. The prices
            // on file are last month's, so a master trimming his order against
            // them would be working from a figure nobody will be charged. This
            // one stays on fromOffice for that reason, even though the master
            // is shown money elsewhere.
            'money' => $this->fromOffice($request),
        ])->setPaper('a4');

        return $pdf->download($requisition->req_number.'.pdf');
    }

    /**
     * The lines the office picked, at the quantities it asked for.
     *
     * An item the calculator did not raise is still allowed on the order —
     * somebody has a reason to buy it — but it is scored the same way as the
     * rest so the saved line carries a real target and ROB beside the quantity.
     * What the calculator would have ordered stays recoverable from those two,
     * so a line ordered against the advice can always be told apart later.
     *
     * @param  array<int, array>  $calculated
     * @param  array<int, array{item_id: int, order_qty: float}>  $picked
     * @return array<int, array>
     */
    private function chosen(array $calculated, array $picked, $vessel): array
    {
        $byId = collect($calculated)->keyBy('id');
        $offList = collect($picked)->pluck('item_id')
            ->reject(fn ($id) => $byId->has($id))
            ->unique();

        if ($offList->isNotEmpty()) {
            $calc = ProvisionCalculator::for($vessel);
            $names = Category::pluck('name', 'id');

            $extras = Item::where('vessel_id', $vessel->id)
                ->where('is_active', true)
                ->whereIn('id', $offList)
                ->get();

            foreach ($extras as $item) {
                $byId[$item->id] = $this->presentItem($item, $calc, $names[$item->category_id] ?? null);
            }
        }

        $lines = [];
        $seen = [];

        foreach ($picked as $pick) {
            $id = (int) $pick['item_id'];
            $qty = round((float) $pick['order_qty'], 3);

            // One line per item, and nothing that is not this vessel's.
            if ($qty <= 0 || isset($seen[$id]) || ! $byId->has($id)) {
                continue;
            }
            $seen[$id] = true;

            $row = $byId[$id];
            $row['order_qty'] = $qty;
            $row['order_value'] = $row['unit_price'] === null ? 0.0 : round($qty * (float) $row['unit_price'], 2);

            $lines[] = $row;
        }

        return $lines;
    }

    /** How many days this order has to cover, in days rather than guesswork. */
    private function days(Request $request, $vessel): int
    {
        $asked = (int) $request->input('days_of_supply', $request->input('days', 0));

        if ($asked >= 1 && $asked <= 365) {
            return $asked;
        }

        return max(1, (int) ($vessel->supply_period_days ?: 30));
    }

    /** One order as a list row: enough to decide, not enough to fill a screen. */
    private function summarise(Requisition $requisition): array
    {
        return [
            'id' => $requisition->id,
            'req_number' => $requisition->req_number,
            'req_date' => $requisition->req_date?->toDateString(),
            'required_by' => $requisition->required_by?->toDateString(),
            'days_of_supply' => $requisition->days(),
            'status' => $requisition->status,
            'status_label' => $requisition->statusLabel(),
            'editable' => $requisition->isEditable(),
            'items_count' => $requisition->items_count ?? $requisition->items()->count(),
            'total_value' => round((float) $requisition->total_value, 2),
            'budget_at_issue' => $requisition->budget_at_issue === null
                ? null
                : round((float) $requisition->budget_at_issue, 2),
            'supplier' => $requisition->supplier?->name,
            // The enquiry the office raised from it, once it has one.
            'rfq' => $requisition->relationLoaded('rfq') && $requisition->rfq
                ? $requisition->rfq->only(['id', 'reference', 'status'])
                : null,
            'submitted_at' => $requisition->submitted_at?->toDateTimeString(),
            'submitted_by' => $requisition->submitter?->name,
            'approved_at' => $requisition->approved_at?->toDateTimeString(),
            'approved_by' => $requisition->approver?->name,
            'declined_at' => $requisition->declined_at?->toDateTimeString(),
            'decline_reason' => $requisition->decline_reason,
            'issued_at' => $requisition->issued_at?->toDateTimeString(),
            'notes' => $requisition->notes,
        ];
    }

    private function refuse(string $message)
    {
        return response()->json(['success' => false, 'message' => $message], 422);
    }

    /**
     * Score the catalogue and keep only what needs restocking — plus whatever
     * the cook asked for regardless.
     *
     * The two are merged here rather than kept apart on screen, because what
     * the office needs is one order. Each line says which it is: `requested`
     * marks a line the cook asked for, and `advised_qty` keeps what the
     * calculator would have ordered beside it, so a quantity set against the
     * advice can always be told from one that followed it.
     *
     * @return array{0: array<int, array>, 1: array}
     */
    private function buildLines($vessel, ?int $days = null): array
    {
        $calc = ProvisionCalculator::for($vessel, $days);
        $names = Category::pluck('name', 'id');

        $items = Item::where('vessel_id', $vessel->id)
            ->where('is_active', true)
            ->orderBy('category_id')->orderBy('sort')->orderBy('name')
            ->get();

        $asked = OrderRequest::where('vessel_id', $vessel->id)
            ->get()
            ->keyBy('item_id');

        $all = [];
        $lines = [];

        foreach ($items as $item) {
            $row = $this->presentItem($item, $calc, $names[$item->category_id] ?? null);
            $all[] = $row;

            $request = $asked->get($item->id);

            if ($request) {
                // What he asked for wins over what the sum says. He is looking
                // at the shelf and he knows what is coming.
                $row['advised_qty'] = $row['order_qty'];
                $row['order_qty'] = (float) $request->quantity;
                $row['order_value'] = $row['unit_price'] === null
                    ? 0.0
                    : round($row['order_qty'] * (float) $row['unit_price'], 2);
                $row['requested'] = true;
                $row['request_note'] = $request->note;
                // So the screen can take the ask back. Untick only drops the
                // line from TODAY'S order — the standing request survives and
                // the item is back on the list tomorrow, which is right when
                // somebody meant it and wrong when they pressed Order by
                // mistake. Cancelling needs the request itself.
                $row['request_id'] = $request->id;

                $lines[] = $row;

                continue;
            }

            if ($row['order_qty'] > 0) {
                $row['advised_qty'] = $row['order_qty'];
                $row['requested'] = false;
                $lines[] = $row;
            }
        }

        return [$lines, $this->presentSummary($calc->summarize($all))];
    }

    private function logo(): ?string
    {
        $path = public_path('logo.png');

        return is_file($path) ? 'data:image/png;base64,'.base64_encode(file_get_contents($path)) : null;
    }
}

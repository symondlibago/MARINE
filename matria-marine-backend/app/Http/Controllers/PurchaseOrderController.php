<?php

namespace App\Http\Controllers;

use App\Mail\PurchaseOrderMail;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderAttachment;
use App\Models\Quote;
use App\Models\SentLog;
use App\Models\Rfq;
use App\Models\Vendor;
use App\Support\DocNumber;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PurchaseOrderController extends Controller
{
    public function index(Request $request)
    {
        $query = PurchaseOrder::query()
            ->with(['vendor:id,name', 'rfq:id,reference,ship_name,customer_id,customer_reference', 'rfq.customer:id,name', 'creator:id,name'])
            ->withCount('items')
            ->orderByDesc('id');

        if ($rfqId = $request->query('rfq_id')) {
            $query->where('rfq_id', $rfqId);
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('po_number', 'like', "%{$search}%")
                    ->orWhereHas('vendor', fn ($v) => $v->where('name', 'like', "%{$search}%"));
            });
        }

        $pos = $query->get()->map(fn (PurchaseOrder $po) => [
            'id' => $po->id,
            'po_number' => $po->po_number,
            'rfq_id' => $po->rfq_id,
            'reference' => $po->rfq?->reference,
            // No enquiry behind it — bought directly, e.g. office items.
            'is_direct' => $po->rfq_id === null,
            'vendor' => $po->vendor?->name,
            'prepared_by' => $po->creator?->name,
            // Who the goods are ultimately for, and the reference they know it
            // by — a PO is easier to place from the job than from its own number.
            'customer' => $po->rfq?->customer?->name,
            'customer_reference' => $po->rfq?->customer_reference,
            'ship_name' => $po->ship_name ?: $po->rfq?->ship_name,
            'currency' => $po->currency,
            'status' => $po->status,
            'subtotal' => (float) $po->subtotal,
            'items_count' => $po->items_count,
            'issued_date' => $po->issued_date?->toDateString(),
            'expected_date' => $po->expected_date?->toDateString(),
            'accepted_at' => $po->accepted_at?->toIso8601String(),
            'created_at' => $po->created_at?->toDateString(),
        ]);

        return response()->json(['success' => true, 'data' => $pos]);
    }

    /**
     * A purchase that did not come from an enquiry — office items, tools,
     * anything bought directly. The same document as a generated purchase
     * order, minus the enquiry; items are added on the detail screen.
     *
     * Mirrors the direct customer invoice. A vendor is required here because a
     * purchase without one has nobody to pay and nothing to reconcile against.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'vendor_id' => ['required', 'integer', 'exists:vendors,id'],
            'currency' => ['nullable', 'string', 'size:3'],
            'expected_date' => ['nullable', 'date'],
            // Classified at the point it is raised, like every other purchase.
            // A direct purchase has no enquiry behind it, so if it is not coded
            // here it is coded nowhere.
            'account_code' => \App\Models\Account::validationRule(),
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $vendor = Vendor::findOrFail($data['vendor_id']);
        // Same base the reports use, so a direct purchase is costed like any other.
        $base = strtoupper(config('procurement.base_currency', 'USD'));

        $po = PurchaseOrder::create([
            'rfq_id' => null,
            'vendor_id' => $vendor->id,
            'currency' => strtoupper($data['currency'] ?? $vendor->currency ?? $base),
            'base_currency' => $base,
            'exchange_rate' => 1,
            'status' => 'draft',
            'expected_date' => $data['expected_date'] ?? null,
            'account_code' => ($data['account_code'] ?? null) ?: \App\Models\Account::DEFAULT_PURCHASE,
            'tax_rate' => $data['tax_rate'] ?? 0,
            'notes' => $data['notes'] ?? null,
            'created_by' => $request->user()?->id,
        ]);

        $po->po_number = DocNumber::next('PO');
        $po->save();

        return response()->json([
            'success' => true,
            'message' => 'Purchase order '.$po->po_number.' created.',
            'data' => $po->load('items'),
        ], 201);
    }

    public function show(PurchaseOrder $purchaseOrder)
    {
        // Backfill a magic-link token for any PO created before the column existed.
        if (! $purchaseOrder->token) {
            $purchaseOrder->forceFill(['token' => Str::random(48)])->save();
        }

        $purchaseOrder->load(['items', 'vendor', 'rfq:id,reference', 'creator:id,name', 'returnNote.items', 'attachments']);

        $data = $purchaseOrder->toArray();
        $data['attachments'] = $purchaseOrder->attachments
            ->map(fn ($a) => ['id' => $a->id, 'name' => $a->original_name, 'size' => $a->size])
            ->values();
        $returnsTotal = (float) ($purchaseOrder->returnNote?->subtotal ?? 0);
        $data['returns_total'] = round($returnsTotal, 4);
        $data['net_payable'] = round((float) $purchaseOrder->subtotal - $returnsTotal, 4);

        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * Turn an enquiry's awards into one purchase order per awarded vendor.
     * Idempotent: vendors that already have a PO for this enquiry are skipped.
     */
    public function generate(Request $request, Rfq $rfq)
    {
        $rfq->load(['items.awards.quoteItem.quote', 'items.awards.vendor']);

        // Flatten to one entry per (line, vendor). A line split between two
        // vendors therefore appears on both vendors' purchase orders, each with
        // only the quantity that vendor is supplying.
        $awardedItems = $rfq->items->flatMap(
            fn ($item) => $item->awards->map(fn ($award) => ['item' => $item, 'award' => $award])
        )->values();

        if ($awardedItems->isEmpty()) {
            return response()->json(['success' => false, 'message' => 'No awarded items to turn into purchase orders.'], 422);
        }

        $existingVendorIds = PurchaseOrder::where('rfq_id', $rfq->id)->pluck('vendor_id')->all();

        $created = collect();
        $skipped = 0;

        DB::transaction(function () use ($request, $rfq, $awardedItems, $existingVendorIds, $created, &$skipped) {
            foreach ($awardedItems->groupBy(fn ($row) => $row['award']->vendor_id) as $vendorId => $rows) {
                if (in_array($vendorId, $existingVendorIds)) {
                    $skipped++;

                    continue;
                }

                $first = $rows->first();
                $quote = $first['award']->quoteItem?->quote
                    ?? Quote::where('rfq_id', $rfq->id)->where('vendor_id', $vendorId)->first();
                $vendor = $first['award']->vendor;

                $po = PurchaseOrder::create([
                    'rfq_id' => $rfq->id,
                    'vendor_id' => $vendorId,
                    'ship_name' => $rfq->ship_name,
                    'delivery_port' => $rfq->delivery_port,
                    'currency' => $quote?->currency ?? $vendor?->currency ?? $rfq->base_currency,
                    'base_currency' => $rfq->base_currency,
                    'exchange_rate' => ($quote && (float) $quote->exchange_rate > 0) ? $quote->exchange_rate : 1,
                    'status' => 'draft',
                    'created_by' => $request->user()?->id,
                ]);
                $po->po_number = DocNumber::next('PO'); // MMS-PO-2026-000001
                $po->save();

                $subtotal = 0;
                foreach ($rows->values() as $sort => $row) {
                    $item = $row['item'];
                    $award = $row['award'];
                    $qty = (float) $award->qty_to_buy;
                    $cost = (float) $award->unit_cost;
                    $lineTotal = round($qty * $cost, 4);
                    $subtotal += $lineTotal;

                    $po->items()->create([
                        'rfq_item_id' => $item->id,
                        'award_id' => $award->id,
                        'description' => $item->description,
                        'impa_no' => $item->impa_no,
                        'unit' => $item->unit,
                        'qty' => $qty,
                        'unit_cost' => $cost,
                        'line_total' => $lineTotal,
                        'remarks' => $award->quoteItem?->remarks, // carry the awarded vendor's remark
                        'sort' => $sort,
                    ]);
                }
                $po->update(['subtotal' => $subtotal]);

                $created->push($po->load('vendor:id,name'));
            }
        });

        // Stamp the customer delivery address (passed from the Delivery Order) onto
        // this enquiry's draft purchase orders, so the vendor ships to the customer.
        if ($request->filled('delivery_address')) {
            PurchaseOrder::where('rfq_id', $rfq->id)->where('status', 'draft')
                ->update(['delivery_address' => $request->input('delivery_address')]);
        }

        return response()->json([
            'success' => true,
            'message' => $created->isEmpty()
                ? 'Purchase orders already exist for every awarded vendor.'
                : $created->count().' purchase order(s) generated.',
            'data' => [
                'created' => $created->values(),
                'skipped_vendor_count' => $skipped,
            ],
        ]);
    }

    public function update(Request $request, PurchaseOrder $purchaseOrder)
    {
        $data = $request->validate([
            'status' => ['sometimes', 'in:draft,issued,received,cancelled'],
            'issued_date' => ['nullable', 'date'],
            'expected_date' => ['nullable', 'date'],
            'delivery_address' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'exchange_rate' => ['sometimes', 'numeric', 'min:0'],
            'receipt_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'has_credit_note' => ['sometimes', 'boolean'],
            'credit_note_number' => ['nullable', 'string', 'max:100', 'required_if:has_credit_note,true'],
            'credit_note_amount' => ['nullable', 'numeric', 'min:0', 'required_if:has_credit_note,true'],
            // Optional during rolling deploys; an enabled credit defaults to
            // the standard sales account below when an older client omits it.
            'credit_note_account_code' => \App\Models\Account::validationRule(),
            'expenses' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'expense_items' => ['sometimes', 'nullable', 'array'],
            'expense_items.*.name' => ['nullable', 'string', 'max:255'],
            'expense_items.*.amount' => ['nullable', 'numeric', 'min:0'],
            'expense_currency' => ['sometimes', 'nullable', 'string', 'size:3'],
            'expense_rate' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'paid_at' => ['sometimes', 'nullable', 'date'],
            // Which expense account this purchase is booked to, and the GST the
            // vendor charged. Without the tax figure there is no Box 5 and no
            // Box 7 — input tax cannot be claimed on a return at all.
            'account_code' => \App\Models\Account::validationRule(),
            'tax_rate' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['sometimes', 'array'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.description' => ['required_with:items', 'string', 'max:8000'],
            'items.*.qty' => ['required_with:items', 'numeric', 'min:0'],
            'items.*.unit' => ['nullable', 'string', 'max:50'],
            'items.*.unit_cost' => ['required_with:items', 'numeric', 'min:0'],
        ]);

        $hasCreditNote = array_key_exists('has_credit_note', $data)
            ? (bool) $data['has_credit_note']
            : (bool) $purchaseOrder->has_credit_note;
        $projectedSubtotal = array_key_exists('items', $data)
            ? collect($data['items'])->sum(fn ($item) => (float) $item['qty'] * (float) $item['unit_cost'])
            : (float) $purchaseOrder->subtotal;
        $receiptAmount = array_key_exists('receipt_amount', $data)
            ? $data['receipt_amount']
            : $purchaseOrder->receipt_amount;
        $gross = (float) ($receiptAmount ?? $projectedSubtotal);
        $credit = array_key_exists('credit_note_amount', $data)
            ? (float) ($data['credit_note_amount'] ?? 0)
            : $purchaseOrder->vendorCreditAmount();
        if ($hasCreditNote && $credit <= 0) {
            throw ValidationException::withMessages([
                'credit_note_amount' => 'Enter a credit note amount greater than 0.',
            ]);
        }
        // A direct purchase can be used to record a standalone vendor credit
        // before any vendor invoice is entered. Once there is a charge, keep
        // the credit from turning the payable into a negative amount.
        if ($hasCreditNote && $gross > 0 && $credit > $gross) {
            throw ValidationException::withMessages([
                'credit_note_amount' => 'The credit note amount cannot exceed the vendor charge.',
            ]);
        }
        $creditAccountCode = ($data['credit_note_account_code'] ?? $purchaseOrder->credit_note_account_code)
            ?: \App\Models\Account::DEFAULT_SALES;
        $creditAccount = \App\Models\Account::find_by_code($creditAccountCode);
        if ($hasCreditNote && $creditAccount && $creditAccount->type !== \App\Models\Account::INCOME) {
            throw ValidationException::withMessages([
                'credit_note_account_code' => 'Choose a sales or income account for the vendor credit note.',
            ]);
        }

        DB::transaction(function () use ($purchaseOrder, $data) {
            $attrs = [];
            foreach (['status', 'notes', 'exchange_rate', 'issued_date', 'expected_date', 'delivery_address', 'receipt_amount', 'has_credit_note', 'credit_note_number', 'credit_note_amount', 'credit_note_account_code', 'expenses', 'expense_currency', 'expense_rate', 'paid_at'] as $key) {
                if (array_key_exists($key, $data)) {
                    $attrs[$key] = $data[$key];
                }
            }
            if (array_key_exists('has_credit_note', $attrs)) {
                $attrs['has_credit_note'] = (bool) $attrs['has_credit_note'];
                if (! $attrs['has_credit_note']) {
                    $attrs['credit_note_number'] = null;
                    $attrs['credit_note_amount'] = 0;
                    $attrs['credit_note_account_code'] = null;
                } else {
                    $attrs['credit_note_number'] = trim((string) ($attrs['credit_note_number'] ?? $purchaseOrder->credit_note_number));
                    $attrs['credit_note_amount'] = round((float) ($attrs['credit_note_amount'] ?? $purchaseOrder->credit_note_amount), 4);
                    $attrs['credit_note_account_code'] = ($attrs['credit_note_account_code'] ?? $purchaseOrder->credit_note_account_code)
                        ?: \App\Models\Account::DEFAULT_SALES;
                }
            }
            // Blank clears back to cost of sales rather than storing "".
            if (array_key_exists('account_code', $data)) {
                $attrs['account_code'] = $data['account_code'] ?: \App\Models\Account::DEFAULT_PURCHASE;
            }
            if (array_key_exists('tax_rate', $data)) {
                $attrs['tax_rate'] = $data['tax_rate'] ?? 0;
            }
            // expenses is non-nullable (defaults 0); a cleared field means zero.
            if (array_key_exists('expenses', $attrs) && $attrs['expenses'] === null) {
                $attrs['expenses'] = 0;
            }
            // Normalise the expenses currency + its rate to base (rate defaults to 1).
            if (array_key_exists('expense_currency', $attrs)) {
                $attrs['expense_currency'] = $attrs['expense_currency'] ? strtoupper($attrs['expense_currency']) : null;
            }
            if (array_key_exists('expense_rate', $attrs) && (! $attrs['expense_rate'] || $attrs['expense_rate'] <= 0)) {
                $attrs['expense_rate'] = 1;
            }
            // Itemised expenses [{name, amount}] are the source of truth; the
            // scalar `expenses` total is derived from them for the reports.
            if (array_key_exists('expense_items', $data)) {
                $items = collect($data['expense_items'] ?? [])
                    ->map(fn ($e) => ['name' => trim((string) ($e['name'] ?? '')), 'amount' => round((float) ($e['amount'] ?? 0), 4)])
                    ->filter(fn ($e) => $e['name'] !== '' || $e['amount'] > 0)
                    ->values();
                $attrs['expense_items'] = $items->all();
                $attrs['expenses'] = round($items->sum('amount'), 4);
            }
            // Stamp the issued date the first time a PO is marked issued.
            if (($data['status'] ?? null) === 'issued' && empty($attrs['issued_date']) && ! $purchaseOrder->issued_date) {
                $attrs['issued_date'] = now()->toDateString();
            }
            if ($attrs) {
                $purchaseOrder->update($attrs);
            }

            if (array_key_exists('items', $data)) {
                $keep = [];
                foreach (array_values($data['items']) as $i => $row) {
                    $payload = [
                        'description' => $row['description'],
                        'unit' => $row['unit'] ?? null,
                        'qty' => $row['qty'],
                        'unit_cost' => $row['unit_cost'],
                        'line_total' => round((float) $row['qty'] * (float) $row['unit_cost'], 4),
                        'sort' => $i,
                    ];
                    if (! empty($row['id']) && ($item = $purchaseOrder->items()->whereKey($row['id'])->first())) {
                        $item->update($payload);
                        $keep[] = $item->id;
                    } else {
                        $keep[] = $purchaseOrder->items()->create($payload)->id;
                    }
                }
                $purchaseOrder->items()->whereNotIn('id', $keep)->delete();
            }

            $purchaseOrder->recalcSubtotal();
            $this->recalcTax($purchaseOrder);
        });

        return response()->json([
            'success' => true,
            'message' => 'Purchase order updated.',
            'data' => $purchaseOrder->fresh()->load(['items', 'vendor', 'rfq:id,reference']),
        ]);
    }

    /**
     * Recompute the input tax on a purchase order.
     *
     * Charged on what the vendor actually billed — the receipt amount where one
     * has been recorded, the awarded cost otherwise — which is the same figure
     * the purchase register and the GST return read. Derived rather than typed,
     * so the tax and the value it was charged on can never disagree.
     */
    private function recalcTax(PurchaseOrder $purchaseOrder): void
    {
        $net = $purchaseOrder->vendorNetAmount();
        $rate = (float) $purchaseOrder->tax_rate;

        $purchaseOrder->forceFill([
            'tax_amount' => round($net * $rate / 100, 4),
        ])->save();
    }

    public function destroy(PurchaseOrder $purchaseOrder)
    {
        if ($purchaseOrder->status !== 'draft') {
            return response()->json([
                'success' => false,
                'message' => 'Only draft purchase orders can be deleted. Cancel it instead.',
            ], 422);
        }

        $purchaseOrder->delete();

        return response()->json(['success' => true, 'message' => 'Purchase order deleted.']);
    }

    public function pdf(PurchaseOrder $purchaseOrder)
    {
        $purchaseOrder->load(['items', 'vendor', 'creator:id,name,phone,email']);

        $logoPath = public_path('logo.png');
        $logo = is_file($logoPath) ? 'data:image/png;base64,'.base64_encode(file_get_contents($logoPath)) : null;

        return Pdf::loadView('pdf.purchase-order', [
            'po' => $purchaseOrder,
            'company' => config('procurement.company'),
            'logo' => $logo,
        ])->download(($purchaseOrder->po_number ?: 'PO').'.pdf');
    }

    /** Final invoice PDF — order line items less any returns = the net amount payable to the vendor. */
    public function finalInvoice(PurchaseOrder $purchaseOrder)
    {
        $purchaseOrder->load(['items', 'vendor', 'returnNote.items']);

        // NOTE: this is the VENDOR settlement document (order less returns), not a
        // customer invoice — it must NOT consume MMS-INV numbers. It is referenced
        // by its PO number instead (legacy rows that already got one keep it).

        $returnsTotal = (float) ($purchaseOrder->returnNote?->subtotal ?? 0);
        $netPayable = round((float) $purchaseOrder->subtotal - $returnsTotal, 4);

        $logoPath = public_path('logo.png');
        $logo = is_file($logoPath) ? 'data:image/png;base64,'.base64_encode(file_get_contents($logoPath)) : null;

        return Pdf::loadView('pdf.final-invoice', [
            'po' => $purchaseOrder,
            'company' => config('procurement.company'),
            'logo' => $logo,
            'returnsTotal' => $returnsTotal,
            'netPayable' => $netPayable,
        ])->download('final-invoice-'.($purchaseOrder->po_number ?: 'PO').'.pdf');
    }

    public function email(Request $request, PurchaseOrder $purchaseOrder)
    {
        $purchaseOrder->load(['items', 'vendor']);

        $emails = \App\Support\Recipients::emails($purchaseOrder->vendor?->email);
        if (! $emails) {
            return response()->json(['success' => false, 'message' => 'This vendor has no valid email on file.'], 422);
        }
        $to = implode(', ', $emails);

        if (! $purchaseOrder->token) {
            $purchaseOrder->forceFill(['token' => Str::random(48)])->save();
        }
        $link = rtrim(config('procurement.frontend_url'), '/').'/po/'.$purchaseOrder->token;

        $staff = $request->user();
        try {
            Mail::to($emails)->bcc(config('mail.from.address'))->send(new PurchaseOrderMail($purchaseOrder, $staff, $link));
        } catch (\Throwable $e) {
            SentLog::record([
                'type' => 'Purchase Order', 'reference' => $purchaseOrder->po_number,
                'recipient_name' => $purchaseOrder->vendor->name, 'recipient_email' => $to,
                'subject' => 'Purchase Order '.$purchaseOrder->po_number,
                'status' => 'failed', 'error' => $e->getMessage(), 'sent_by' => $staff?->id, 'sent_by_name' => $staff?->name,
            ]);

            return response()->json(['success' => false, 'message' => 'Email failed: '.$e->getMessage()], 500);
        }

        SentLog::record([
            'type' => 'Purchase Order', 'reference' => $purchaseOrder->po_number,
            'recipient_name' => $purchaseOrder->vendor->name, 'recipient_email' => $to,
            'subject' => 'Purchase Order '.$purchaseOrder->po_number,
            'status' => 'sent', 'sent_by' => $staff?->id, 'sent_by_name' => $staff?->name,
        ]);

        // Emailing a draft PO issues it.
        if ($purchaseOrder->status === 'draft') {
            $purchaseOrder->update([
                'status' => 'issued',
                'issued_date' => $purchaseOrder->issued_date ?? now()->toDateString(),
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Purchase order emailed to '.$purchaseOrder->vendor->email.'.',
        ]);
    }

    /** Attach the vendor's invoice (and any supporting files) to this PO. Stored privately on R2. */
    public function uploadAttachment(Request $request, PurchaseOrder $purchaseOrder)
    {
        $request->validate([
            'files' => ['required', 'array', 'max:10'],
            'files.*' => ['file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,xls,xlsx,doc,docx,csv,txt'],
        ]);

        foreach ($request->file('files') as $file) {
            $path = $file->store('purchase-orders/'.$purchaseOrder->id, 'r2');
            $purchaseOrder->attachments()->create([
                'disk' => 'r2',
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getClientMimeType(),
                'size' => $file->getSize(),
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'File(s) uploaded.',
            'data' => $this->attachmentList($purchaseOrder),
        ]);
    }

    public function deleteAttachment(PurchaseOrder $purchaseOrder, PurchaseOrderAttachment $attachment)
    {
        abort_unless($attachment->purchase_order_id === $purchaseOrder->id, 404);

        Storage::disk($attachment->disk)->delete($attachment->path);
        $attachment->delete();

        return response()->json(['success' => true, 'message' => 'File removed.', 'data' => $this->attachmentList($purchaseOrder)]);
    }

    /** Short-lived signed URL so staff can open a private attachment. */
    public function attachmentUrl(PurchaseOrder $purchaseOrder, PurchaseOrderAttachment $attachment)
    {
        abort_unless($attachment->purchase_order_id === $purchaseOrder->id, 404);

        $url = Storage::disk($attachment->disk)->temporaryUrl($attachment->path, now()->addMinutes(10));

        return response()->json(['success' => true, 'data' => ['url' => $url, 'name' => $attachment->original_name]]);
    }

    private function attachmentList(PurchaseOrder $purchaseOrder): array
    {
        return $purchaseOrder->attachments()->get()
            ->map(fn ($a) => ['id' => $a->id, 'name' => $a->original_name, 'size' => $a->size])
            ->values()
            ->all();
    }
}

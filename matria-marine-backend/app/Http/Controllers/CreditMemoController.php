<?php

namespace App\Http\Controllers;

use App\Models\CreditMemo;
use App\Models\CustomerInvoice;
use App\Support\DocNumber;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Credit memos — credit part of an ISSUED invoice back to the customer
 * (damaged / not delivered / price error). Mirrors the Return Note flow:
 * one credit memo per invoice, raised from its own screen, PDF download only
 * (no emailing).
 */
class CreditMemoController extends Controller
{
    /**
     * Every credit memo raised, newest first.
     *
     * `q` searches the memo number, the invoice it credits and the customer —
     * the three things anyone actually remembers about one.
     */
    public function index(Request $request)
    {
        $rows = CreditMemo::with(['invoice:id,invoice_number,issue_date', 'creator:id,name'])
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('q'), function ($q, $term) {
                $like = '%'.$term.'%';
                $q->where(fn ($w) => $w->where('cm_number', 'like', $like)
                    ->orWhere('customer_name', 'like', $like)
                    ->orWhereHas('invoice', fn ($i) => $i->where('invoice_number', 'like', $like)));
            })
            ->orderByDesc('memo_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (CreditMemo $cm) => [
                'id' => $cm->id,
                'cm_number' => $cm->cm_number,
                'memo_date' => optional($cm->memo_date)->toDateString(),
                'status' => $cm->status,
                'currency' => $cm->currency,
                'subtotal' => (float) $cm->subtotal,
                'tax_amount' => (float) $cm->tax_amount,
                'grand_total' => (float) $cm->grand_total,
                'reason' => $cm->reason,
                'customer_name' => $cm->customer_name,
                'invoice_id' => $cm->customer_invoice_id,
                'invoice_number' => $cm->invoice?->invoice_number,
                'created_by' => $cm->creator?->name,
            ]);

        return response()->json(['success' => true, 'data' => $rows]);
    }

    /**
     * Invoices a credit memo can still be raised against.
     *
     * Drafts are excluded — nothing has been billed yet, so there is nothing to
     * credit back. Invoices that already carry a memo are listed too, flagged,
     * because the flow is one memo per invoice and picking one should reopen it
     * rather than silently fail.
     */
    public function creditableInvoices(Request $request)
    {
        $rows = CustomerInvoice::query()
            ->where('status', '!=', 'draft')
            ->with(['creditMemos:id,customer_invoice_id,cm_number,status'])
            ->when($request->query('q'), function ($q, $term) {
                $like = '%'.$term.'%';
                $q->where(fn ($w) => $w->where('invoice_number', 'like', $like)
                    ->orWhere('customer_name', 'like', $like));
            })
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->map(fn (CustomerInvoice $i) => [
                'id' => $i->id,
                'invoice_number' => $i->invoice_number,
                'issue_date' => optional($i->issue_date)->toDateString(),
                'customer_name' => $i->customer_name,
                'currency' => $i->currency,
                'grand_total' => (float) $i->grand_total,
                'existing_memo' => $i->creditMemos->first()?->only(['id', 'cm_number', 'status']),
            ]);

        return response()->json(['success' => true, 'data' => $rows]);
    }

    /** One invoice with the lines a memo would be built from. */
    public function invoiceLines(CustomerInvoice $invoice)
    {
        $invoice->load(['items', 'creditMemos.items']);
        $memo = $invoice->creditMemos->first();
        $credited = collect($memo?->items ?? [])->keyBy('customer_invoice_item_id');

        return response()->json(['success' => true, 'data' => [
            'id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'issue_date' => optional($invoice->issue_date)->toDateString(),
            'customer_name' => $invoice->customer_name,
            'currency' => $invoice->currency,
            'tax_rate' => (float) $invoice->tax_rate,
            'grand_total' => (float) $invoice->grand_total,
            'memo' => $memo ? [
                'id' => $memo->id,
                'cm_number' => $memo->cm_number,
                'status' => $memo->status,
                'memo_date' => optional($memo->memo_date)->toDateString(),
                'reason' => $memo->reason,
            ] : null,
            'lines' => $invoice->items->where('is_heading', false)->values()->map(function ($it) use ($credited) {
                $already = $credited->get($it->id);

                return [
                    'customer_invoice_item_id' => $it->id,
                    'description' => $it->description,
                    'unit' => $it->unit,
                    'invoiced_qty' => (float) $it->qty,
                    'invoiced_price' => (float) $it->unit_price,
                    // Shown, not chosen: the credit goes back where the sale
                    // came from, and saying so beats making someone pick again.
                    'account_code' => $it->account_code,
                    // What is already on the memo, so reopening it shows the
                    // figures as they were saved rather than starting at zero.
                    'qty' => $already ? (float) $already->qty : 0,
                    'unit_price' => $already ? (float) $already->unit_price : (float) $it->unit_price,
                    'reason' => $already?->reason,
                ];
            }),
        ]]);
    }

    /**
     * Create or update the credit memo for an invoice from the submitted lines.
     * Only lines with a credited qty > 0 are kept; an emptied memo is deleted.
     */
    public function storeForInvoice(Request $request, CustomerInvoice $invoice)
    {
        $data = $request->validate([
            'memo_date' => ['nullable', 'date'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array'],
            'lines.*.customer_invoice_item_id' => ['required', 'integer'],
            'lines.*.qty' => ['required', 'numeric', 'min:0'],
            // Negative is allowed, because the invoice line can be: a discount
            // is billed as a negative amount, and crediting an invoice back
            // has to be able to credit that line too.
            'lines.*.unit_price' => ['nullable', 'numeric'],
            // Normally inherited from the invoice line, but settable so a
            // credit can be booked somewhere else when it has to be.
            'lines.*.account_code' => \App\Models\Account::validationRule(),
            'lines.*.reason' => ['nullable', 'string', 'max:500'],
        ]);

        $invoice->load('items');
        $itemsById = $invoice->items->where('is_heading', false)->keyBy('id');

        $cm = DB::transaction(function () use ($invoice, $data, $itemsById, $request) {
            $cm = CreditMemo::firstOrNew(['customer_invoice_id' => $invoice->id]);
            if (! $cm->exists) {
                $cm->cm_number = DocNumber::next('CM');
                $cm->created_by = $request->user()?->id;
                $cm->status = 'draft';
            }
            $cm->fill([
                'rfq_id' => $invoice->rfq_id,
                'customer_id' => $invoice->customer_id,
                'customer_name' => $invoice->customer_name,
                'customer_address' => $invoice->customer_address,
                'currency' => $invoice->currency,
                'tax_rate' => (float) $invoice->tax_rate,
                'memo_date' => $data['memo_date'] ?? $cm->memo_date ?? now()->toDateString(),
                'reason' => array_key_exists('reason', $data) ? $data['reason'] : $cm->reason,
            ]);
            $cm->save();

            $keep = [];
            $sort = 0;
            foreach ($data['lines'] as $line) {
                $invItem = $itemsById->get($line['customer_invoice_item_id']);
                if (! $invItem) {
                    continue;
                }
                // Cap: can never credit more qty than was invoiced on that line,
                // nor a higher unit price than was charged.
                $qty = min((float) $line['qty'], (float) $invItem->qty);
                if ($qty <= 0) {
                    continue;
                }
                // Never credit back more than was charged. On an ordinary line
                // that means capping at the invoiced price; on a discount line,
                // which was billed as a negative, "more" runs the other way —
                // so the cap is a floor instead.
                $invoiced = (float) $invItem->unit_price;
                $asked = (float) ($line['unit_price'] ?? $invoiced);
                $price = $invoiced >= 0 ? min($asked, $invoiced) : max($asked, $invoiced);
                $cmItem = $cm->items()->updateOrCreate(
                    ['customer_invoice_item_id' => $invItem->id],
                    [
                        'description' => $invItem->description,
                        'unit' => $invItem->unit,
                        'qty' => $qty,
                        'unit_price' => $price,
                        // Undo the sale where it was made, unless the user said
                        // otherwise. Falls back to the memo's own code for
                        // invoices raised before lines carried one.
                        'account_code' => ($line['account_code'] ?? null)
                            ?: ($invItem->account_code ?: $cm->account_code),
                        'line_total' => round($qty * $price, 2),
                        'reason' => $line['reason'] ?? null,
                        'sort' => $sort++,
                    ]
                );
                $keep[] = $cmItem->id;
            }
            $cm->items()->whereNotIn('id', $keep)->delete();
            $cm->recalcTotals();

            return $cm;
        });

        // An empty memo (every line set back to 0) is removed entirely.
        if ($cm->items()->count() === 0) {
            $cm->delete();

            return response()->json(['success' => true, 'message' => 'No lines credited — credit memo cleared.', 'data' => null]);
        }

        return response()->json(['success' => true, 'message' => 'Credit memo saved.', 'data' => $cm->fresh()->load('items')]);
    }

    public function show(CreditMemo $creditMemo)
    {
        $creditMemo->load(['items', 'invoice:id,invoice_number,issue_date', 'creator:id,name']);

        return response()->json(['success' => true, 'data' => $creditMemo]);
    }

    public function update(Request $request, CreditMemo $creditMemo)
    {
        $data = $request->validate([
            'status' => ['sometimes', 'in:draft,issued'],
            'memo_date' => ['nullable', 'date'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $attrs = [];
        foreach (['status', 'memo_date', 'reason'] as $key) {
            if (array_key_exists($key, $data)) {
                $attrs[$key] = $data[$key];
            }
        }
        if (($data['status'] ?? null) === 'issued' && ! $creditMemo->issued_at) {
            $attrs['issued_at'] = now();
        }
        if ($attrs) {
            $creditMemo->update($attrs);
        }

        return response()->json([
            'success' => true,
            'message' => 'Credit memo updated.',
            'data' => $creditMemo->fresh()->load('items'),
        ]);
    }

    public function destroy(CreditMemo $creditMemo)
    {
        if ($creditMemo->status !== 'draft') {
            return response()->json([
                'success' => false,
                'message' => 'Only draft credit memos can be deleted.',
            ], 422);
        }

        $creditMemo->delete();

        return response()->json(['success' => true, 'message' => 'Credit memo deleted.']);
    }

    public function pdf(CreditMemo $creditMemo)
    {
        $creditMemo->load(['items', 'invoice:id,invoice_number,issue_date', 'creator:id,name,phone,email']);

        $logoPath = public_path('logo.png');
        $logo = is_file($logoPath) ? 'data:image/png;base64,'.base64_encode(file_get_contents($logoPath)) : null;

        $pdf = Pdf::loadView('pdf.credit-memo', [
            'cm' => $creditMemo,
            'company' => config('procurement.company'),
            'logo' => $logo,
        ]);

        return $pdf->download(($creditMemo->cm_number ?: 'credit-memo').'.pdf');
    }
}

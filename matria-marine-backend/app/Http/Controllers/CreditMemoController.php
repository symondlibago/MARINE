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
 * one credit memo per invoice, edited from the invoice page, PDF download
 * only (no emailing).
 */
class CreditMemoController extends Controller
{
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
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0'],
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
                $price = min(
                    (float) ($line['unit_price'] ?? $invItem->unit_price),
                    (float) $invItem->unit_price
                );
                $cmItem = $cm->items()->updateOrCreate(
                    ['customer_invoice_item_id' => $invItem->id],
                    [
                        'description' => $invItem->description,
                        'unit' => $invItem->unit,
                        'qty' => $qty,
                        'unit_price' => $price,
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

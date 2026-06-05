<?php

namespace App\Http\Controllers;

use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\RfqVendor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Public, token-protected vendor quote endpoints (magic link — NO auth).
 * The {token} resolves to one vendor's view of one enquiry via rfq_vendors.
 */
class QuoteController extends Controller
{
    public function show(string $token)
    {
        $rv = RfqVendor::with(['rfq.items', 'vendor'])->where('token', $token)->first();

        if (! $rv) {
            return response()->json(['success' => false, 'message' => 'This quotation link is invalid or has expired.'], 404);
        }

        // Record first open.
        if (! $rv->opened_at) {
            $rv->opened_at = now();
            if (in_array($rv->status, ['requested', 'sent'], true)) {
                $rv->status = 'opened';
            }
            $rv->save();
        }

        $rfq = $rv->rfq;
        $vendor = $rv->vendor;

        $existing = Quote::with('items')
            ->where('rfq_id', $rfq->id)
            ->where('vendor_id', $vendor->id)
            ->first();

        return response()->json([
            'success' => true,
            'data' => [
                'vendor' => ['name' => $vendor->name, 'currency' => $vendor->currency],
                'rfq' => [
                    'reference' => $rfq->reference,
                    'ship_name' => $rfq->ship_name,
                    'delivery_port' => $rfq->delivery_port,
                ],
                'items' => $rfq->items->map(fn ($i) => [
                    'rfq_item_id' => $i->id,
                    'description' => $i->description,
                    'qty' => (float) $i->qty,
                    'unit' => $i->unit,
                ])->values(),
                'submitted' => (bool) $existing,
                'quote' => $existing ? [
                    'currency' => $existing->currency,
                    'items' => $existing->items->map(fn ($qi) => [
                        'rfq_item_id' => $qi->rfq_item_id,
                        'unit_cost' => (float) $qi->unit_cost,
                        'remarks' => $qi->remarks,
                    ])->values(),
                ] : null,
            ],
        ]);
    }

    public function submit(Request $request, string $token)
    {
        $rv = RfqVendor::with('rfq.items')->where('token', $token)->first();

        if (! $rv) {
            return response()->json(['success' => false, 'message' => 'This quotation link is invalid or has expired.'], 404);
        }

        $data = $request->validate([
            'currency' => ['required', 'string', 'size:3'],
            'valid_until' => ['nullable', 'date'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.rfq_item_id' => ['required', 'integer'],
            'lines.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'lines.*.remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $rfq = $rv->rfq;
        $validItemIds = $rfq->items->pluck('id');

        DB::transaction(function () use ($rv, $rfq, $data, $validItemIds) {
            $quote = Quote::updateOrCreate(
                ['rfq_id' => $rfq->id, 'vendor_id' => $rv->vendor_id],
                [
                    'currency' => strtoupper($data['currency']),
                    'valid_until' => $data['valid_until'] ?? null,
                    'status' => 'submitted',
                    'submitted_at' => now(),
                ]
            );

            foreach ($data['lines'] as $line) {
                if (! $validItemIds->contains($line['rfq_item_id'])) {
                    continue;
                }
                $cost = $line['unit_cost'] ?? null;
                if ($cost === null || $cost === '') {
                    continue; // line left un-priced by the vendor
                }
                QuoteItem::updateOrCreate(
                    ['quote_id' => $quote->id, 'rfq_item_id' => $line['rfq_item_id']],
                    ['unit_cost' => $cost, 'remarks' => $line['remarks'] ?? null]
                );
            }

            $rv->status = 'submitted';
            $rv->responded_at = now();
            $rv->save();

            if ($rfq->status === 'sent') {
                $rfq->update(['status' => 'quoting']);
            }
        });

        return response()->json(['success' => true, 'message' => 'Thank you — your quotation has been submitted.']);
    }
}

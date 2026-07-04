<?php

namespace App\Http\Controllers;

use App\Models\Quote;
use App\Models\QuoteAttachment;
use App\Models\QuoteItem;
use App\Models\RfqVendor;
use App\Support\DocNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

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

        // Only show the items this vendor was asked for. No pivot rows = all items.
        $askedIds = $rv->items()->pluck('rfq_items.id');
        $items = $askedIds->isEmpty() ? $rfq->items : $rfq->items->whereIn('id', $askedIds->all());

        $existing = Quote::with(['items', 'attachments'])
            ->where('rfq_id', $rfq->id)
            ->where('vendor_id', $vendor->id)
            ->first();

        return response()->json([
            'success' => true,
            'data' => [
                'vendor' => ['name' => $vendor->name, 'currency' => $vendor->currency],
                'attachments' => $existing
                    ? $existing->attachments->map(fn ($a) => ['id' => $a->id, 'name' => $a->original_name, 'size' => $a->size])->values()
                    : [],
                'rfq' => [
                    'reference' => $rfq->reference,
                    // What this vendor sees as their RFQ number: MMS-QTN-2026-00001-01.
                    'vendor_reference' => DocNumber::vendorSuffix($rfq->reference, $rv->seq ?: 1),
                    'ship_name' => $rfq->ship_name,
                    'delivery_port' => $rfq->delivery_port,
                    // Requirements ARE shown to vendors so they quote correctly.
                    // The customer is intentionally NOT exposed here.
                    'requirements' => $rfq->requirements ?? [],
                ],
                'items' => $items->map(fn ($i) => [
                    'rfq_item_id' => $i->id,
                    'description' => $i->description,
                    'qty' => (float) $i->qty,
                    'unit' => $i->unit,
                ])->values(),
                'submitted' => (bool) $existing,
                'quote' => $existing ? [
                    'quotation_number' => $existing->quotation_number,
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
            // Prices are optional — vendors may submit blank and staff key the
            // prices in on the Compare & Award grid (some vendors email directly).
            'quotation_number' => ['nullable', 'string', 'max:100'],
            'currency' => ['required', 'string', 'size:3'],
            'valid_until' => ['nullable', 'date'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.rfq_item_id' => ['required', 'integer'],
            'lines.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'lines.*.remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $rfq = $rv->rfq;
        // Restrict to the items this vendor was asked for (all, if none scoped).
        $askedIds = $rv->items()->pluck('rfq_items.id');
        $validItemIds = $askedIds->isEmpty() ? $rfq->items->pluck('id') : $askedIds;

        DB::transaction(function () use ($rv, $rfq, $data, $validItemIds) {
            $quote = Quote::updateOrCreate(
                ['rfq_id' => $rfq->id, 'vendor_id' => $rv->vendor_id],
                [
                    'quotation_number' => $data['quotation_number'] ?? null,
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
                    continue; // price left blank — staff will key it in on Compare & Award
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

    /**
     * Vendor uploads supporting files (their own quote, datasheets, certificates).
     * Creates the quote if needed so files can be attached before/without pricing.
     */
    public function uploadAttachment(Request $request, string $token)
    {
        $rv = RfqVendor::with('vendor')->where('token', $token)->first();

        if (! $rv) {
            return response()->json(['success' => false, 'message' => 'This quotation link is invalid or has expired.'], 404);
        }

        $request->validate([
            'files' => ['required', 'array', 'max:10'],
            'files.*' => ['file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,xls,xlsx,doc,docx,csv,txt'],
        ]);

        $quote = Quote::firstOrCreate(
            ['rfq_id' => $rv->rfq_id, 'vendor_id' => $rv->vendor_id],
            ['currency' => $rv->vendor->currency ?? 'USD', 'status' => 'draft']
        );

        foreach ($request->file('files') as $file) {
            $path = $file->store('quotes/'.$quote->id, 'r2');
            $quote->attachments()->create([
                'disk' => 'r2',
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getClientMimeType(),
                'size' => $file->getSize(),
            ]);
        }

        // Mark the vendor as having engaged with the request.
        if (in_array($rv->status, ['requested', 'sent', 'opened'], true)) {
            $rv->status = 'opened';
            $rv->opened_at = $rv->opened_at ?? now();
            $rv->save();
        }

        return response()->json([
            'success' => true,
            'message' => 'File(s) uploaded.',
            'data' => $this->attachmentList($quote),
        ]);
    }

    public function deleteAttachment(string $token, QuoteAttachment $attachment)
    {
        $rv = RfqVendor::where('token', $token)->first();

        if (! $rv) {
            return response()->json(['success' => false, 'message' => 'This quotation link is invalid or has expired.'], 404);
        }

        $quote = Quote::where('rfq_id', $rv->rfq_id)->where('vendor_id', $rv->vendor_id)->first();
        abort_unless($quote && $attachment->quote_id === $quote->id, 404);

        Storage::disk($attachment->disk)->delete($attachment->path);
        $attachment->delete();

        return response()->json(['success' => true, 'message' => 'File removed.', 'data' => $this->attachmentList($quote)]);
    }

    private function attachmentList(Quote $quote): array
    {
        return $quote->attachments()->get()
            ->map(fn ($a) => ['id' => $a->id, 'name' => $a->original_name, 'size' => $a->size])
            ->values()
            ->all();
    }
}

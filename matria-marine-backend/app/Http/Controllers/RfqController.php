<?php

namespace App\Http\Controllers;

use App\Mail\VendorQuoteRequest;
use App\Models\Award;
use App\Models\SentLog;
use App\Models\Quote;
use App\Models\QuoteAttachment;
use App\Models\QuoteItem;
use App\Models\Rfq;
use App\Models\RfqItem;
use App\Models\RfqVendor;
use App\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class RfqController extends Controller
{
    public function index()
    {
        $rfqs = Rfq::query()
            ->with('customer:id,name')
            ->withCount(['items', 'rfqVendors', 'quotes'])
            ->orderByDesc('id')
            ->get();

        return response()->json(['success' => true, 'data' => $rfqs]);
    }

    public function store(Request $request)
    {
        $data = $this->validateRfq($request);

        $rfq = DB::transaction(function () use ($data, $request) {
            $rfq = Rfq::create([
                'customer_id' => $data['customer_id'] ?? null,
                'customer_reference' => $data['customer_reference'] ?? null,
                'priority' => $data['priority'] ?? 'normal',
                'requirements' => $data['requirements'] ?? null,
                'ship_name' => $data['ship_name'] ?? null,
                'requested_by' => $data['requested_by'] ?? null,
                'delivery_port' => $data['delivery_port'] ?? null,
                'received_date' => $data['received_date'] ?? null,
                'base_currency' => strtoupper($data['base_currency']),
                'status' => 'draft',
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()->id,
            ]);
            $rfq->reference = 'RFQ-'.str_pad((string) $rfq->id, 4, '0', STR_PAD_LEFT);
            $rfq->save();

            $this->syncItems($rfq, $data['items'] ?? []);

            return $rfq;
        });

        return response()->json([
            'success' => true,
            'message' => 'Enquiry created.',
            'data' => $rfq->load('items'),
        ], 201);
    }

    public function show(Rfq $rfq)
    {
        $rfq->load(['customer:id,name,address,email', 'items', 'creator:id,name', 'rfqVendors.vendor', 'quotes.vendor']);

        return response()->json(['success' => true, 'data' => $rfq]);
    }

    public function update(Request $request, Rfq $rfq)
    {
        $data = $this->validateRfq($request);

        DB::transaction(function () use ($rfq, $data) {
            $rfq->update([
                'customer_id' => $data['customer_id'] ?? null,
                'customer_reference' => $data['customer_reference'] ?? null,
                'priority' => $data['priority'] ?? 'normal',
                'requirements' => $data['requirements'] ?? null,
                'ship_name' => $data['ship_name'] ?? null,
                'requested_by' => $data['requested_by'] ?? null,
                'delivery_port' => $data['delivery_port'] ?? null,
                'received_date' => $data['received_date'] ?? null,
                'base_currency' => strtoupper($data['base_currency']),
                'notes' => $data['notes'] ?? null,
            ]);

            // Upsert line items: edit existing (matched by id), add new ones, and
            // remove omitted lines — but NEVER one a vendor has already quoted on.
            if (array_key_exists('items', $data)) {
                $keepIds = [];
                foreach (array_values($data['items']) as $i => $item) {
                    if (! empty($item['id']) && ($existing = $rfq->items()->whereKey($item['id'])->first())) {
                        $existing->update([
                            'description' => $item['description'],
                            'qty' => $item['qty'],
                            'unit' => $item['unit'] ?? null,
                            'sort' => $i,
                        ]);
                        $keepIds[] = $existing->id;
                    } else {
                        $new = $rfq->items()->create([
                            'description' => $item['description'],
                            'qty' => $item['qty'],
                            'unit' => $item['unit'] ?? null,
                            'sort' => $i,
                        ]);
                        $keepIds[] = $new->id;
                    }
                }

                $rfq->items()->whereNotIn('id', $keepIds)->get()->each(function ($item) {
                    if ($item->quoteItems()->count() === 0) {
                        $item->delete();
                    }
                });
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Enquiry updated.',
            'data' => $rfq->load('items'),
        ]);
    }

    /** Reopen a locked enquiry so awards / quantities can be adjusted again. */
    public function reopen(Rfq $rfq)
    {
        if ($rfq->status === 'closed') {
            $rfq->update(['status' => 'awarded']);
        }

        return response()->json(['success' => true, 'message' => 'Enquiry reopened for editing.']);
    }

    public function destroy(Rfq $rfq)
    {
        $rfq->delete();

        return response()->json(['success' => true, 'message' => 'Enquiry deleted.']);
    }

    /**
     * Select vendors and "send to all" — create a unique magic link per vendor
     * and email it. Sent synchronously (no queue worker).
     */
    public function send(Request $request, Rfq $rfq)
    {
        $data = $request->validate([
            'vendor_ids' => ['required', 'array', 'min:1'],
            'vendor_ids.*' => ['integer', 'exists:vendors,id'],
            'subject' => ['nullable', 'string', 'max:255'],
            'message' => ['nullable', 'string', 'max:5000'],
        ]);

        $staff = $request->user();
        $results = [];

        foreach ($data['vendor_ids'] as $vendorId) {
            $vendor = Vendor::find($vendorId);

            $rv = RfqVendor::firstOrNew(['rfq_id' => $rfq->id, 'vendor_id' => $vendorId]);
            if (! $rv->token) {
                $rv->token = Str::random(48);
            }
            $rv->status = 'requested';
            $rv->sent_at = now();
            $rv->save();

            if (! $vendor->email) {
                $results[] = ['vendor_id' => $vendorId, 'vendor' => $vendor->name, 'sent' => false, 'error' => 'No email on file'];

                continue;
            }

            $link = rtrim(config('procurement.frontend_url'), '/').'/quote/'.$rv->token;

            try {
                Mail::to($vendor->email)->send(
                    new VendorQuoteRequest($rfq, $vendor, $link, $data['message'] ?? null, $data['subject'] ?? null, $staff)
                );
                $results[] = ['vendor_id' => $vendorId, 'vendor' => $vendor->name, 'email' => $vendor->email, 'sent' => true];
                SentLog::record([
                    'type' => 'RFQ', 'reference' => $rfq->reference,
                    'recipient_name' => $vendor->name, 'recipient_email' => $vendor->email,
                    'subject' => $data['subject'] ?? ('Request for Quotation — '.$rfq->reference),
                    'status' => 'sent', 'sent_by' => $staff?->id, 'sent_by_name' => $staff?->name,
                ]);
            } catch (\Throwable $e) {
                $results[] = ['vendor_id' => $vendorId, 'vendor' => $vendor->name, 'email' => $vendor->email, 'sent' => false, 'error' => $e->getMessage()];
                SentLog::record([
                    'type' => 'RFQ', 'reference' => $rfq->reference,
                    'recipient_name' => $vendor->name, 'recipient_email' => $vendor->email,
                    'subject' => $data['subject'] ?? ('Request for Quotation — '.$rfq->reference),
                    'status' => 'failed', 'error' => $e->getMessage(), 'sent_by' => $staff?->id, 'sent_by_name' => $staff?->name,
                ]);
            }
        }

        if ($rfq->status === 'draft') {
            $rfq->update(['status' => 'sent']);
        }

        return response()->json([
            'success' => true,
            'message' => 'Send processed.',
            'data' => ['results' => $results],
        ]);
    }

    /**
     * Comparison grid: rows = line items, columns = vendors who quoted,
     * each cell = unit cost converted to the enquiry base currency.
     */
    public function compare(Rfq $rfq)
    {
        $rfq->load(['items.award', 'quotes.vendor', 'quotes.items', 'quotes.attachments']);

        $quotes = $rfq->quotes;
        $itemCount = $rfq->items->count();

        // Per vendor: their total quote value (base currency) and whether they
        // priced every line (complete) or left some un-quoted (incomplete).
        $vendors = $quotes->map(function (Quote $q) use ($rfq, $itemCount) {
            $quoted = 0;
            $total = 0.0;
            foreach ($rfq->items as $item) {
                $qi = $q->items->firstWhere('rfq_item_id', $item->id);
                if ($qi && $qi->unit_cost !== null) {
                    $quoted++;
                    $total += (float) $qi->unit_cost * (float) $q->exchange_rate * (float) $item->qty;
                }
            }

            return [
                'vendor_id' => $q->vendor_id,
                'vendor_name' => $q->vendor->name,
                'quote_id' => $q->id,
                'currency' => $q->currency,
                'exchange_rate' => (float) $q->exchange_rate,
                'total_base' => round($total, 2),
                'quoted_count' => $quoted,
                'item_count' => $itemCount,
                'complete' => $itemCount > 0 && $quoted === $itemCount,
                'attachments' => $q->attachments->map(fn ($a) => [
                    'id' => $a->id,
                    'name' => $a->original_name,
                    'size' => $a->size,
                ])->values(),
            ];
        })->values();

        $rows = $rfq->items->map(function ($item) use ($quotes) {
            $cells = $quotes->map(function (Quote $quote) use ($item) {
                $qi = $quote->items->firstWhere('rfq_item_id', $item->id);
                if (! $qi) {
                    return ['vendor_id' => $quote->vendor_id, 'quoted' => false];
                }

                $baseCost = (float) $qi->unit_cost * (float) $quote->exchange_rate;

                return [
                    'vendor_id' => $quote->vendor_id,
                    'quote_id' => $quote->id,
                    'quote_item_id' => $qi->id,
                    'quoted' => true,
                    'unit_cost' => (float) $qi->unit_cost,
                    'currency' => $quote->currency,
                    'base_cost' => round($baseCost, 4),
                    'remarks' => $qi->remarks,
                ];
            })->values();

            $lowest = $cells->where('quoted', true)->min('base_cost');

            return [
                'rfq_item_id' => $item->id,
                'description' => $item->description,
                'qty' => (float) $item->qty,
                'unit' => $item->unit,
                'lowest_base_cost' => $lowest,
                'award' => $item->award ? [
                    'vendor_id' => $item->award->vendor_id,
                    'qty_to_buy' => (float) $item->award->qty_to_buy,
                    'unit_cost' => (float) $item->award->unit_cost,
                ] : null,
                'cells' => $cells,
            ];
        })->values();

        return response()->json([
            'success' => true,
            'data' => [
                'rfq' => [
                    'id' => $rfq->id,
                    'reference' => $rfq->reference,
                    'ship_name' => $rfq->ship_name,
                    'base_currency' => $rfq->base_currency,
                    'status' => $rfq->status,
                ],
                'vendors' => $vendors,
                'rows' => $rows,
            ],
        ]);
    }

    /** Update a quote's currency and/or exchange rate (staff normalising in the grid). */
    public function updateQuoteRate(Request $request, Quote $quote)
    {
        $data = $request->validate([
            'exchange_rate' => ['sometimes', 'numeric', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
        ]);

        $update = [];
        if (array_key_exists('exchange_rate', $data)) {
            $update['exchange_rate'] = $data['exchange_rate'];
        }
        if (array_key_exists('currency', $data)) {
            $update['currency'] = strtoupper($data['currency']);
        }
        if ($update) {
            $quote->update($update);
        }

        return response()->json(['success' => true, 'data' => $quote]);
    }

    /**
     * Staff key in (or clear) a vendor's unit prices on the compare grid — used
     * when a vendor only uploaded a file and left the price fields blank.
     */
    public function saveVendorPrices(Request $request, Quote $quote)
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.rfq_item_id' => ['required', 'integer'],
            'items.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'items.*.remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $validIds = RfqItem::where('rfq_id', $quote->rfq_id)->pluck('id');

        foreach ($data['items'] as $row) {
            if (! $validIds->contains($row['rfq_item_id'])) {
                continue;
            }
            $cost = $row['unit_cost'] ?? null;
            if ($cost === null || $cost === '') {
                // Cleared price → drop the line.
                QuoteItem::where('quote_id', $quote->id)->where('rfq_item_id', $row['rfq_item_id'])->delete();

                continue;
            }
            QuoteItem::updateOrCreate(
                ['quote_id' => $quote->id, 'rfq_item_id' => $row['rfq_item_id']],
                ['unit_cost' => $cost, 'remarks' => $row['remarks'] ?? null]
            );
        }

        return response()->json(['success' => true, 'message' => 'Prices saved.']);
    }

    /** Short-lived signed URL so staff can open a vendor's uploaded file (R2 is private). */
    public function attachmentUrl(Quote $quote, QuoteAttachment $attachment)
    {
        abort_unless($attachment->quote_id === $quote->id, 404);

        $url = Storage::disk($attachment->disk)->temporaryUrl($attachment->path, now()->addMinutes(10));

        return response()->json(['success' => true, 'data' => ['url' => $url, 'name' => $attachment->original_name]]);
    }

    /** Save the awarded vendor + qty per line item. */
    public function saveAwards(Request $request, Rfq $rfq)
    {
        $data = $request->validate([
            'awards' => ['required', 'array'],
            'awards.*.rfq_item_id' => ['required', 'integer'],
            'awards.*.vendor_id' => ['required', 'integer'],
            'awards.*.quote_item_id' => ['nullable', 'integer'],
            'awards.*.qty_to_buy' => ['required', 'numeric', 'min:0'],
            'awards.*.unit_cost' => ['required', 'numeric', 'min:0'],
        ]);

        $itemIds = $rfq->items()->pluck('id');

        DB::transaction(function () use ($data, $rfq, $itemIds) {
            foreach ($data['awards'] as $a) {
                if (! $itemIds->contains($a['rfq_item_id'])) {
                    continue;
                }
                Award::updateOrCreate(
                    ['rfq_item_id' => $a['rfq_item_id']],
                    [
                        'vendor_id' => $a['vendor_id'],
                        'quote_item_id' => $a['quote_item_id'] ?? null,
                        'qty_to_buy' => $a['qty_to_buy'],
                        'unit_cost' => $a['unit_cost'],
                    ]
                );
            }

            if ($rfq->status !== 'closed') {
                $rfq->update(['status' => 'awarded']);
            }
        });

        return response()->json(['success' => true, 'message' => 'Awards saved.']);
    }

    /** Lock the enquiry: mark winning vendors selected, status -> closed. */
    public function finish(Rfq $rfq)
    {
        $rfq->load('items.award');

        DB::transaction(function () use ($rfq) {
            $awardedVendorIds = $rfq->items
                ->map(fn ($i) => $i->award?->vendor_id)
                ->filter()
                ->unique()
                ->values();

            RfqVendor::where('rfq_id', $rfq->id)
                ->whereIn('vendor_id', $awardedVendorIds)
                ->update(['status' => 'selected']);

            $rfq->update(['status' => 'closed']);
        });

        return response()->json(['success' => true, 'message' => 'Enquiry finished and locked.']);
    }

    /** Distinct line-item descriptions used on previous enquiries (autocomplete). */
    public function itemSuggestions(Request $request)
    {
        $q = $request->query('q');

        $descriptions = RfqItem::query()
            ->when($q, fn ($query) => $query->where('description', 'like', "%{$q}%"))
            ->distinct()
            ->orderBy('description')
            ->limit(20)
            ->pluck('description');

        return response()->json(['success' => true, 'data' => $descriptions]);
    }

    private function validateRfq(Request $request): array
    {
        return $request->validate([
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'customer_reference' => ['nullable', 'string', 'max:255'],
            'priority' => ['nullable', 'string', 'in:low,normal,high,urgent'],
            'requirements' => ['nullable', 'array'],
            'requirements.*' => ['string', 'max:255'],
            'ship_name' => ['nullable', 'string', 'max:255'],
            'requested_by' => ['nullable', 'string', 'max:255'],
            'delivery_port' => ['nullable', 'string', 'max:255'],
            'received_date' => ['nullable', 'date'],
            'base_currency' => ['required', 'string', 'size:3'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['sometimes', 'array'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.description' => ['required', 'string', 'max:1000'],
            'items.*.qty' => ['required', 'numeric', 'min:0'],
            'items.*.unit' => ['nullable', 'string', 'max:50'],
        ]);
    }

    private function syncItems(Rfq $rfq, array $items): void
    {
        foreach (array_values($items) as $i => $item) {
            $rfq->items()->create([
                'description' => $item['description'],
                'qty' => $item['qty'],
                'unit' => $item['unit'] ?? null,
                'sort' => $i,
            ]);
        }
    }
}

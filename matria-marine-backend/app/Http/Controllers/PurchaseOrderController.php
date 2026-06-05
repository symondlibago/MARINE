<?php

namespace App\Http\Controllers;

use App\Mail\PurchaseOrderMail;
use App\Models\PurchaseOrder;
use App\Models\Quote;
use App\Models\Rfq;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class PurchaseOrderController extends Controller
{
    public function index(Request $request)
    {
        $query = PurchaseOrder::query()
            ->with(['vendor:id,name', 'rfq:id,reference'])
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
            'vendor' => $po->vendor?->name,
            'currency' => $po->currency,
            'status' => $po->status,
            'subtotal' => (float) $po->subtotal,
            'items_count' => $po->items_count,
            'issued_date' => $po->issued_date?->toDateString(),
            'accepted_at' => $po->accepted_at?->toIso8601String(),
            'created_at' => $po->created_at?->toDateString(),
        ]);

        return response()->json(['success' => true, 'data' => $pos]);
    }

    public function show(PurchaseOrder $purchaseOrder)
    {
        // Backfill a magic-link token for any PO created before the column existed.
        if (! $purchaseOrder->token) {
            $purchaseOrder->forceFill(['token' => Str::random(48)])->save();
        }

        $purchaseOrder->load(['items', 'vendor', 'rfq:id,reference', 'creator:id,name']);

        return response()->json(['success' => true, 'data' => $purchaseOrder]);
    }

    /**
     * Turn an enquiry's awards into one purchase order per awarded vendor.
     * Idempotent: vendors that already have a PO for this enquiry are skipped.
     */
    public function generate(Request $request, Rfq $rfq)
    {
        $rfq->load(['items.award.quoteItem.quote', 'items.award.vendor']);

        $awardedItems = $rfq->items->filter(fn ($i) => $i->award);

        if ($awardedItems->isEmpty()) {
            return response()->json(['success' => false, 'message' => 'No awarded items to turn into purchase orders.'], 422);
        }

        $existingVendorIds = PurchaseOrder::where('rfq_id', $rfq->id)->pluck('vendor_id')->all();

        $created = collect();
        $skipped = 0;

        DB::transaction(function () use ($request, $rfq, $awardedItems, $existingVendorIds, $created, &$skipped) {
            foreach ($awardedItems->groupBy(fn ($i) => $i->award->vendor_id) as $vendorId => $items) {
                if (in_array($vendorId, $existingVendorIds)) {
                    $skipped++;

                    continue;
                }

                $first = $items->first();
                $quote = $first->award->quoteItem?->quote
                    ?? Quote::where('rfq_id', $rfq->id)->where('vendor_id', $vendorId)->first();
                $vendor = $first->award->vendor;

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
                $po->po_number = 'PO-'.str_pad((string) $po->id, 4, '0', STR_PAD_LEFT);
                $po->save();

                $subtotal = 0;
                foreach ($items->values() as $sort => $item) {
                    $qty = (float) $item->award->qty_to_buy;
                    $cost = (float) $item->award->unit_cost;
                    $lineTotal = round($qty * $cost, 4);
                    $subtotal += $lineTotal;

                    $po->items()->create([
                        'rfq_item_id' => $item->id,
                        'award_id' => $item->award->id,
                        'description' => $item->description,
                        'unit' => $item->unit,
                        'qty' => $qty,
                        'unit_cost' => $cost,
                        'line_total' => $lineTotal,
                        'sort' => $sort,
                    ]);
                }
                $po->update(['subtotal' => $subtotal]);

                $created->push($po->load('vendor:id,name'));
            }
        });

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
            'exchange_rate' => ['sometimes', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['sometimes', 'array'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.description' => ['required_with:items', 'string', 'max:1000'],
            'items.*.qty' => ['required_with:items', 'numeric', 'min:0'],
            'items.*.unit' => ['nullable', 'string', 'max:50'],
            'items.*.unit_cost' => ['required_with:items', 'numeric', 'min:0'],
        ]);

        DB::transaction(function () use ($purchaseOrder, $data) {
            $attrs = [];
            foreach (['status', 'notes', 'exchange_rate', 'issued_date', 'expected_date'] as $key) {
                if (array_key_exists($key, $data)) {
                    $attrs[$key] = $data[$key];
                }
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
        });

        return response()->json([
            'success' => true,
            'message' => 'Purchase order updated.',
            'data' => $purchaseOrder->fresh()->load(['items', 'vendor', 'rfq:id,reference']),
        ]);
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
        $purchaseOrder->load(['items', 'vendor']);

        return Pdf::loadView('pdf.purchase-order', ['po' => $purchaseOrder])
            ->download(($purchaseOrder->po_number ?: 'PO').'.pdf');
    }

    public function email(Request $request, PurchaseOrder $purchaseOrder)
    {
        $purchaseOrder->load(['items', 'vendor']);

        if (! $purchaseOrder->vendor?->email) {
            return response()->json(['success' => false, 'message' => 'This vendor has no email on file.'], 422);
        }

        if (! $purchaseOrder->token) {
            $purchaseOrder->forceFill(['token' => Str::random(48)])->save();
        }
        $link = rtrim(config('procurement.frontend_url'), '/').'/po/'.$purchaseOrder->token;

        try {
            Mail::to($purchaseOrder->vendor->email)->send(new PurchaseOrderMail($purchaseOrder, $request->user(), $link));
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Email failed: '.$e->getMessage()], 500);
        }

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
}

<?php

namespace App\Http\Controllers;

use App\Models\DeliveryOrder;
use App\Models\Offer;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DeliveryOrderController extends Controller
{
    public function index()
    {
        $orders = DeliveryOrder::with(['rfq:id,reference', 'customer:id,name'])
            ->orderByDesc('id')
            ->get();

        return response()->json(['success' => true, 'data' => $orders]);
    }

    public function show(DeliveryOrder $deliveryOrder)
    {
        $deliveryOrder->load(['items', 'offer:id,offer_number', 'rfq:id,reference,ship_name', 'customer:id,name,address,email']);

        return response()->json(['success' => true, 'data' => $deliveryOrder]);
    }

    /** Build (or return the existing) delivery order from an accepted offer. */
    public function generate(Request $request, Offer $offer)
    {
        $existing = DeliveryOrder::where('offer_id', $offer->id)->first();
        if ($existing) {
            return response()->json([
                'success' => true,
                'message' => 'Delivery order already exists for this offer.',
                'data' => $existing->load('items'),
            ]);
        }

        $offer->load(['items', 'rfq:id,customer_reference']);

        $do = DB::transaction(function () use ($offer, $request) {
            $do = DeliveryOrder::create([
                'do_number' => $this->nextNumber(),
                'offer_id' => $offer->id,
                'rfq_id' => $offer->rfq_id,
                'customer_id' => $offer->customer_id,
                'customer_name' => $offer->customer_name,
                'customer_address' => $offer->customer_address,
                'delivery_address' => $offer->customer_address, // default — staff confirms/edits
                'customer_reference' => $offer->rfq?->customer_reference,
                'currency' => $offer->currency,
                'status' => 'draft',
                'order_date' => now()->toDateString(),
                'subtotal' => $offer->subtotal,
                'created_by' => $request->user()?->id,
            ]);

            $sort = 0;
            foreach ($offer->items->where('is_heading', false) as $it) {
                $do->items()->create([
                    'offer_item_id' => $it->id,
                    'description' => $it->description,
                    'code' => $it->code,
                    'unit' => $it->unit,
                    'qty' => $it->qty,
                    'unit_price' => $it->unit_price,
                    'discount_amount' => $it->discount_amount,
                    'line_total' => $it->line_total,
                    'sort' => $sort++,
                ]);
            }

            $do->recalcTotals();

            // The offer has become an order — mark it accepted.
            if ($offer->status !== 'accepted') {
                $offer->update(['status' => 'accepted']);
            }

            return $do;
        });

        return response()->json([
            'success' => true,
            'message' => 'Delivery order created.',
            'data' => $do->load('items'),
        ], 201);
    }

    public function update(Request $request, DeliveryOrder $deliveryOrder)
    {
        $data = $request->validate([
            'delivery_address' => ['nullable', 'string', 'max:2000'],
            'customer_reference' => ['nullable', 'string', 'max:255'],
            'order_date' => ['nullable', 'date'],
            'readiness_date' => ['nullable', 'date'],
            'status' => ['sometimes', 'string', 'in:draft,confirmed,delivered,cancelled'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'items' => ['sometimes', 'array'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.qty' => ['nullable', 'numeric', 'min:0'],
        ]);

        DB::transaction(function () use ($deliveryOrder, $data) {
            $deliveryOrder->fill([
                'delivery_address' => array_key_exists('delivery_address', $data) ? $data['delivery_address'] : $deliveryOrder->delivery_address,
                'customer_reference' => array_key_exists('customer_reference', $data) ? $data['customer_reference'] : $deliveryOrder->customer_reference,
                'order_date' => array_key_exists('order_date', $data) ? $data['order_date'] : $deliveryOrder->order_date,
                'readiness_date' => array_key_exists('readiness_date', $data) ? $data['readiness_date'] : $deliveryOrder->readiness_date,
                'status' => $data['status'] ?? $deliveryOrder->status,
                'notes' => array_key_exists('notes', $data) ? $data['notes'] : $deliveryOrder->notes,
            ])->save();

            if (array_key_exists('items', $data)) {
                foreach ($data['items'] as $row) {
                    if (empty($row['id'])) {
                        continue;
                    }
                    $item = $deliveryOrder->items()->whereKey($row['id'])->first();
                    if (! $item) {
                        continue;
                    }
                    $qty = array_key_exists('qty', $row) ? (float) $row['qty'] : (float) $item->qty;
                    $net = (float) $item->unit_price - (float) $item->discount_amount;
                    $item->update(['qty' => $qty, 'line_total' => round($net * $qty, 2)]);
                }
            }

            $deliveryOrder->recalcTotals();
        });

        return response()->json([
            'success' => true,
            'message' => 'Delivery order saved.',
            'data' => $deliveryOrder->fresh()->load('items'),
        ]);
    }

    public function destroy(DeliveryOrder $deliveryOrder)
    {
        $deliveryOrder->delete();

        return response()->json(['success' => true, 'message' => 'Delivery order deleted.']);
    }

    public function pdf(DeliveryOrder $deliveryOrder)
    {
        $deliveryOrder->load('items');

        $logoPath = public_path('logo.png');
        $logo = is_file($logoPath) ? 'data:image/png;base64,'.base64_encode(file_get_contents($logoPath)) : null;

        $pdf = Pdf::loadView('pdf.delivery-order', [
            'do' => $deliveryOrder,
            'company' => config('procurement.company'),
            'logo' => $logo,
        ]);

        return $pdf->download(($deliveryOrder->do_number ?: 'delivery-order').'.pdf');
    }

    /** Next sequential DO number (DO-0001), computed up front. */
    private function nextNumber(): string
    {
        $last = DeliveryOrder::orderByDesc('id')->value('do_number');
        $seq = 1;
        if ($last && preg_match('/(\d+)$/', $last, $m)) {
            $seq = (int) $m[1] + 1;
        }

        return 'DO-'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }
}

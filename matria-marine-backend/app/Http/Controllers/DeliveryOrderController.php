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
        $orders = DeliveryOrder::with(['rfq:id,reference', 'customer:id,name', 'creator:id,name'])
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
        $existed = DeliveryOrder::where('offer_id', $offer->id)->exists();

        $do = DeliveryOrder::generateFromOffer($offer, $request->user()?->id);

        return response()->json([
            'success' => true,
            'message' => $existed ? 'Delivery order already exists for this offer.' : 'Delivery order created.',
            'data' => $do->load('items'),
        ], $existed ? 200 : 201);
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
        $deliveryOrder->load(['items', 'creator:id,name,phone']);

        $logoPath = public_path('logo.png');
        $logo = is_file($logoPath) ? 'data:image/png;base64,'.base64_encode(file_get_contents($logoPath)) : null;

        $pdf = Pdf::loadView('pdf.delivery-order', [
            'do' => $deliveryOrder,
            'company' => config('procurement.company'),
            'logo' => $logo,
        ]);

        return $pdf->download(($deliveryOrder->do_number ?: 'delivery-order').'.pdf');
    }

    /** Proforma invoice for the DO — the PRICED version (the delivery-order PDF shows quantities only). */
    public function proforma(DeliveryOrder $deliveryOrder)
    {
        $deliveryOrder->load(['items', 'creator:id,name,phone']);

        $logoPath = public_path('logo.png');
        $logo = is_file($logoPath) ? 'data:image/png;base64,'.base64_encode(file_get_contents($logoPath)) : null;

        $pdf = Pdf::loadView('pdf.proforma-invoice', [
            'do' => $deliveryOrder,
            'company' => config('procurement.company'),
            'logo' => $logo,
        ]);

        return $pdf->download('proforma-'.($deliveryOrder->do_number ?: 'delivery-order').'.pdf');
    }
}

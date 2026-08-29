<?php

namespace App\Http\Controllers;

use App\Models\DeliveryOrder;
use App\Models\DeliveryOrderAttachment;
use App\Models\Offer;
use App\Models\PurchaseOrder;
use App\Support\DocNumber;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class DeliveryOrderController extends Controller
{
    public function index()
    {
        $orders = DeliveryOrder::with([
            'rfq:id,reference,ship_name',
            'customer:id,name',
            'creator:id,name',
            'purchaseOrder:id,po_number',
        ])
            ->orderByDesc('id')
            ->get();

        return response()->json(['success' => true, 'data' => $orders]);
    }

    public function show(DeliveryOrder $deliveryOrder)
    {
        $deliveryOrder->load(['items', 'offer:id,offer_number', 'rfq:id,reference,ship_name', 'customer:id,name,address,email', 'purchaseOrder:id,po_number']);

        $payload = $deliveryOrder->toArray();
        $payload['attachments'] = $this->attachmentList($deliveryOrder);

        return response()->json(['success' => true, 'data' => $payload]);
    }

    /* ---------------------- proof of delivery ------------------------- */

    /**
     * File the signed copy back from the vessel.
     *
     * Internal evidence only — nothing filed here is ever attached to a
     * customer email or printed on a customer document.
     */
    public function uploadAttachments(Request $request, DeliveryOrder $deliveryOrder)
    {
        $request->validate([
            'files' => ['required', 'array', 'max:10'],
            'files.*' => ['file', 'max:10240', 'mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png,webp'],
            'kind' => ['nullable', Rule::in(array_keys(DeliveryOrderAttachment::KINDS))],
            'note' => ['nullable', 'string', 'max:190'],
        ]);

        foreach ($request->file('files') as $file) {
            $path = $file->store('delivery-orders/'.$deliveryOrder->id, 'r2');
            $deliveryOrder->attachments()->create([
                'disk' => 'r2',
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getClientMimeType(),
                'size' => $file->getSize(),
                'kind' => $request->input('kind', 'signed_do'),
                'note' => $request->input('note'),
                'uploaded_by' => $request->user()?->id,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Filed against '.$deliveryOrder->do_number.'.',
            'data' => $this->attachmentList($deliveryOrder->fresh()),
        ]);
    }

    public function deleteAttachment(DeliveryOrder $deliveryOrder, DeliveryOrderAttachment $attachment)
    {
        abort_unless($attachment->delivery_order_id === $deliveryOrder->id, 404);

        Storage::disk($attachment->disk)->delete($attachment->path);
        $attachment->delete();

        return response()->json([
            'success' => true,
            'message' => 'File removed.',
            'data' => $this->attachmentList($deliveryOrder->fresh()),
        ]);
    }

    /** Short-lived signed URL — the bucket is private. */
    public function attachmentUrl(DeliveryOrder $deliveryOrder, DeliveryOrderAttachment $attachment)
    {
        abort_unless($attachment->delivery_order_id === $deliveryOrder->id, 404);

        return response()->json(['success' => true, 'data' => [
            'url' => Storage::disk($attachment->disk)->temporaryUrl($attachment->path, now()->addMinutes(10)),
            'name' => $attachment->original_name,
        ]]);
    }

    private function attachmentList(DeliveryOrder $deliveryOrder)
    {
        return $deliveryOrder->attachments()
            ->with('uploader:id,name')
            ->get(['id', 'delivery_order_id', 'original_name', 'mime_type', 'size', 'kind', 'note', 'uploaded_by', 'created_at']);
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

    /** New flow: build (or return the existing) delivery order for ONE purchase order. */
    public function generateForPo(Request $request, PurchaseOrder $purchaseOrder)
    {
        $existed = DeliveryOrder::where('purchase_order_id', $purchaseOrder->id)->exists();

        $do = DeliveryOrder::generateFromPurchaseOrder($purchaseOrder, $request->user()?->id);

        return response()->json([
            'success' => true,
            'message' => $existed ? 'A delivery order already exists for this PO.' : 'Delivery order created for '.$purchaseOrder->po_number.'.',
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
            // The DO's delivery address is the source of truth — carry it onto the
            // linked purchase order so the vendor ships to the right place.
            if (array_key_exists('delivery_address', $data) && $deliveryOrder->purchase_order_id) {
                PurchaseOrder::whereKey($deliveryOrder->purchase_order_id)
                    ->update(['delivery_address' => $data['delivery_address']]);
            }

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
        $deliveryOrder->load(['items', 'creator:id,name,phone,email']);

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

        // Assign the MMS-ProINV number the first time the proforma is generated.
        if (! $deliveryOrder->proforma_number) {
            $deliveryOrder->update(['proforma_number' => DocNumber::next('ProINV')]);
        }

        $logoPath = public_path('logo.png');
        $logo = is_file($logoPath) ? 'data:image/png;base64,'.base64_encode(file_get_contents($logoPath)) : null;

        $pdf = Pdf::loadView('pdf.proforma-invoice', [
            'pf' => \App\Support\ProformaDoc::fromDeliveryOrder($deliveryOrder),
            'company' => config('procurement.company'),
            'logo' => $logo,
        ]);

        return $pdf->download('proforma-'.($deliveryOrder->do_number ?: 'delivery-order').'.pdf');
    }
}

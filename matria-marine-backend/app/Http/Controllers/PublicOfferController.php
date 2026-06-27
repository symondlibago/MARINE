<?php

namespace App\Http\Controllers;

use App\Models\DeliveryOrder;
use App\Models\Offer;
use Illuminate\Http\Request;

/**
 * Public, token-protected customer offer (quotation) endpoints (magic link — NO
 * auth). The {token} resolves to one customer's view of one quotation so they
 * can review it and accept it without logging in. Vendor names, base prices and
 * markup are never exposed here.
 */
class PublicOfferController extends Controller
{
    private function resolve(string $token): ?Offer
    {
        return Offer::with(['items', 'rfq:id,ship_name,customer_reference'])->where('token', $token)->first();
    }

    public function show(string $token)
    {
        $offer = $this->resolve($token);

        if (! $offer || $offer->status === 'declined') {
            return response()->json(['success' => false, 'message' => 'This quotation link is invalid or has been withdrawn.'], 404);
        }

        if (! $offer->opened_at) {
            $offer->forceFill(['opened_at' => now()])->save();
            if ($offer->status === 'draft') {
                $offer->forceFill(['status' => 'sent'])->save();
            }
        }

        return response()->json(['success' => true, 'data' => $this->present($offer)]);
    }

    public function accept(Request $request, string $token)
    {
        $offer = $this->resolve($token);

        if (! $offer) {
            return response()->json(['success' => false, 'message' => 'This quotation link is invalid or has expired.'], 404);
        }
        if ($offer->status === 'declined') {
            return response()->json(['success' => false, 'message' => 'This quotation has been withdrawn and can no longer be accepted.'], 422);
        }

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $offer->forceFill([
            'accepted_at' => $offer->accepted_at ?? now(),
            'accepted_by_name' => $data['name'] ?? $offer->accepted_by_name,
            'acceptance_note' => $data['note'] ?? $offer->acceptance_note,
            'opened_at' => $offer->opened_at ?? now(),
            'status' => 'accepted',
        ])->save();

        // Acceptance turns the quotation into an order — create the Delivery Order.
        DeliveryOrder::generateFromOffer($offer);

        return response()->json([
            'success' => true,
            'message' => 'Thank you — your acceptance has been recorded.',
            'data' => $this->present($offer->fresh(['items', 'rfq:id,ship_name,customer_reference'])),
        ]);
    }

    /** Customer-facing shape of the offer — NO vendor, base price or markup. */
    private function present(Offer $offer): array
    {
        return [
            'offer_number' => $offer->offer_number,
            'status' => $offer->status,
            'customer_name' => $offer->customer_name,
            'customer_address' => $offer->customer_address,
            'vessel' => $offer->rfq?->ship_name,
            'customer_reference' => $offer->rfq?->customer_reference,
            'currency' => $offer->currency,
            'valid_until' => optional($offer->valid_until)->toDateString(),
            'payment_terms' => $offer->payment_terms,
            'delivery_terms' => $offer->delivery_terms,
            'origin_type' => $offer->origin_type,
            'subtotal' => (float) $offer->subtotal,
            'packing_cost' => (float) $offer->packing_cost,
            'transportation_cost' => (float) $offer->transportation_cost,
            'grand_total' => ((float) $offer->packing_cost + (float) $offer->transportation_cost) > 0
                ? (float) $offer->grand_total
                : (float) $offer->subtotal,
            'opened_at' => $offer->opened_at?->toIso8601String(),
            'accepted_at' => $offer->accepted_at?->toIso8601String(),
            'accepted_by_name' => $offer->accepted_by_name,
            'acceptance_note' => $offer->acceptance_note,
            'company' => config('procurement.company'),
            'items' => $offer->items->map(fn ($i) => [
                'is_heading' => (bool) $i->is_heading,
                'description' => $i->description,
                'unit' => $i->unit,
                'qty' => (float) $i->qty,
                'unit_price' => (float) $i->unit_price,
                'discount' => round((float) $i->discount_amount * (float) $i->qty, 2),
                'line_total' => (float) $i->line_total,
            ])->values(),
        ];
    }
}

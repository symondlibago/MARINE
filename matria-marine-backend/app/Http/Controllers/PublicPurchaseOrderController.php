<?php

namespace App\Http\Controllers;

use App\Models\PurchaseOrder;
use Illuminate\Http\Request;

/**
 * Public, token-protected purchase-order endpoints (magic link — NO auth).
 * The {token} resolves to a single vendor's view of one purchase order so they
 * can review it and confirm acceptance without logging in.
 */
class PublicPurchaseOrderController extends Controller
{
    private function resolve(string $token): ?PurchaseOrder
    {
        return PurchaseOrder::with(['items', 'vendor'])->where('token', $token)->first();
    }

    public function show(string $token)
    {
        $po = $this->resolve($token);

        if (! $po || $po->status === 'cancelled') {
            return response()->json(['success' => false, 'message' => 'This purchase order link is invalid or has been cancelled.'], 404);
        }

        // Record the vendor's first view.
        if (! $po->opened_at) {
            $po->forceFill(['opened_at' => now()])->save();
        }

        return response()->json([
            'success' => true,
            'data' => $this->present($po),
        ]);
    }

    public function accept(Request $request, string $token)
    {
        $po = $this->resolve($token);

        if (! $po) {
            return response()->json(['success' => false, 'message' => 'This purchase order link is invalid or has expired.'], 404);
        }
        if ($po->status === 'cancelled') {
            return response()->json(['success' => false, 'message' => 'This purchase order has been cancelled and can no longer be accepted.'], 422);
        }

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $po->forceFill([
            'accepted_at' => $po->accepted_at ?? now(),
            'accepted_by_name' => $data['name'] ?? $po->accepted_by_name,
            'acceptance_note' => $data['note'] ?? $po->acceptance_note,
            'opened_at' => $po->opened_at ?? now(),
        ])->save();

        return response()->json([
            'success' => true,
            'message' => 'Thank you — your acceptance has been recorded.',
            'data' => $this->present($po->fresh(['items', 'vendor'])),
        ]);
    }

    /** Vendor-facing shape of the purchase order. */
    private function present(PurchaseOrder $po): array
    {
        return [
            'po_number' => $po->po_number,
            'status' => $po->status,
            'supplier' => $po->vendor?->name,
            'ship_name' => $po->ship_name,
            'delivery_port' => $po->delivery_port,
            'currency' => $po->currency,
            'issued_date' => $po->issued_date?->toDateString(),
            'expected_date' => $po->expected_date?->toDateString(),
            'notes' => $po->notes,
            'subtotal' => (float) $po->subtotal,
            'opened_at' => $po->opened_at?->toIso8601String(),
            'accepted_at' => $po->accepted_at?->toIso8601String(),
            'accepted_by_name' => $po->accepted_by_name,
            'acceptance_note' => $po->acceptance_note,
            'items' => $po->items->map(fn ($i) => [
                'description' => $i->description,
                'unit' => $i->unit,
                'qty' => (float) $i->qty,
                'unit_cost' => (float) $i->unit_cost,
                'line_total' => (float) $i->line_total,
            ])->values(),
        ];
    }
}

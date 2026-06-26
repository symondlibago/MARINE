<?php

namespace App\Http\Controllers;

use App\Mail\ReturnNoteMail;
use App\Models\PurchaseOrder;
use App\Models\ReturnNote;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class ReturnNoteController extends Controller
{
    public function index(Request $request)
    {
        $query = ReturnNote::query()
            ->with(['purchaseOrder:id,po_number', 'vendor:id,name'])
            ->withCount('items')
            ->orderByDesc('id');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('rtn_number', 'like', "%{$search}%")
                    ->orWhere('vendor_name', 'like', "%{$search}%")
                    ->orWhereHas('purchaseOrder', fn ($p) => $p->where('po_number', 'like', "%{$search}%"));
            });
        }

        $rows = $query->get()->map(fn (ReturnNote $rn) => [
            'id' => $rn->id,
            'rtn_number' => $rn->rtn_number,
            'po_number' => $rn->purchaseOrder?->po_number,
            'purchase_order_id' => $rn->purchase_order_id,
            'vendor' => $rn->vendor_name ?: $rn->vendor?->name,
            'currency' => $rn->currency,
            'status' => $rn->status,
            'subtotal' => (float) $rn->subtotal,
            'items_count' => $rn->items_count,
            'return_date' => $rn->return_date?->toDateString(),
            'created_at' => $rn->created_at?->toDateString(),
        ]);

        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function show(ReturnNote $returnNote)
    {
        $returnNote->load(['items', 'purchaseOrder:id,po_number,currency,subtotal', 'vendor', 'creator:id,name']);

        return response()->json(['success' => true, 'data' => $returnNote]);
    }

    /**
     * Create or update the return note for a purchase order from the submitted lines.
     * Only lines with a returned qty > 0 are kept; an emptied note is deleted.
     */
    public function storeForPo(Request $request, PurchaseOrder $purchaseOrder)
    {
        $data = $request->validate([
            'return_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array'],
            'lines.*.purchase_order_item_id' => ['required', 'integer'],
            'lines.*.qty' => ['required', 'numeric', 'min:0'],
            'lines.*.reason' => ['nullable', 'string', 'max:500'],
        ]);

        $purchaseOrder->load(['items', 'vendor:id,name']);
        $itemsById = $purchaseOrder->items->keyBy('id');

        $rn = DB::transaction(function () use ($purchaseOrder, $data, $itemsById, $request) {
            $rn = ReturnNote::firstOrNew(['purchase_order_id' => $purchaseOrder->id]);
            if (! $rn->exists) {
                $rn->rtn_number = $this->nextNumber();
                $rn->created_by = $request->user()?->id;
                $rn->status = 'draft';
            }
            $rn->fill([
                'rfq_id' => $purchaseOrder->rfq_id,
                'vendor_id' => $purchaseOrder->vendor_id,
                'vendor_name' => $purchaseOrder->vendor?->name,
                'currency' => $purchaseOrder->currency,
                'return_date' => $data['return_date'] ?? $rn->return_date ?? now()->toDateString(),
                'notes' => array_key_exists('notes', $data) ? $data['notes'] : $rn->notes,
            ]);
            $rn->save();

            $keep = [];
            $sort = 0;
            foreach ($data['lines'] as $line) {
                $poItem = $itemsById->get($line['purchase_order_item_id']);
                if (! $poItem) {
                    continue;
                }
                // Cannot return more than was ordered on the PO line.
                $qty = min((float) $line['qty'], (float) $poItem->qty);
                if ($qty <= 0) {
                    continue;
                }
                $unitCost = (float) $poItem->unit_cost;
                $rnItem = $rn->items()->updateOrCreate(
                    ['purchase_order_item_id' => $poItem->id],
                    [
                        'description' => $poItem->description,
                        'unit' => $poItem->unit,
                        'qty' => $qty,
                        'unit_cost' => $unitCost,
                        'line_total' => round($qty * $unitCost, 4),
                        'reason' => $line['reason'] ?? null,
                        'sort' => $sort++,
                    ]
                );
                $keep[] = $rnItem->id;
            }
            $rn->items()->whereNotIn('id', $keep)->delete();
            $rn->recalcSubtotal();

            return $rn;
        });

        // An empty return note (everything set back to 0) is removed entirely.
        if ($rn->items()->count() === 0) {
            $rn->delete();

            return response()->json(['success' => true, 'message' => 'No returns recorded — return note cleared.', 'data' => null]);
        }

        return response()->json(['success' => true, 'message' => 'Return note saved.', 'data' => $rn->fresh()->load('items')]);
    }

    public function update(Request $request, ReturnNote $returnNote)
    {
        $data = $request->validate([
            'status' => ['sometimes', 'in:draft,issued'],
            'return_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $attrs = [];
        foreach (['status', 'return_date', 'notes'] as $key) {
            if (array_key_exists($key, $data)) {
                $attrs[$key] = $data[$key];
            }
        }
        if (($data['status'] ?? null) === 'issued' && ! $returnNote->issued_at) {
            $attrs['issued_at'] = now();
        }
        if ($attrs) {
            $returnNote->update($attrs);
        }

        return response()->json([
            'success' => true,
            'message' => 'Return note updated.',
            'data' => $returnNote->fresh()->load(['items', 'purchaseOrder:id,po_number', 'vendor']),
        ]);
    }

    public function destroy(ReturnNote $returnNote)
    {
        if ($returnNote->status !== 'draft') {
            return response()->json([
                'success' => false,
                'message' => 'Only draft return notes can be deleted.',
            ], 422);
        }

        $returnNote->delete();

        return response()->json(['success' => true, 'message' => 'Return note deleted.']);
    }

    public function pdf(ReturnNote $returnNote)
    {
        $returnNote->load(['items', 'purchaseOrder:id,po_number,ship_name', 'vendor']);

        $logoPath = public_path('logo.png');
        $logo = is_file($logoPath) ? 'data:image/png;base64,'.base64_encode(file_get_contents($logoPath)) : null;

        return Pdf::loadView('pdf.return-note', ['rn' => $returnNote, 'logo' => $logo])
            ->download(($returnNote->rtn_number ?: 'return-note').'.pdf');
    }

    public function email(Request $request, ReturnNote $returnNote)
    {
        $returnNote->load(['items', 'purchaseOrder:id,po_number', 'vendor']);

        if (! $returnNote->vendor?->email) {
            return response()->json(['success' => false, 'message' => 'This vendor has no email on file.'], 422);
        }

        try {
            Mail::to($returnNote->vendor->email)->send(new ReturnNoteMail($returnNote, $request->user()));
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Email failed: '.$e->getMessage()], 500);
        }

        if ($returnNote->status === 'draft') {
            $returnNote->update(['status' => 'issued', 'issued_at' => $returnNote->issued_at ?? now()]);
        }

        return response()->json(['success' => true, 'message' => 'Return note emailed to '.$returnNote->vendor->email.'.']);
    }

    /** Next sequential return-note number (RTN-0001), independent of the row id. */
    private function nextNumber(): string
    {
        $last = ReturnNote::orderByDesc('id')->value('rtn_number');
        $seq = 1;
        if ($last && preg_match('/(\d+)$/', $last, $m)) {
            $seq = (int) $m[1] + 1;
        }

        return 'RTN-'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }
}

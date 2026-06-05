<?php

namespace App\Http\Controllers;

use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PurchaseInvoiceController extends Controller
{
    public function index(Request $request)
    {
        $query = PurchaseInvoice::query()
            ->with(['vendor:id,name', 'purchaseOrder:id,po_number', 'rfq:id,reference'])
            ->withCount('items')
            ->orderByDesc('id');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($vendorId = $request->query('vendor_id')) {
            $query->where('vendor_id', $vendorId);
        }
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('reference', 'like', "%{$search}%")
                    ->orWhere('vendor_invoice_no', 'like', "%{$search}%")
                    ->orWhereHas('vendor', fn ($v) => $v->where('name', 'like', "%{$search}%"));
            });
        }

        $rows = $query->get()->map(fn (PurchaseInvoice $inv) => [
            'id' => $inv->id,
            'reference' => $inv->reference,
            'vendor_invoice_no' => $inv->vendor_invoice_no,
            'vendor' => $inv->vendor?->name,
            'po_number' => $inv->purchaseOrder?->po_number,
            'enquiry' => $inv->rfq?->reference,
            'currency' => $inv->currency,
            'status' => $inv->status,
            'total' => (float) $inv->total,
            'items_count' => $inv->items_count,
            'invoice_date' => $inv->invoice_date?->toDateString(),
            'exported_at' => $inv->exported_at?->toIso8601String(),
        ]);

        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function show(PurchaseInvoice $purchaseInvoice)
    {
        $purchaseInvoice->load([
            'items', 'vendor', 'purchaseOrder:id,po_number,subtotal,currency', 'rfq:id,reference', 'creator:id,name',
        ]);

        return response()->json(['success' => true, 'data' => $purchaseInvoice]);
    }

    /** Pre-fill a draft invoice from a purchase order (lines + PO snapshots for the 3-way match). */
    public function createFromPo(Request $request, PurchaseOrder $purchaseOrder)
    {
        $purchaseOrder->load('items');

        $invoice = DB::transaction(function () use ($request, $purchaseOrder) {
            $inv = PurchaseInvoice::create([
                'purchase_order_id' => $purchaseOrder->id,
                'rfq_id' => $purchaseOrder->rfq_id,
                'vendor_id' => $purchaseOrder->vendor_id,
                'currency' => $purchaseOrder->currency,
                'base_currency' => $purchaseOrder->base_currency,
                'exchange_rate' => $purchaseOrder->exchange_rate,
                'status' => 'draft',
                'invoice_date' => now()->toDateString(),
                'received_date' => $purchaseOrder->status === 'received' ? now()->toDateString() : null,
                'created_by' => $request->user()?->id,
            ]);
            $inv->reference = 'PINV-'.str_pad((string) $inv->id, 4, '0', STR_PAD_LEFT);
            $inv->save();

            $subtotal = 0;
            foreach ($purchaseOrder->items->values() as $sort => $poItem) {
                $qty = (float) $poItem->qty;
                $cost = (float) $poItem->unit_cost;
                $lineTotal = round($qty * $cost, 4);
                $subtotal += $lineTotal;

                $inv->items()->create([
                    'purchase_order_item_id' => $poItem->id,
                    'rfq_item_id' => $poItem->rfq_item_id,
                    'description' => $poItem->description,
                    'unit' => $poItem->unit,
                    'qty' => $qty,
                    'unit_cost' => $cost,
                    'line_total' => $lineTotal,
                    'po_qty' => $qty,
                    'po_unit_cost' => $cost,
                    'sort' => $sort,
                ]);
            }
            $inv->update(['subtotal' => $subtotal, 'total' => $subtotal]);

            return $inv;
        });

        return response()->json([
            'success' => true,
            'message' => 'Draft invoice created from '.$purchaseOrder->po_number.'.',
            'data' => $invoice->load(['items', 'vendor', 'purchaseOrder:id,po_number', 'rfq:id,reference']),
        ], 201);
    }

    /** Create a standalone invoice not tied to a purchase order. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'vendor_id' => ['required', 'integer', 'exists:vendors,id'],
            'vendor_invoice_no' => ['nullable', 'string', 'max:100'],
            'currency' => ['required', 'string', 'size:3'],
            'exchange_rate' => ['nullable', 'numeric', 'min:0'],
            'invoice_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
            'other_charges' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.description' => ['required', 'string', 'max:1000'],
            'items.*.qty' => ['required', 'numeric', 'min:0'],
            'items.*.unit' => ['nullable', 'string', 'max:50'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0'],
            'items.*.account_code' => ['nullable', 'string', 'max:50'],
        ]);

        $invoice = DB::transaction(function () use ($request, $data) {
            $inv = PurchaseInvoice::create([
                'vendor_id' => $data['vendor_id'],
                'vendor_invoice_no' => $data['vendor_invoice_no'] ?? null,
                'currency' => strtoupper($data['currency']),
                'base_currency' => strtoupper($data['currency']),
                'exchange_rate' => $data['exchange_rate'] ?? 1,
                'status' => 'draft',
                'invoice_date' => $data['invoice_date'] ?? now()->toDateString(),
                'due_date' => $data['due_date'] ?? null,
                'other_charges' => $data['other_charges'] ?? 0,
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()?->id,
            ]);
            $inv->reference = 'PINV-'.str_pad((string) $inv->id, 4, '0', STR_PAD_LEFT);
            $inv->save();

            $subtotal = 0;
            foreach (array_values($data['items']) as $i => $row) {
                $lineTotal = round((float) $row['qty'] * (float) $row['unit_cost'], 4);
                $subtotal += $lineTotal;
                $inv->items()->create([
                    'description' => $row['description'],
                    'unit' => $row['unit'] ?? null,
                    'qty' => $row['qty'],
                    'unit_cost' => $row['unit_cost'],
                    'line_total' => $lineTotal,
                    'account_code' => $row['account_code'] ?? null,
                    'sort' => $i,
                ]);
            }
            $inv->update(['subtotal' => $subtotal, 'total' => $subtotal + (float) ($data['other_charges'] ?? 0)]);

            return $inv;
        });

        return response()->json([
            'success' => true,
            'message' => 'Invoice created.',
            'data' => $invoice->load(['items', 'vendor']),
        ], 201);
    }

    public function update(Request $request, PurchaseInvoice $purchaseInvoice)
    {
        if (in_array($purchaseInvoice->status, ['exported', 'cancelled'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'This invoice is '.$purchaseInvoice->status.' and can no longer be edited.',
            ], 422);
        }

        $data = $request->validate([
            'vendor_invoice_no' => ['nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'in:draft,approved,cancelled'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'exchange_rate' => ['sometimes', 'numeric', 'min:0'],
            'invoice_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
            'received_date' => ['nullable', 'date'],
            'other_charges' => ['sometimes', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['sometimes', 'array'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.description' => ['required_with:items', 'string', 'max:1000'],
            'items.*.qty' => ['required_with:items', 'numeric', 'min:0'],
            'items.*.unit' => ['nullable', 'string', 'max:50'],
            'items.*.unit_cost' => ['required_with:items', 'numeric', 'min:0'],
            'items.*.account_code' => ['nullable', 'string', 'max:50'],
        ]);

        DB::transaction(function () use ($purchaseInvoice, $data) {
            $attrs = [];
            foreach (['vendor_invoice_no', 'status', 'currency', 'exchange_rate', 'invoice_date', 'due_date', 'received_date', 'other_charges', 'notes'] as $k) {
                if (array_key_exists($k, $data)) {
                    $attrs[$k] = $data[$k];
                }
            }
            if (isset($attrs['currency'])) {
                $attrs['currency'] = strtoupper($attrs['currency']);
            }
            if ($attrs) {
                $purchaseInvoice->update($attrs);
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
                        'account_code' => $row['account_code'] ?? null,
                        'sort' => $i,
                    ];
                    if (! empty($row['id']) && ($item = $purchaseInvoice->items()->whereKey($row['id'])->first())) {
                        $item->update($payload);
                        $keep[] = $item->id;
                    } else {
                        $keep[] = $purchaseInvoice->items()->create($payload)->id;
                    }
                }
                $purchaseInvoice->items()->whereNotIn('id', $keep)->delete();
            }

            $purchaseInvoice->recalcTotals();
        });

        return response()->json([
            'success' => true,
            'message' => 'Invoice updated.',
            'data' => $purchaseInvoice->fresh()->load(['items', 'vendor', 'purchaseOrder:id,po_number', 'rfq:id,reference']),
        ]);
    }

    public function destroy(PurchaseInvoice $purchaseInvoice)
    {
        if ($purchaseInvoice->status === 'exported') {
            return response()->json(['success' => false, 'message' => 'Exported invoices cannot be deleted.'], 422);
        }

        $purchaseInvoice->delete();

        return response()->json(['success' => true, 'message' => 'Invoice deleted.']);
    }

    /**
     * Export invoices to a Business Central / Navision purchase-invoice CSV.
     * With no ids, exports every 'approved' invoice. Marks them exported.
     */
    public function export(Request $request)
    {
        $data = $request->validate([
            'ids' => ['nullable', 'array'],
            'ids.*' => ['integer'],
            'mark' => ['nullable', 'boolean'],
        ]);

        $query = PurchaseInvoice::with(['vendor', 'items', 'rfq:id,reference', 'purchaseOrder:id,po_number']);
        if (! empty($data['ids'])) {
            $query->whereIn('id', $data['ids'])->whereNot('status', 'cancelled');
        } else {
            $query->where('status', 'approved');
        }
        $invoices = $query->orderBy('id')->get();

        if ($invoices->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No invoices to export. Approve invoices first, or select specific ones.',
            ], 422);
        }

        $cfg = config('procurement.navision');
        $num = fn ($v, $dec) => number_format((float) $v, $dec, '.', '');
        $qty = fn ($v) => rtrim(rtrim(number_format((float) $v, 3, '.', ''), '0'), '.') ?: '0';

        $header = [
            'Document Type', 'Document No.', 'Vendor No.', 'Vendor Name', 'External Document No.',
            'Posting Date', 'Document Date', 'Due Date', 'Currency Code',
            'Line Type', 'No.', 'Description', 'Quantity', 'Unit of Measure', 'Direct Unit Cost', 'Line Amount',
            'Enquiry Ref', 'PO No.',
        ];

        $rows = [];
        foreach ($invoices as $inv) {
            $fmt = fn ($d) => $d ? $d->format($cfg['date_format']) : '';
            $base = [
                'Invoice', $inv->reference, $inv->vendor?->nav_code ?? '', $inv->vendor?->name ?? '',
                $inv->vendor_invoice_no ?? '', $fmt($inv->invoice_date), $fmt($inv->invoice_date),
                $fmt($inv->due_date), $inv->currency,
            ];
            $trail = [$inv->rfq?->reference ?? '', $inv->purchaseOrder?->po_number ?? ''];

            foreach ($inv->items as $line) {
                $rows[] = array_merge($base, [
                    $cfg['line_type'],
                    $line->account_code ?: $cfg['default_account'],
                    $line->description,
                    $qty($line->qty),
                    $line->unit ?? '',
                    $num($line->unit_cost, 4),
                    $num($line->line_total, 4),
                ], $trail);
            }

            if ((float) $inv->other_charges > 0) {
                $rows[] = array_merge($base, [
                    $cfg['line_type'],
                    $cfg['charges_account'] ?: $cfg['default_account'],
                    'Other charges / freight',
                    '1', '',
                    $num($inv->other_charges, 4),
                    $num($inv->other_charges, 4),
                ], $trail);
            }
        }

        if ($data['mark'] ?? true) {
            PurchaseInvoice::whereIn('id', $invoices->pluck('id'))
                ->update(['status' => 'exported', 'exported_at' => now()]);
        }

        $delimiter = $cfg['delimiter'] ?: ',';
        $fh = fopen('php://temp', 'r+');
        fputcsv($fh, $header, $delimiter);
        foreach ($rows as $row) {
            fputcsv($fh, $row, $delimiter);
        }
        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="matria-navision-'.now()->format('Ymd-His').'.csv"',
        ]);
    }
}

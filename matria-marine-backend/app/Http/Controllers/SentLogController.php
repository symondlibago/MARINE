<?php

namespace App\Http\Controllers;

use App\Models\SentLog;
use Illuminate\Http\Request;

/**
 * Sent log — proof of every document email sent from the portal (RFQ to
 * vendors, purchase orders, quotations, return notes). Read-only listing.
 */
class SentLogController extends Controller
{
    public function index(Request $request)
    {
        $query = SentLog::query()->orderByDesc('id');

        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('reference', 'like', "%{$search}%")
                    ->orWhere('recipient_name', 'like', "%{$search}%")
                    ->orWhere('recipient_email', 'like', "%{$search}%")
                    ->orWhere('type', 'like', "%{$search}%")
                    ->orWhere('sent_by_name', 'like', "%{$search}%");
            });
        }

        $logs = $query->limit(500)->get();

        // Resolve each reference to its record (one query per document type) so
        // the UI can link the reference straight to the page it lives on.
        $maps = [
            'RFQ' => [\App\Models\Rfq::class, 'reference', '/enquiries/'],
            'Quotation' => [\App\Models\Offer::class, 'offer_number', '/offers/'],
            'Purchase Order' => [\App\Models\PurchaseOrder::class, 'po_number', '/purchase-orders/'],
            'Return Note' => [\App\Models\ReturnNote::class, 'rtn_number', '/return-notes/'],
            'Invoice' => [\App\Models\CustomerInvoice::class, 'invoice_number', '/invoices/'],
        ];
        $links = [];
        foreach ($maps as $type => [$model, $col, $prefix]) {
            $refs = $logs->where('type', $type)->pluck('reference')->filter()->unique()->values();
            if ($refs->isEmpty()) {
                continue;
            }
            foreach ($model::whereIn($col, $refs)->pluck('id', $col) as $ref => $recordId) {
                $links[$type.'|'.$ref] = $prefix.$recordId;
            }
        }

        $rows = $logs->map(fn (SentLog $l) => [
            'id' => $l->id,
            'type' => $l->type,
            'reference' => $l->reference,
            'link' => $links[$l->type.'|'.$l->reference] ?? null,
            'recipient_name' => $l->recipient_name,
            'recipient_email' => $l->recipient_email,
            'subject' => $l->subject,
            'status' => $l->status,
            'error' => $l->error,
            'sent_by' => $l->sent_by_name,
            'sent_at' => $l->created_at?->toDayDateTimeString(),
        ]);

        return response()->json(['success' => true, 'data' => $rows]);
    }
}

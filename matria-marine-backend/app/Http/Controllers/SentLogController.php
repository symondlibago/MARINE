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

        $rows = $query->limit(500)->get()->map(fn (SentLog $l) => [
            'id' => $l->id,
            'type' => $l->type,
            'reference' => $l->reference,
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

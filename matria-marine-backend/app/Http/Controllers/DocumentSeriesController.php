<?php

namespace App\Http\Controllers;

use App\Models\DocumentCounterAudit;
use App\Support\DocumentSeries;
use Illuminate\Http\Request;

/**
 * Admin screen for the running document numbers.
 *
 * Thin by design — every rule that keeps this safe lives in
 * {@see DocumentSeries}, so the guard cannot be bypassed by another caller.
 */
class DocumentSeriesController extends Controller
{
    public function index()
    {
        return response()->json(['success' => true, 'data' => [
            'series' => DocumentSeries::state(),
            'history' => DocumentCounterAudit::with('changedBy:id,name')
                ->latest('id')->limit(20)->get()
                ->map(fn ($a) => [
                    'id' => $a->id,
                    'key' => $a->key,
                    'label' => \App\Support\DocNumber::label($a->key),
                    'from' => \App\Support\DocNumber::preview($a->key, $a->from_seq + 1),
                    'to' => \App\Support\DocNumber::preview($a->key, $a->to_seq + 1),
                    'reason' => $a->reason,
                    'by' => $a->changedBy?->name,
                    'at' => $a->created_at?->toDateTimeString(),
                ]),
        ]]);
    }

    public function update(Request $request, string $key)
    {
        $data = $request->validate([
            'next_number' => ['required', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        // Throws a 422 with a readable message if the move goes backwards.
        $result = DocumentSeries::setNext(
            $key,
            (int) $data['next_number'],
            $request->user(),
            $data['reason'] ?? null,
        );

        return response()->json([
            'success' => true,
            'message' => $result['changed']
                ? 'The next '.\App\Support\DocNumber::label($key).' will be '.$result['next_number'].'.'
                : 'Already set to '.$result['next_number'].' — nothing changed.',
            'data' => ['series' => DocumentSeries::state(), 'result' => $result],
        ]);
    }
}

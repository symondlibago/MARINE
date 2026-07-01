<?php

namespace App\Http\Controllers;

use App\Models\OperatingExpense;
use Illuminate\Http\Request;

class OperatingExpenseController extends Controller
{
    public function index(Request $request)
    {
        $rows = OperatingExpense::with('creator:id,name')
            ->orderByDesc('effective_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (OperatingExpense $e) => $this->present($e));

        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['created_by'] = $request->user()?->id;

        $expense = OperatingExpense::create($data);

        return response()->json(['success' => true, 'message' => 'Operating expense added.', 'data' => $this->present($expense)]);
    }

    public function update(Request $request, OperatingExpense $operatingExpense)
    {
        $operatingExpense->update($this->validated($request));

        return response()->json(['success' => true, 'message' => 'Operating expense updated.', 'data' => $this->present($operatingExpense->fresh())]);
    }

    public function destroy(OperatingExpense $operatingExpense)
    {
        $operatingExpense->delete();

        return response()->json(['success' => true, 'message' => 'Operating expense removed.']);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:100'],
            'amount' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'exchange_rate' => ['nullable', 'numeric', 'min:0'],
            'effective_date' => ['required', 'date'],
            'recurring' => ['boolean'],
            'end_date' => ['nullable', 'date', 'after_or_equal:effective_date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $data['currency'] = strtoupper($data['currency']);
        $data['exchange_rate'] = (! empty($data['exchange_rate']) && $data['exchange_rate'] > 0) ? $data['exchange_rate'] : 1;
        $data['recurring'] = (bool) ($data['recurring'] ?? false);
        if (! $data['recurring']) {
            $data['end_date'] = null;
        }

        return $data;
    }

    private function present(OperatingExpense $e): array
    {
        return [
            'id' => $e->id,
            'name' => $e->name,
            'category' => $e->category,
            'amount' => (float) $e->amount,
            'currency' => $e->currency,
            'exchange_rate' => (float) $e->exchange_rate,
            'effective_date' => $e->effective_date?->toDateString(),
            'recurring' => (bool) $e->recurring,
            'end_date' => $e->end_date?->toDateString(),
            'notes' => $e->notes,
            'created_by' => $e->creator?->name,
        ];
    }
}

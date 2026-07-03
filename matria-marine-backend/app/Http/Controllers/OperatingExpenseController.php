<?php

namespace App\Http\Controllers;

use App\Models\OperatingExpense;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OperatingExpenseController extends Controller
{
    public function index(Request $request)
    {
        $rows = OperatingExpense::with(['items', 'creator:id,name'])
            ->orderByDesc('period_start')
            ->orderByDesc('id')
            ->get()
            ->map(fn (OperatingExpense $g) => $this->present($g));

        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        $group = DB::transaction(function () use ($data, $request) {
            $group = OperatingExpense::create([
                'label' => $data['label'] ?? null,
                'period_start' => $data['period_start'],
                'period_end' => $data['period_end'],
                'currency' => $data['currency'],
                'exchange_rate' => $data['exchange_rate'],
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()?->id,
            ]);
            $this->syncItems($group, $data['items']);

            return $group;
        });

        return response()->json(['success' => true, 'message' => 'Expenses saved.', 'data' => $this->present($group->fresh('items'))], 201);
    }

    public function update(Request $request, OperatingExpense $operatingExpense)
    {
        $data = $this->validated($request);

        DB::transaction(function () use ($operatingExpense, $data) {
            $operatingExpense->update([
                'label' => $data['label'] ?? null,
                'period_start' => $data['period_start'],
                'period_end' => $data['period_end'],
                'currency' => $data['currency'],
                'exchange_rate' => $data['exchange_rate'],
                'notes' => $data['notes'] ?? null,
            ]);
            $operatingExpense->items()->delete();
            $this->syncItems($operatingExpense, $data['items']);
        });

        return response()->json(['success' => true, 'message' => 'Expenses updated.', 'data' => $this->present($operatingExpense->fresh('items'))]);
    }

    public function destroy(OperatingExpense $operatingExpense)
    {
        $operatingExpense->delete(); // items cascade

        return response()->json(['success' => true, 'message' => 'Expense group removed.']);
    }

    private function syncItems(OperatingExpense $group, array $items): void
    {
        $sort = 0;
        foreach ($items as $item) {
            $name = trim((string) ($item['name'] ?? ''));
            if ($name === '' && (float) ($item['amount'] ?? 0) === 0.0) {
                continue;
            }
            $group->items()->create([
                'name' => $name !== '' ? $name : 'Expense',
                'category' => $item['category'] ?? null,
                'amount' => round((float) ($item['amount'] ?? 0), 4),
                'sort' => $sort++,
            ]);
        }
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:255'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'currency' => ['required', 'string', 'size:3'],
            'exchange_rate' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.name' => ['nullable', 'string', 'max:255'],
            'items.*.category' => ['nullable', 'string', 'max:100'],
            'items.*.amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        $data['currency'] = strtoupper($data['currency']);
        $data['exchange_rate'] = (! empty($data['exchange_rate']) && $data['exchange_rate'] > 0) ? $data['exchange_rate'] : 1;

        return $data;
    }

    private function present(OperatingExpense $g): array
    {
        return [
            'id' => $g->id,
            'label' => $g->label,
            'period_start' => $g->period_start?->toDateString(),
            'period_end' => $g->period_end?->toDateString(),
            'currency' => $g->currency,
            'exchange_rate' => (float) $g->exchange_rate,
            'notes' => $g->notes,
            'total' => round($g->total(), 2),
            'total_base' => round($g->totalBase(), 2),
            'items' => $g->items->map(fn ($i) => [
                'id' => $i->id,
                'name' => $i->name,
                'category' => $i->category,
                'amount' => (float) $i->amount,
            ])->values(),
            'created_by' => $g->creator?->name,
        ];
    }
}

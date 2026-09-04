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
                'account_code' => $data['account_code'],
                'tax_rate' => $data['tax_rate'],
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()?->id,
            ]);
            $this->syncItems($group, $data['items']);
            $this->recalcTax($group);

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
                'account_code' => $data['account_code'],
                'tax_rate' => $data['tax_rate'],
                'notes' => $data['notes'] ?? null,
            ]);
            $operatingExpense->items()->delete();
            $this->syncItems($operatingExpense, $data['items']);
            $this->recalcTax($operatingExpense);
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

    /**
     * GST paid on a group of overheads, derived from its line total.
     *
     * Called after the items are synced, since the total those lines add up to
     * is what the tax is charged on.
     */
    private function recalcTax(OperatingExpense $group): void
    {
        $group->forceFill([
            'tax_amount' => round($group->fresh('items')->total() * (float) $group->tax_rate / 100, 4),
        ])->save();
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:255'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'currency' => ['required', 'string', 'size:3'],
            'exchange_rate' => ['nullable', 'numeric', 'min:0'],
            // Which expense account these overheads sit on, and the GST paid on
            // them — the input tax side of the return.
            'account_code' => \App\Models\Account::validationRule(),
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.name' => ['nullable', 'string', 'max:255'],
            'items.*.category' => ['nullable', 'string', 'max:100'],
            'items.*.amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        $data['currency'] = strtoupper($data['currency']);
        $data['exchange_rate'] = (! empty($data['exchange_rate']) && $data['exchange_rate'] > 0) ? $data['exchange_rate'] : 1;
        $data['account_code'] = $data['account_code'] ?? null ?: \App\Models\Account::DEFAULT_EXPENSE;
        $data['tax_rate'] = (float) ($data['tax_rate'] ?? 0);

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
            'account_code' => $g->account_code,
            'account_name' => $g->accountRecord()?->name,
            'gst_code' => $g->gstCode(),
            'tax_rate' => (float) $g->tax_rate,
            'tax_amount' => round((float) $g->tax_amount, 2),
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

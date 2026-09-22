<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\CashToMasterRecord;
use App\Support\DocNumber;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CashToMasterController extends Controller
{
    public function index(Request $request)
    {
        $rows = CashToMasterRecord::with(['customer:id,name,customer_no', 'creator:id,name'])
            ->when($request->query('from'), fn ($q, $from) => $q->whereDate('transaction_date', '>=', $from))
            ->when($request->query('to'), fn ($q, $to) => $q->whereDate('transaction_date', '<=', $to))
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (CashToMasterRecord $record) => $this->present($record));

        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['reference'] = DocNumber::next('CTM');
        $data['created_by'] = $request->user()?->id;
        $record = CashToMasterRecord::create($data);

        return response()->json(['success' => true, 'message' => 'CTM record saved.', 'data' => $this->present($record->load(['customer:id,name,customer_no', 'creator:id,name']))], 201);
    }

    public function update(Request $request, CashToMasterRecord $cashToMasterRecord)
    {
        $cashToMasterRecord->update($this->validated($request));

        return response()->json(['success' => true, 'message' => 'CTM record updated.', 'data' => $this->present($cashToMasterRecord->fresh(['customer:id,name,customer_no', 'creator:id,name']))]);
    }

    public function destroy(CashToMasterRecord $cashToMasterRecord)
    {
        $cashToMasterRecord->delete();

        return response()->json(['success' => true, 'message' => 'CTM record removed.']);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'transaction_date' => ['required', 'date'],
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'vessel' => ['nullable', 'string', 'max:255'],
            'currency' => ['required', 'string', 'size:3'],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'cash_delivered' => ['required', 'numeric', 'min:0'],
            'transit_cash_incoming' => ['required', 'numeric', 'gte:cash_delivered'],
            'transit_cash_outgoing' => ['required', 'numeric', 'min:0'],
            'fee_account_code' => Account::validationRule(),
            'fx_account_code' => Account::validationRule(),
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $data['currency'] = strtoupper($data['currency']);
        $data['exchange_rate'] = (float) ($data['exchange_rate'] ?? 1) ?: 1;
        $data['funds_account_code'] = '2100';
        $data['fee_account_code'] = $data['fee_account_code'] ?? null ?: '4200';
        $data['fx_account_code'] = $data['fx_account_code'] ?? null ?: '5200';

        foreach ([['fee_account_code', Account::INCOME], ['fx_account_code', Account::EXPENSE]] as [$field, $type]) {
            $account = Account::find_by_code($data[$field]);
            if ($account && $account->type !== $type) {
                throw ValidationException::withMessages([
                    $field => $field === 'fee_account_code'
                        ? 'Choose a sales or income account for the CTM fee.'
                        : 'Choose an expense account for the CTM FX or transfer cost.',
                ]);
            }
        }

        return $data;
    }

    private function present(CashToMasterRecord $record): array
    {
        return [
            'id' => $record->id,
            'reference' => $record->reference,
            'transaction_date' => $record->transaction_date?->toDateString(),
            'customer_id' => $record->customer_id,
            'customer_no' => $record->customer?->customer_no,
            'customer_name' => $record->customer?->name,
            'vessel' => $record->vessel,
            'currency' => $record->currency,
            'exchange_rate' => (float) $record->exchange_rate,
            'cash_delivered' => (float) $record->cash_delivered,
            'transit_cash_incoming' => (float) $record->transit_cash_incoming,
            'transit_cash_outgoing' => (float) $record->transit_cash_outgoing,
            'fee_amount' => $record->feeAmount(),
            'fee_percentage' => $record->feePercentage(),
            'fx_expense' => $record->fxExpense(),
            'fx_variance' => $record->fxVariance(),
            'net_profit' => $record->netProfit(),
            'fee_account_code' => $record->fee_account_code,
            'fx_account_code' => $record->fx_account_code,
            'notes' => $record->notes,
            'created_by' => $record->creator?->name,
        ];
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Vendor;
use App\Support\AccountingBooks;
use App\Support\GstCodes;
use App\Support\OpenEntries;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The accounting area — the nine screens that replace the hand-kept workbook.
 *
 * Sales register, purchase register, GST summary, trial balance, balance sheet,
 * income statement, AR ageing, AP ageing and the party numbers. Every one of
 * them reads {@see AccountingBooks}, so no two screens can tell a different
 * story about the same month.
 *
 * Read-only. Nothing in here writes, so no report can damage a document.
 */
class AccountingController extends Controller
{
    /**
     * Which cash account a currency lands in.
     *
     * The chart has one cash account per currency the business banks in. A
     * payment in anything else is still real money, so it is reported under
     * "other" rather than being dropped.
     */
    private const CASH_ACCOUNTS = ['SGD' => '1000', 'USD' => '1010', 'EUR' => '1020'];

    private const RECEIVABLES = '1100';

    private const PAYABLES = '2000';

    private const EQUITY = '3000';

    /* ------------------------------------------------------------------ */
    /*  Shared                                                            */
    /* ------------------------------------------------------------------ */

    /** @return array{0: ?Carbon, 1: ?Carbon} */
    private function range(Request $request): array
    {
        $from = $request->query('from') ? Carbon::parse($request->query('from'))->startOfDay() : null;
        $to = $request->query('to') ? Carbon::parse($request->query('to'))->endOfDay() : null;

        return [$from, $to];
    }

    private function asOf(Request $request): Carbon
    {
        return $request->query('to')
            ? Carbon::parse($request->query('to'))->endOfDay()
            : Carbon::today()->endOfDay();
    }

    private function ok(array $data)
    {
        return response()->json(['success' => true, 'data' => $data]);
    }

    /** Apply a free-text search across the columns a register is searched by. */
    private function search(Collection $rows, ?string $term): Collection
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $rows;
        }

        $needle = mb_strtolower($term);

        return $rows->filter(function (array $r) use ($needle) {
            foreach (['number', 'vendor_invoice_number', 'party_name', 'party_no', 'party_reference',
                'reference', 'vessel', 'account_code', 'account_name', 'gst_code'] as $key) {
                if (str_contains(mb_strtolower((string) ($r[$key] ?? '')), $needle)) {
                    return true;
                }
            }

            return false;
        })->values();
    }

    /* ------------------------------------------------------------------ */
    /*  1. Chart of accounts                                              */
    /* ------------------------------------------------------------------ */

    public function chart()
    {
        $accounts = Account::ordered()->get()->map(fn (Account $a) => [
            'id' => $a->id,
            'code' => $a->code,
            'name' => $a->name,
            'type' => $a->type,
            'type_label' => Account::TYPES[$a->type] ?? $a->type,
            'gst_code' => $a->gst_code,
            'gst_label' => $a->gstLabel(),
            'description' => $a->description,
            'normal_balance' => $a->normalBalance(),
            'statement' => $a->statement(),
            'is_active' => $a->is_active,
        ]);

        return $this->ok([
            'accounts' => $accounts->all(),
            'gst_codes' => GstCodes::options(),
            'defaults' => [
                'sales' => Account::DEFAULT_SALES,
                'sales_standard' => Account::DEFAULT_SALES_STANDARD,
                'purchase' => Account::DEFAULT_PURCHASE,
                'expense' => Account::DEFAULT_EXPENSE,
            ],
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  2. Sales register                                                 */
    /* ------------------------------------------------------------------ */

    public function salesInvoices(Request $request)
    {
        [$from, $to] = $this->range($request);
        $rows = $this->search(AccountingBooks::sales($from, $to), $request->query('q'));

        if ($code = $request->query('account_code')) {
            $rows = $rows->where('account_code', $code)->values();
        }

        return $this->ok([
            'rows' => $rows->all(),
            'totals' => $this->registerTotals($rows) + [
                'invoices' => $rows->where('kind', 'invoice')->count(),
                'credit_notes' => $rows->where('kind', 'credit_memo')->count(),
                'outstanding' => round($rows->sum('outstanding'), 2),
                'settled' => round($rows->sum('settled'), 2),
            ],
            'by_account' => $this->groupByAccount($rows),
            'unclassified' => $rows->where('unclassified', true)->count(),
        ] + AccountingBooks::currencyNote($rows));
    }

    /* ------------------------------------------------------------------ */
    /*  3. Purchase register                                              */
    /* ------------------------------------------------------------------ */

    public function purchaseInvoices(Request $request)
    {
        [$from, $to] = $this->range($request);
        $rows = $this->search(AccountingBooks::purchases($from, $to), $request->query('q'));

        if ($code = $request->query('account_code')) {
            $rows = $rows->where('account_code', $code)->values();
        }

        return $this->ok([
            'rows' => $rows->all(),
            'totals' => $this->registerTotals($rows) + [
                'orders' => $rows->where('kind', 'purchase_order')->count(),
                'expenses' => $rows->where('kind', 'operating_expense')->count(),
                'payroll' => $rows->where('kind', 'payroll')->count(),
                'outstanding' => round($rows->sum('outstanding'), 2),
                'settled' => round($rows->sum('settled'), 2),
                'job_expenses' => round($rows->sum(fn ($r) => (float) ($r['expenses'] ?? 0)), 2),
            ],
            'by_account' => $this->groupByAccount($rows),
            'unclassified' => $rows->where('unclassified', true)->count(),
        ] + AccountingBooks::currencyNote($rows));
    }

    private function registerTotals(Collection $rows): array
    {
        return [
            'count' => $rows->count(),
            'net' => round($rows->sum('net'), 2),
            'tax' => round($rows->sum('tax_amount'), 2),
            'gross' => round($rows->sum('gross'), 2),
            'base_net' => round($rows->sum('base_net'), 2),
            'base_tax' => round($rows->sum('base_tax'), 2),
            'base_gross' => round($rows->sum('base_gross'), 2),
        ];
    }

    /** Subtotals per account, so a register footer explains where a total came from. */
    private function groupByAccount(Collection $rows): array
    {
        return $rows->groupBy(fn ($r) => $r['account_code'] ?? '—')
            ->map(fn (Collection $g, $code) => [
                'account_code' => $code === '—' ? null : $code,
                'account_name' => $g->first()['account_name'] ?? 'Unclassified',
                'gst_code' => $g->first()['gst_code'],
                'gst_label' => $g->first()['gst_label'],
                'count' => $g->count(),
                'net' => round($g->sum('net'), 2),
                'tax' => round($g->sum('tax_amount'), 2),
                'gross' => round($g->sum('gross'), 2),
            ])
            ->sortBy('account_code')
            ->values()
            ->all();
    }

    /* ------------------------------------------------------------------ */
    /*  4. GST summary                                                    */
    /* ------------------------------------------------------------------ */

    /**
     * The GST return: output tax from sales, input tax from purchases, and the
     * IRAS F5 boxes those two produce.
     *
     * Every box is built from the account each document carries, so Box 2
     * cannot read zero while zero-rated sales exist — the field that says
     * "4100" is the field that says "zero-rated". Anything on a code we do not
     * recognise is reported in its own bucket and left OUT of the boxes, so an
     * unclassified amount is visible rather than quietly mis-declared.
     */
    public function gstSummary(Request $request)
    {
        [$from, $to] = $this->range($request);

        $sales = AccountingBooks::sales($from, $to);
        $purchases = AccountingBooks::purchases($from, $to);

        $outputSide = $this->gstSide($sales, 'sales');
        $inputSide = $this->gstSide($purchases, 'purchase');

        $box = fn (array $side, int $n) => round(
            collect($side['rows'])->where('f5_box', $n)->sum('net'), 2
        );

        $box1 = $box($outputSide, 1);
        $box2 = $box($outputSide, 2);
        $box3 = $box($outputSide, 3);
        $box5 = $box($inputSide, 5);
        $box6 = $outputSide['tax'];
        $box7 = $inputSide['tax'];

        return $this->ok([
            'period' => [
                'from' => $from?->toDateString(),
                'to' => $to?->toDateString(),
            ],
            // "GST from sales" and "GST from purchases" — the two halves the
            // client thinks of this report as.
            'output' => $outputSide,
            'input' => $inputSide,
            'boxes' => [
                ['box' => 1, 'label' => 'Total value of standard-rated supplies', 'amount' => $box1],
                ['box' => 2, 'label' => 'Total value of zero-rated supplies', 'amount' => $box2],
                ['box' => 3, 'label' => 'Total value of exempt supplies', 'amount' => $box3],
                ['box' => 4, 'label' => 'Total value of (1) + (2) + (3)', 'amount' => round($box1 + $box2 + $box3, 2), 'computed' => true],
                ['box' => 5, 'label' => 'Total value of taxable purchases', 'amount' => $box5],
                ['box' => 6, 'label' => 'Output tax due', 'amount' => $box6],
                ['box' => 7, 'label' => 'Input tax and refunds claimed', 'amount' => $box7],
                ['box' => 8, 'label' => 'Net GST payable / (refundable)', 'amount' => round($box6 - $box7, 2), 'computed' => true],
            ],
            'net_gst' => round($box6 - $box7, 2),
            'payable' => $box6 >= $box7,
            'warnings' => array_values(array_filter([
                $outputSide['unclassified']['count'] ? sprintf(
                    '%d sales document(s) totalling %s are on an account outside the chart and are NOT in any box.',
                    $outputSide['unclassified']['count'],
                    number_format($outputSide['unclassified']['net'], 2)
                ) : null,
                $inputSide['unclassified']['count'] ? sprintf(
                    '%d purchase document(s) totalling %s are on an account outside the chart and are NOT in any box.',
                    $inputSide['unclassified']['count'],
                    number_format($inputSide['unclassified']['net'], 2)
                ) : null,
            ])),
        ] + AccountingBooks::currencyNote($sales->concat($purchases)));
    }

    /**
     * One half of the return — grouped by GST code, with the documents behind
     * each group kept alongside so the screen can drill in without a second
     * request.
     */
    private function gstSide(Collection $rows, string $side): array
    {
        $classified = $rows->where('unclassified', false);
        $unclassified = $rows->where('unclassified', true);

        $groups = $classified->groupBy('gst_code')
            ->map(fn (Collection $g, $code) => [
                'gst_code' => $code,
                'gst_label' => GstCodes::label($code),
                'description' => GstCodes::DESCRIPTIONS[$code] ?? null,
                'f5_box' => $side === 'sales' ? GstCodes::salesBox($code) : GstCodes::purchaseBox($code),
                'count' => $g->count(),
                'net' => round($g->sum('net'), 2),
                'tax' => round($g->sum('tax_amount'), 2),
                'gross' => round($g->sum('gross'), 2),
                'accounts' => $g->groupBy('account_code')->map(fn ($a, $code) => [
                    'account_code' => $code,
                    'account_name' => $a->first()['account_name'],
                    'count' => $a->count(),
                    'net' => round($a->sum('net'), 2),
                    'tax' => round($a->sum('tax_amount'), 2),
                ])->values()->all(),
            ])
            // The return reads in box order; codes on no box come last.
            ->sortBy(fn ($g) => $g['f5_box'] ?? 99)
            ->values();

        return [
            'side' => $side,
            'groups' => $groups->all(),
            'rows' => $classified->values()->all(),
            'count' => $classified->count(),
            'net' => round($classified->sum('net'), 2),
            // Only standard-rated documents actually carry tax; summing the
            // column is safe because everything else records zero.
            'tax' => round($classified->sum('tax_amount'), 2),
            'unclassified' => [
                'count' => $unclassified->count(),
                'net' => round($unclassified->sum('net'), 2),
                'tax' => round($unclassified->sum('tax_amount'), 2),
                'rows' => $unclassified->values()->all(),
            ],
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  5. Income statement                                               */
    /* ------------------------------------------------------------------ */

    public function incomeStatement(Request $request)
    {
        [$from, $to] = $this->range($request);
        $figures = $this->profitAndLoss($from, $to);

        return $this->ok($figures + [
            'period' => ['from' => $from?->toDateString(), 'to' => $to?->toDateString()],
        ]);
    }

    /**
     * Revenue less cost of sales less overheads.
     *
     * Revenue is taken net of GST — tax collected on the government's behalf was
     * never income. Cost of sales is what we bought for jobs; operating
     * expenses are the overheads. Shared with the trial balance and the balance
     * sheet so all three agree by construction.
     */
    private function profitAndLoss(?Carbon $from, ?Carbon $to): array
    {
        $sales = AccountingBooks::sales($from, $to);
        $purchases = AccountingBooks::purchases($from, $to);

        $costOfSales = $purchases->where('kind', 'purchase_order');
        // Wages are an overhead like any other. Matching on "not a purchase
        // order" rather than naming each kind, so a cost added later cannot
        // quietly appear in the register but be missed by the profit.
        $overheads = $purchases->where('kind', '!=', 'purchase_order');

        // Per-job expenses ride on the purchase order rather than on a document
        // of their own, so they are cost of sales too.
        $jobExpenses = round($costOfSales->sum(fn ($r) => (float) ($r['expenses'] ?? 0)), 2);

        $revenue = round($sales->sum('net'), 2);
        $cogs = round($costOfSales->sum('net'), 2) + $jobExpenses;
        $overhead = round($overheads->sum('net'), 2);
        $grossProfit = round($revenue - $cogs, 2);

        return [
            'revenue' => [
                'total' => $revenue,
                'by_account' => $this->groupByAccount($sales),
            ],
            'cost_of_sales' => [
                'total' => round($cogs, 2),
                'purchases' => round($costOfSales->sum('net'), 2),
                'job_expenses' => $jobExpenses,
                'by_account' => $this->groupByAccount($costOfSales),
            ],
            'operating_expenses' => [
                'total' => $overhead,
                'by_account' => $this->groupByAccount($overheads),
            ],
            'gross_profit' => $grossProfit,
            'gross_margin' => $revenue != 0.0 ? round($grossProfit / $revenue * 100, 1) : null,
            'net_profit' => round($grossProfit - $overhead, 2),
            'base_currency' => AccountingBooks::baseCurrency(),
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  6. Trial balance                                                  */
    /* ------------------------------------------------------------------ */

    /**
     * Every account with its debit or credit.
     *
     * Balance-sheet accounts are stated as at the end date; income and expense
     * accounts are stated for the period. That is the ordinary presentation,
     * and it is why the two halves of the report use different date rules.
     */
    public function trialBalance(Request $request)
    {
        [$from, $to] = $this->range($request);
        $asOf = $this->asOf($request);

        $pl = $this->profitAndLoss($from, $to);
        $positions = $this->positionsAt($asOf);

        $movements = [];

        // Balance sheet side, as at the end date.
        foreach ($positions['cash'] as $code => $amount) {
            $movements[$code] = $amount;
        }
        $movements[self::RECEIVABLES] = $positions['receivables'];
        $movements[self::PAYABLES] = -$positions['payables'];
        $movements[self::EQUITY] = -$positions['equity'];

        // Income statement side, for the period. Income is credit-normal, so a
        // sale is a negative movement in this signed view; expenses are debits.
        foreach ($pl['revenue']['by_account'] as $g) {
            if ($g['account_code']) {
                $movements[$g['account_code']] = ($movements[$g['account_code']] ?? 0) - $g['net'];
            }
        }
        foreach ([$pl['cost_of_sales']['by_account'], $pl['operating_expenses']['by_account']] as $set) {
            foreach ($set as $g) {
                if ($g['account_code']) {
                    $movements[$g['account_code']] = ($movements[$g['account_code']] ?? 0) + $g['net'];
                }
            }
        }
        // Job expenses have no document of their own; they belong with cost of sales.
        if ($pl['cost_of_sales']['job_expenses'] != 0.0) {
            $movements[Account::DEFAULT_PURCHASE] =
                ($movements[Account::DEFAULT_PURCHASE] ?? 0) + $pl['cost_of_sales']['job_expenses'];
        }

        $rows = Account::ordered()->get()->map(function (Account $a) use ($movements) {
            $amount = round((float) ($movements[$a->code] ?? 0), 2);

            return [
                'code' => $a->code,
                'name' => $a->name,
                'type' => $a->type,
                'type_label' => Account::TYPES[$a->type] ?? $a->type,
                'gst_code' => $a->gst_code,
                'statement' => $a->statement(),
                'normal_balance' => $a->normalBalance(),
                // A positive signed movement is a debit; negative is a credit.
                'debit' => $amount > 0 ? $amount : 0.0,
                'credit' => $amount < 0 ? round(-$amount, 2) : 0.0,
                'balance' => $amount,
            ];
        });

        // Two real liabilities with no account on the chart — see gstPayableAt()
        // and accruedExpensesAt(). Shown last and flagged, so it is obvious they
        // are derived rather than posted.
        foreach ([
            ['GST payable to IRAS (derived, not on chart)', $positions['gst_payable']],
            ['Accrued expenses — overheads not paid (derived)', $positions['accrued_expenses']],
        ] as [$name, $amount]) {
            if (abs($amount) <= 0.005) {
                continue;
            }

            $rows->push([
                'code' => '—',
                'name' => $name,
                'type' => Account::LIABILITY,
                'type_label' => 'Liability',
                'gst_code' => null,
                'statement' => 'balance_sheet',
                'normal_balance' => 'credit',
                'debit' => $amount < 0 ? round(-$amount, 2) : 0.0,
                'credit' => $amount > 0 ? round($amount, 2) : 0.0,
                'balance' => round(-$amount, 2),
                'off_chart' => true,
            ]);
        }

        $debit = round($rows->sum('debit'), 2);
        $credit = round($rows->sum('credit'), 2);

        return $this->ok([
            'period' => ['from' => $from?->toDateString(), 'to' => $to?->toDateString()],
            'as_of' => $asOf->toDateString(),
            'rows' => $rows->all(),
            'totals' => [
                'debit' => $debit,
                'credit' => $credit,
                'difference' => round($debit - $credit, 2),
                'balanced' => abs($debit - $credit) < 0.01,
            ],
            'note' => $positions['note'],
            'base_currency' => AccountingBooks::baseCurrency(),
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  7. Balance sheet                                                  */
    /* ------------------------------------------------------------------ */

    public function balanceSheet(Request $request)
    {
        $asOf = $this->asOf($request);
        $positions = $this->positionsAt($asOf);

        // Retained earnings are everything the business has earned up to the
        // date, so the profit is taken from the start of the records, not from
        // the report's own from-date.
        $pl = $this->profitAndLoss(null, $asOf);
        $retained = $pl['net_profit'];

        $cash = collect($positions['cash']);
        $assets = round($cash->sum() + $positions['receivables'], 2);
        $liabilities = round($positions['payables'] + $positions['gst_payable'] + $positions['accrued_expenses'], 2);
        $equity = round($positions['equity'] + $retained, 2);

        $accountRow = function (string $code, float $amount) {
            $a = Account::find_by_code($code);

            return [
                'code' => $code,
                'name' => $a?->name ?? $code,
                'amount' => round($amount, 2),
            ];
        };

        return $this->ok([
            'as_of' => $asOf->toDateString(),
            'assets' => [
                'cash' => $cash->map(fn ($amt, $code) => $accountRow($code, $amt))->values()->all(),
                'cash_total' => round($cash->sum(), 2),
                'receivables' => $accountRow(self::RECEIVABLES, $positions['receivables']),
                'total' => $assets,
            ],
            'liabilities' => [
                'payables' => $accountRow(self::PAYABLES, $positions['payables']),
                'gst_payable' => [
                    'code' => null,
                    'name' => 'GST payable to IRAS',
                    'amount' => $positions['gst_payable'],
                    'off_chart' => true,
                ],
                'accrued_expenses' => [
                    'code' => null,
                    'name' => 'Accrued expenses — overheads not paid',
                    'amount' => $positions['accrued_expenses'],
                    'off_chart' => true,
                ],
                'total' => $liabilities,
            ],
            'equity' => [
                'owner' => $accountRow(self::EQUITY, $positions['equity']),
                'retained_earnings' => round($retained, 2),
                'total' => $equity,
            ],
            'check' => [
                'assets' => $assets,
                'liabilities_and_equity' => round($liabilities + $equity, 2),
                'difference' => round($assets - $liabilities - $equity, 2),
                'balanced' => abs($assets - $liabilities - $equity) < 0.01,
            ],
            'note' => $positions['note'],
            'base_currency' => AccountingBooks::baseCurrency(),
        ]);
    }

    /**
     * Cash, receivables, payables and equity as at a date.
     *
     * Cash is the running total of every receipt less every payment recorded up
     * to the date, split by the currency's cash account. That is a true balance
     * ONLY if the business started from zero when it began recording payments
     * here — which is the position Dru chose: start now, load no history. The
     * note travels with the figures so no screen presents them as more than
     * they are.
     */
    private function positionsAt(Carbon $asOf): array
    {
        $payments = Payment::query()
            ->whereDate('payment_date', '<=', $asOf)
            ->get(['direction', 'currency', 'amount']);

        $cash = [];
        foreach (array_values(self::CASH_ACCOUNTS) as $code) {
            $cash[$code] = 0.0;
        }

        $otherCurrency = 0.0;
        foreach ($payments as $p) {
            $signed = ((float) $p->amount) * ($p->direction === 'in' ? 1 : -1);
            $code = self::CASH_ACCOUNTS[strtoupper((string) $p->currency)] ?? null;

            if ($code) {
                $cash[$code] += $signed;
            } else {
                $otherCurrency += $signed;
            }
        }

        $cash = array_map(fn ($v) => round($v, 2), $cash);

        $receivables = round($this->openTotal('customer', $asOf), 2);
        // Payables are taken GROSS. OpenEntries values a purchase order at
        // receipt-or-awarded cost, which is net of the GST the vendor charged,
        // while a receivable is the invoice's grand total, which includes it.
        // Left as-is the two sides of the balance sheet are measured
        // differently and it cannot balance.
        $payables = round($this->openTotal('vendor', $asOf) + $this->unpaidPurchaseTaxAt($asOf), 2);
        $accrued = round($this->accruedExpensesAt($asOf), 2);

        $note = 'Cash is the running total of receipts less payments recorded in the system, '
            .'not a bank balance — no opening balance has been entered. Owner\'s equity is nil '
            .'for the same reason. Any difference on the balance check is the unrecorded '
            .'opening position.';

        if (abs($otherCurrency) > 0.01) {
            $note .= sprintf(' %s of payments were in a currency with no cash account and are excluded.', number_format($otherCurrency, 2));
        }

        return [
            'cash' => $cash,
            'receivables' => $receivables,
            'payables' => $payables,
            'accrued_expenses' => $accrued,
            'equity' => 0.0,
            'gst_payable' => $this->gstPayableAt($asOf),
            'note' => $note,
        ];
    }

    /**
     * GST owed to IRAS as at a date: all output tax charged, less all input tax
     * claimable, from the beginning of the records.
     *
     * This is a real liability with NO account on the ten-code chart. Without
     * it the books cannot balance: a standard-rated invoice puts the GST into
     * trade receivables (customers are billed the tax) while revenue is booked
     * net of it, so the credit has nowhere to go and the trial balance is out
     * by exactly the tax.
     *
     * So it is DERIVED and shown as its own reconciling line, flagged as off
     * chart. Adding an eleventh account would balance the books just as well,
     * and is the tidier long-term answer — but the chart is the client's to
     * change, not ours.
     */
    private function gstPayableAt(Carbon $asOf): float
    {
        $output = AccountingBooks::sales(null, $asOf)
            ->where('unclassified', false)->sum('tax_amount');
        $input = AccountingBooks::purchases(null, $asOf)
            ->where('unclassified', false)->sum('tax_amount');

        return round($output - $input, 2);
    }

    /**
     * The GST sitting inside purchase orders that are not yet settled.
     *
     * Apportioned by how much of each order is still outstanding, so a
     * part-paid order contributes only the tax on the part still owed.
     */
    private function unpaidPurchaseTaxAt(Carbon $asOf): float
    {
        $book = OpenEntries::build('vendor', $asOf, false);

        $entries = collect($book['parties'])->flatMap(fn ($p) => $p['entries'])
            ->filter(fn ($e) => $e['kind'] === 'po' && (float) $e['amount'] > 0);

        if ($entries->isEmpty()) {
            return 0.0;
        }

        $tax = \App\Models\PurchaseOrder::whereIn('id', $entries->pluck('id'))
            ->pluck('tax_amount', 'id');

        return (float) $entries->sum(
            fn ($e) => (float) ($tax[$e['id']] ?? 0) * ((float) $e['outstanding'] / (float) $e['amount'])
        );
    }

    /**
     * Overheads recorded as a cost but with nothing on the other side.
     *
     * An operating expense has no payment and no payable in this system — it is
     * simply a note that money was spent. In double entry a cost must be either
     * paid or owed, so until an expense can be marked paid, every one of them is
     * an accrued liability. Without this the balance sheet is out by exactly the
     * overheads recorded, which is what the worked example showed.
     *
     * Taken gross, because what is owed includes the GST on it.
     */
    private function accruedExpensesAt(Carbon $asOf): float
    {
        $overheads = (float) \App\Models\OperatingExpense::with('items')
            ->whereDate('period_start', '<=', $asOf)
            ->get()
            ->sum(fn ($g) => $g->total() + (float) $g->tax_amount);

        // Payroll is the same story: a cost recorded with no payment against
        // it. Left out, the balance sheet goes out by the whole wage bill the
        // moment a month is finalised.
        $wages = (float) AccountingBooks::payroll(null, $asOf)->sum('net');

        return $overheads + $wages;
    }

    /** Total still outstanding across every party of a kind, as at a date. */
    private function openTotal(string $type, Carbon $asOf): float
    {
        $book = OpenEntries::build($type, $asOf, false);

        return collect($book['parties'])->sum(
            fn ($p) => collect($p['entries'])->sum('outstanding')
        );
    }

    /* ------------------------------------------------------------------ */
    /*  8 & 9. AR / AP ageing                                             */
    /* ------------------------------------------------------------------ */

    public function arAgeing(Request $request)
    {
        return $this->ok($this->ageing('customer', $request));
    }

    public function apAgeing(Request $request)
    {
        return $this->ok($this->ageing('vendor', $request));
    }

    /**
     * Standard 30/60/90 ageing, bucketed from each document's due date.
     *
     * Built on {@see OpenEntries}, which already knows how to value a document
     * as at a date and how far past due it is — so the ageing and the statement
     * of account can never disagree about what a customer owes.
     */
    private function ageing(string $type, Request $request): array
    {
        $asOf = $this->asOf($request);
        $book = OpenEntries::build($type, $asOf, false);

        $bucketOf = function (int $days): string {
            if ($days <= 0) {
                return 'current';
            }
            if ($days <= 30) {
                return 'd1_30';
            }
            if ($days <= 60) {
                return 'd31_60';
            }
            if ($days <= 90) {
                return 'd61_90';
            }

            return 'd90_plus';
        };

        $empty = ['current' => 0.0, 'd1_30' => 0.0, 'd31_60' => 0.0, 'd61_90' => 0.0, 'd90_plus' => 0.0];
        $grand = $empty;

        $parties = collect($book['parties'])->map(function ($p) use ($bucketOf, $empty, &$grand) {
            $buckets = $empty;
            $entries = collect($p['entries'])->map(function ($e) use ($bucketOf, &$buckets) {
                $bucket = $bucketOf((int) $e['overdue_days']);
                $buckets[$bucket] += (float) $e['outstanding'];

                return $e + ['bucket' => $bucket];
            });

            foreach ($buckets as $k => $v) {
                $buckets[$k] = round($v, 2);
                $grand[$k] += $v;
            }

            return [
                'id' => $p['id'],
                'name' => $p['name'],
                'email' => $p['email'] ?? null,
                'entries' => $entries->all(),
                'buckets' => $buckets,
                'total' => round(array_sum($buckets), 2),
                'oldest_days' => (int) $entries->max('overdue_days'),
            ];
        })->sortByDesc('total')->values();

        // Party numbers are what the accounting side identifies people by, so
        // the ageing carries them too. One query, not one per party.
        $model = $type === 'customer' ? Customer::class : Vendor::class;
        $column = $type === 'customer' ? 'customer_no' : 'vendor_no';
        $numbers = $model::whereIn('id', $parties->pluck('id'))->pluck($column, 'id');

        $parties = $parties->map(fn ($p) => $p + ['party_no' => $numbers[$p['id']] ?? null]);

        return [
            'type' => $type,
            'as_of' => $asOf->toDateString(),
            'parties' => $parties->all(),
            'buckets' => array_map(fn ($v) => round($v, 2), $grand),
            'total' => round(array_sum($grand), 2),
            'party_count' => $parties->count(),
            'account' => $type === 'customer' ? self::RECEIVABLES : self::PAYABLES,
            'base_currency' => AccountingBooks::baseCurrency(),
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  10. Party numbers                                                 */
    /* ------------------------------------------------------------------ */

    /**
     * The customer and vendor number registers — the workbook's "Customer
     * Numbers" sheet, both sides.
     */
    public function parties(Request $request)
    {
        $type = $request->query('type') === 'vendor' ? 'vendor' : 'customer';
        $term = trim((string) $request->query('q'));
        $isCustomer = $type === 'customer';

        $column = $isCustomer ? 'customer_no' : 'vendor_no';
        $query = ($isCustomer ? Customer::class : Vendor::class)::query()
            ->when($term !== '', function ($q) use ($term, $column) {
                $like = '%'.$term.'%';
                $q->where(fn ($w) => $w->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere($column, 'like', $like));
            })
            ->when($request->filled('active'), fn ($q) => $q->where('is_active', $request->boolean('active')))
            // Unnumbered parties first so a gap is impossible to miss.
            ->orderByRaw("$column is null desc")
            ->orderBy($column);

        $perPage = min(max((int) $request->query('per_page', 50), 1), 200);
        $page = $query->paginate($perPage);

        $rows = collect($page->items())->map(fn ($p) => [
            'id' => $p->id,
            'party_no' => $p->{$column},
            'name' => $p->name,
            'email' => $p->email,
            'phone' => $p->phone,
            'address' => $p->address,
            'currency' => $p->currency,
            'is_active' => (bool) $p->is_active,
            'contact_name' => $isCustomer ? null : $p->contact_name,
            'created_at' => optional($p->created_at)->toDateString(),
        ]);

        return $this->ok([
            'type' => $type,
            'rows' => $rows->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
            'series' => [
                'starts_at' => $isCustomer ? 10001 : 20001,
                'issued' => ($isCustomer ? Customer::class : Vendor::class)::whereNotNull($column)->count(),
                'unnumbered' => ($isCustomer ? Customer::class : Vendor::class)::whereNull($column)->count(),
            ],
        ]);
    }
}

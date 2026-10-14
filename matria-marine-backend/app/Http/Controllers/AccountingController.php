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

    /**
     * The books, loaded once per request.
     *
     * A balance sheet asks for them five times over — the profit, the GST
     * liability, the accruals, the client funds held — and each ask was a fresh
     * pass over every invoice and order in the period. The controller is built
     * per request, so holding them here cannot go stale: nothing in this class
     * writes, and the next request starts with an empty one.
     *
     * @var array<string, Collection>
     */
    private array $books = [];

    /* ------------------------------------------------------------------ */
    /*  Shared                                                            */
    /* ------------------------------------------------------------------ */

    private function sales(?Carbon $from, ?Carbon $to): Collection
    {
        return $this->books['sales|'.$from?->toDateString().'|'.$to?->toDateString()]
            ??= AccountingBooks::sales($from, $to);
    }

    private function purchases(?Carbon $from, ?Carbon $to): Collection
    {
        return $this->books['purchases|'.$from?->toDateString().'|'.$to?->toDateString()]
            ??= AccountingBooks::purchases($from, $to);
    }

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
            // Set on a sub-account, so pickers and statements can show it
            // beneath the account it breaks down.
            'parent_code' => $a->parent_code,
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
        $rows = $this->search($this->sales($from, $to), $request->query('q'));

        if ($code = $request->query('account_code')) {
            $rows = $rows->where('account_code', $code)->values();
        }

        return $this->ok([
            'rows' => $rows->all(),
            'totals' => $this->registerTotals($rows) + [
                'invoices' => $rows->where('kind', 'invoice')->count(),
                'credit_notes' => $rows->whereIn('kind', ['credit_memo', 'vendor_credit_note'])->count(),
                'vendor_credit_notes' => $rows->where('kind', 'vendor_credit_note')->count(),
                // Converted, like everything else in a register footer. These
                // two were the figures the accountant circled: an AED 536,185
                // invoice was adding its face value straight into a Singapore
                // dollar outstanding balance.
                'outstanding' => AccountingBooks::baseSum($rows, 'base_outstanding'),
                'settled' => AccountingBooks::baseSum($rows, 'base_settled'),
            ],
            'by_account' => $this->groupByAccount($rows),
        ] + $this->problems($rows) + AccountingBooks::currencyNote($rows));
    }

    /* ------------------------------------------------------------------ */
    /*  3. Purchase register                                              */
    /* ------------------------------------------------------------------ */

    public function purchaseInvoices(Request $request)
    {
        [$from, $to] = $this->range($request);
        $rows = $this->search($this->purchases($from, $to), $request->query('q'));

        if ($code = $request->query('account_code')) {
            $rows = $rows->where('account_code', $code)->values();
        }

        return $this->ok([
            'rows' => $rows->all(),
            'totals' => $this->registerTotals($rows) + [
                'orders' => $rows->where('kind', 'purchase_order')->count(),
                'expenses' => $rows->where('kind', 'operating_expense')->count(),
                'payroll' => $rows->where('kind', 'payroll')->count(),
                'outstanding' => AccountingBooks::baseSum($rows, 'base_outstanding'),
                'settled' => AccountingBooks::baseSum($rows, 'base_settled'),
                'job_expenses' => AccountingBooks::baseSum($rows, 'base_expenses'),
            ],
            'by_account' => $this->groupByAccount($rows),
        ] + $this->problems($rows) + AccountingBooks::currencyNote($rows));
    }

    /**
     * The documents standing between these figures and a complete set.
     *
     * Each one is NAMED, not counted. A register that says "6 purchase
     * documents are on an account outside the chart" has told somebody there is
     * a problem and nothing about which six — and hunting them through the list
     * screens is how they stay unfixed for a year.
     */
    private function problems(Collection $rows): array
    {
        $unclassified = AccountingBooks::unclassified($rows);
        $assumedPaid = AccountingBooks::assumedPaid($rows);

        return [
            // Count kept at the old key so nothing that reads it breaks; the
            // documents themselves are beside it.
            'unclassified' => $unclassified->count(),
            'unclassified_rows' => AccountingBooks::identify($unclassified)->all(),
            'unclassified_total' => AccountingBooks::baseSum($unclassified, 'base_net'),
            // Ticked paid with nothing recorded against them. This money is in
            // neither receivables nor cash, which is most of why the balance
            // sheet does not balance.
            'assumed_paid' => AccountingBooks::identify($assumedPaid)->all(),
            'assumed_paid_total' => AccountingBooks::baseSum($assumedPaid, 'base_unrecorded'),
        ];
    }

    /**
     * The foot of a register — every figure in the reporting currency.
     *
     * There is no face-value total here and there must never be one. The rows
     * above hold AED, EUR, SGD and USD in the same column, so `net` and `billed`
     * below are the CONVERTED sums despite their plain names: a caller asking a
     * register for its total can only sensibly be asking for one it can add to
     * something else. The `base_` keys are the same numbers under the name the
     * screens use for a converted column.
     */
    private function registerTotals(Collection $rows): array
    {
        $base = fn (string $field) => AccountingBooks::baseSum($rows, $field);

        return [
            'count' => $rows->count(),
            'net' => $base('base_net'),
            'tax' => $base('base_tax'),
            'gross' => $base('base_gross'),
            // What was invoiced, against the part of it that was never a sale.
            // Equal to gross unless something passed through — a Cash to Master
            // principal collected for a vessel master, say.
            'billed' => $base('base_billed_gross'),
            'pass_through' => $base('base_pass_through'),
            'base_net' => $base('base_net'),
            'base_tax' => $base('base_tax'),
            'base_gross' => $base('base_gross'),
            'base_billed' => $base('base_billed_gross'),
            'base_pass_through' => $base('base_pass_through'),
            // Face value, kept ONLY so a screen can say "these rows are in four
            // currencies" — never to be presented as a total. Named so that
            // reading it as money is a deliberate act.
            'face_value_do_not_total' => round($rows->sum('gross'), 2),
            // A row with no rate contributes nothing to the converted totals,
            // which makes those totals understated rather than wrong. The
            // screen has to say so, or an incomplete figure reads as a
            // complete one — so the documents still waiting for a rate are
            // named here rather than merely counted.
            'unrated' => \App\Support\AccountingBooks::unrated($rows)
                ->map(fn ($r) => [
                    'id' => $r['id'] ?? null,
                    'kind' => $r['kind'] ?? null,
                    'number' => $r['number'] ?? null,
                    'currency' => $r['currency'] ?? null,
                    'net' => $r['net'] ?? null,
                ])
                ->all(),
        ];
    }

    /**
     * Subtotals per account, so a register footer explains where a total came
     * from — and so the income statement and the trial balance have something
     * to be built out of.
     *
     * Converted, which matters more here than anywhere. These subtotals are not
     * only displayed: the trial balance posts them to accounts and the income
     * statement adds them into revenue and cost. At face value they put dirhams
     * into a Singapore-dollar account balance, which is exactly what "sa trial
     * balance mali japon ang total, wala siya na convert" was pointing at.
     */
    private function groupByAccount(Collection $rows): array
    {
        return $rows->groupBy(fn ($r) => $r['account_code'] ?? '—')
            ->map(function (Collection $g, $code) {
                $account = $code === '—' ? null : \App\Models\Account::find_by_code($code);

                return [
                    'account_code' => $code === '—' ? null : $code,
                    'account_name' => $g->first()['account_name'] ?? 'Unclassified',
                    // Which account this rolls into on a statement. 5100-01
                    // Salaries reports under 5100 Operating Expenses; an
                    // account with no parent answers for itself.
                    'parent_code' => $account?->parent_code,
                    'parent_name' => $account?->parent_code
                        ? \App\Models\Account::find_by_code($account->parent_code)?->name
                        : null,
                    'gst_code' => $g->first()['gst_code'],
                    'gst_label' => $g->first()['gst_label'],
                    'count' => $g->count(),
                    'net' => AccountingBooks::baseSum($g, 'base_net'),
                    'tax' => AccountingBooks::baseSum($g, 'base_tax'),
                    'gross' => AccountingBooks::baseSum($g, 'base_gross'),
                    'currency' => AccountingBooks::baseCurrency(),
                    // Documents on this account that had no rate and so are not
                    // in the figures above. A subtotal that is short by a
                    // document has to say which one.
                    'unrated' => AccountingBooks::unrated($g)->count(),
                ];
            })
            // Sub-accounts sort directly beneath their parent, because "5100"
            // sorts before "5100-01" as text anyway.
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

        $sales = $this->sales($from, $to);
        $purchases = $this->purchases($from, $to);

        // Vendor credits are income, but are not supplies made to customers.
        // They belong in the Sales register and income statement, never F5.
        $outputSide = $this->gstSide(
            $sales->where('kind', '!=', 'vendor_credit_note')->values(),
            'sales'
        );
        $inputSide = $this->gstSide($purchases, 'purchase');

        // Every box in the reporting currency, because that is the one IRAS
        // takes the return in. A box built from face values declares a sum of
        // dirhams and dollars as a Singapore-dollar supply.
        $box = fn (array $side, int $n) => AccountingBooks::baseSum(
            collect($side['rows'])->where('f5_box', $n), 'base_net'
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
            // Named, so the documents keeping GST out of the boxes can be
            // opened and fixed rather than merely counted.
            'unclassified_rows' => AccountingBooks::identify(
                AccountingBooks::unclassified($sales->concat($purchases))
            )->all(),
            // What the unclassified purchases are holding back from Box 7. On
            // the live books this was 379.71 of input tax going unclaimed.
            'unclaimed_input_tax' => AccountingBooks::baseSum(
                AccountingBooks::unclassified($purchases), 'base_tax'
            ),
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
                // Not in any box either, for a different reason: the boxes are
                // in SGD and these have no rate to get there with.
                count($outputSide['unrated']) + count($inputSide['unrated']) ? sprintf(
                    '%d document(s) have no exchange rate and are NOT in any box — %s. Convert them before filing.',
                    count($outputSide['unrated']) + count($inputSide['unrated']),
                    collect($outputSide['unrated'])->concat($inputSide['unrated'])
                        ->map(fn ($r) => $r['number'].' ('.$r['currency'].')')->take(8)->implode(', ')
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
                'net' => AccountingBooks::baseSum($g, 'base_net'),
                'tax' => AccountingBooks::baseSum($g, 'base_tax'),
                'gross' => AccountingBooks::baseSum($g, 'base_gross'),
                'unrated' => AccountingBooks::unrated($g)->count(),
                'accounts' => $g->groupBy('account_code')->map(fn ($a, $code) => [
                    'account_code' => $code,
                    'account_name' => $a->first()['account_name'],
                    'count' => $a->count(),
                    'net' => AccountingBooks::baseSum($a, 'base_net'),
                    'tax' => AccountingBooks::baseSum($a, 'base_tax'),
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
            'base_currency' => AccountingBooks::baseCurrency(),
            'net' => AccountingBooks::baseSum($classified, 'base_net'),
            // Only standard-rated documents actually carry tax; summing the
            // column is safe because everything else records zero.
            'tax' => AccountingBooks::baseSum($classified, 'base_tax'),
            // A document with no rate is not in any box. On a filed return that
            // is an understatement, so it is reported rather than left to be
            // noticed.
            'unrated' => AccountingBooks::unrated($classified)
                ->map(fn ($r) => [
                    'kind' => $r['kind'] ?? null,
                    'number' => $r['number'] ?? null,
                    'currency' => $r['currency'] ?? null,
                    'net' => $r['net'] ?? null,
                ])->all(),
            'unclassified' => [
                'count' => $unclassified->count(),
                'net' => AccountingBooks::baseSum($unclassified, 'base_net'),
                'tax' => AccountingBooks::baseSum($unclassified, 'base_tax'),
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
        $sales = $this->sales($from, $to);
        $purchases = $this->purchases($from, $to);

        // The purchase register is net of vendor credits. For the income
        // statement, show the original purchase on its expense account and the
        // credit separately on the selected income account. This preserves the
        // same profit without hiding which account received the credit.
        $costOfSales = $purchases->where('kind', 'purchase_order')
            ->map(function (array $row) {
                $net = (float) ($row['vendor_gross_amount'] ?? $row['net']);
                $gross = $net + (float) ($row['tax_amount'] ?? 0);

                // No rate means no converted figure — NOT a rate of one. The
                // old `?? 1` here quietly booked an unrated foreign order into
                // cost of sales at its face value, so a 10,000 EUR order became
                // 10,000 SGD of cost and the profit was wrong by the margin
                // between them.
                $rate = $row['exchange_rate'] ?? null;
                $rate = ($rate !== null && (float) $rate > 0) ? (float) $rate : null;

                return array_replace($row, [
                    'net' => round($net, 2),
                    'gross' => round($gross, 2),
                    'base_net' => $rate === null ? null : round($net * $rate, 2),
                    'base_gross' => $rate === null ? null : round($gross * $rate, 2),
                ]);
            });
        // Wages are an overhead like any other. Matching on "not a purchase
        // order" rather than naming each kind, so a cost added later cannot
        // quietly appear in the register but be missed by the profit.
        $overheads = $purchases->where('kind', '!=', 'purchase_order');

        // Per-job expenses ride on the purchase order rather than on a document
        // of their own, so they are cost of sales too.
        $jobExpenses = AccountingBooks::baseSum($costOfSales, 'base_expenses');

        // Every one of these is converted. The income statement is the report
        // the whole thing exists to produce, and a profit figure built by
        // subtracting euros from dirhams is not a profit figure.
        $revenue = AccountingBooks::baseSum($sales, 'base_net');
        $purchased = AccountingBooks::baseSum($costOfSales, 'base_net');
        $cogs = round($purchased + $jobExpenses, 2);
        $overhead = AccountingBooks::baseSum($overheads, 'base_net');
        $grossProfit = round($revenue - $cogs, 2);

        // Anything that could not be converted is missing from the figures
        // above, so it travels with them. Silence here is how an incomplete
        // profit reads as a complete one.
        $unrated = AccountingBooks::unrated($sales)
            ->concat(AccountingBooks::unrated($purchases))
            ->map(fn ($r) => [
                'kind' => $r['kind'] ?? null,
                'number' => $r['number'] ?? null,
                'currency' => $r['currency'] ?? null,
                'net' => $r['net'] ?? null,
            ])
            ->values();

        // Amounts on no account at all. The income statement counts them — a
        // cost is a cost whether or not somebody coded it — but the trial
        // balance has no account to post them to, which is why the two reports
        // can disagree by exactly this much.
        $unclassified = AccountingBooks::unclassified($sales->concat($purchases));

        // Money ticked paid with no payment behind it: out of receivables and
        // never into cash, so it is missing from both sides of the books.
        $assumedPaid = AccountingBooks::assumedPaid($sales->concat($purchases));

        return [
            'unrated' => $unrated->all(),
            'unrated_count' => $unrated->count(),
            'unclassified_rows' => AccountingBooks::identify($unclassified)->all(),
            'assumed_paid' => AccountingBooks::identify($assumedPaid)->all(),
            'assumed_paid_total' => AccountingBooks::baseSum($assumedPaid, 'base_unrecorded'),
            'unclassified_revenue' => AccountingBooks::baseSum(
                AccountingBooks::unclassified($sales), 'base_net'
            ),
            'unclassified_cost' => round(
                AccountingBooks::baseSum(AccountingBooks::unclassified($costOfSales), 'base_net')
                + AccountingBooks::baseSum(AccountingBooks::unclassified($overheads), 'base_net'),
                2
            ),
            'revenue' => [
                'total' => $revenue,
                'by_account' => $this->groupByAccount($sales),
            ],
            'cost_of_sales' => [
                'total' => $cogs,
                'purchases' => $purchased,
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

        // Balance sheet side, as at the end date. The TRANSLATED cash figures:
        // a trial balance is one column of one currency, so the US dollar cash
        // account goes in at what it is worth in SGD, not at its dollar figure.
        foreach ($positions['cash_in_base'] as $code => $amount) {
            $movements[$code] = (float) ($amount ?? 0);
        }
        $movements[self::RECEIVABLES] = $positions['receivables'];
        $movements[self::PAYABLES] = -$positions['payables'];
        $movements[self::EQUITY] = -$positions['equity'];

        // Client funds held and anything else the documents post straight to a
        // balance-sheet account. Credit-positive there, and this view is
        // debit-positive, so the sign flips.
        foreach ($positions['other_accounts'] as $code => $balance) {
            $movements[$code] = ($movements[$code] ?? 0) - $balance;
        }

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

        // Real liabilities and real amounts with no account on the chart — see
        // gstPayableAt() and accruedExpensesAt(). Shown last and flagged, so it
        // is obvious they are derived rather than posted.
        //
        // The unclassified pair is the important addition. Those amounts ARE in
        // the income statement, because a cost is a cost whether or not anybody
        // coded it — but they have no account to post to here, so the loop above
        // skipped them and the trial balance came out short by exactly that
        // much while the balance sheet did not. Shown rather than dropped: a
        // figure missing from one report and present in another is the kind of
        // difference nobody can ever track down.
        foreach ([
            ['GST payable to IRAS (derived, not on chart)', $positions['gst_payable']],
            ['Accrued expenses — overheads not paid (derived)', $positions['accrued_expenses']],
            // Signs follow the loop below: positive is a credit. Income is
            // credit-normal, a cost is a debit, so the cost goes in negative.
            ['Income on no account code — give these documents an account', $pl['unclassified_revenue']],
            ['Costs on no account code — give these documents an account', -$pl['unclassified_cost']],
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
            // Documents absent from the figures above because they could not be
            // converted. A trial balance that is out needs to say whether the
            // reason is a missing rate before anyone goes looking elsewhere.
            'unrated' => $pl['unrated'],
            'unrated_count' => $pl['unrated_count'],
            // And the ones on no account code, named so they can be fixed.
            'unclassified_rows' => $pl['unclassified_rows'],
            // Money ticked paid with nothing recorded against it: out of
            // receivables, never into cash, so it is missing from both sides.
            // Usually the largest single reason this report does not balance.
            'assumed_paid' => $pl['assumed_paid'],
            'assumed_paid_total' => $pl['assumed_paid_total'],
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

        // Translated, for the same reason the trial balance uses them: the
        // statement has one currency across it.
        $cash = collect($positions['cash_in_base'])->map(fn ($v) => (float) ($v ?? 0));

        // Client funds held and the like. Credit-positive, so a positive
        // balance is a liability and a negative one an asset — more has been
        // paid out on the account than was ever collected for it, which is
        // worth seeing rather than netting away.
        $other = collect($positions['other_accounts']);
        $otherLiabilities = round($other->filter(fn ($v) => $v > 0)->sum(), 2);
        $otherAssets = round(-$other->filter(fn ($v) => $v < 0)->sum(), 2);

        $assets = round($cash->sum() + $positions['receivables'] + $otherAssets, 2);
        $liabilities = round(
            $positions['payables'] + $positions['gst_payable']
            + $positions['accrued_expenses'] + $otherLiabilities, 2
        );
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
                // Each cash account shows what it actually holds beside what
                // that is worth, so "1010 Cash USD" does not read as Singapore
                // dollars just because the column it sits in is in SGD.
                'cash' => $cash->map(fn ($amt, $code) => $accountRow($code, $amt) + [
                    'face_value' => round((float) ($positions['cash'][$code] ?? 0), 2),
                    'face_currency' => array_search($code, self::CASH_ACCOUNTS, true) ?: null,
                    'exchange_rate' => $positions['cash_rates'][$code] ?? null,
                ])->values()->all(),
                'cash_total' => round($cash->sum(), 2),
                'receivables' => $accountRow(self::RECEIVABLES, $positions['receivables']),
                // An account that has paid out more than it collected sits on
                // this side; usually empty.
                'other' => $other->filter(fn ($v) => $v < 0)
                    ->map(fn ($v, $code) => $accountRow((string) $code, -$v))->values()->all(),
                'total' => $assets,
            ],
            'liabilities' => [
                'payables' => $accountRow(self::PAYABLES, $positions['payables']),
                // CTM Client Funds Held and anything like it: money collected
                // from a client that belongs to somebody else. Reported at last
                // — previously it was billed as an asset with nothing on this
                // side to answer for it.
                'other' => $other->filter(fn ($v) => $v > 0)
                    ->map(fn ($v, $code) => $accountRow((string) $code, $v))->values()->all(),
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
            // Why it does not balance, in order of size, rather than leaving
            // the reader to guess. Each of these is money the books cannot see
            // both sides of.
            'assumed_paid' => $pl['assumed_paid'],
            'assumed_paid_total' => $pl['assumed_paid_total'],
            'unclassified_rows' => $pl['unclassified_rows'],
            'unclassified_total' => round($pl['unclassified_revenue'] + $pl['unclassified_cost'], 2),
            'note' => $positions['note'],
            'base_currency' => AccountingBooks::baseCurrency(),
        ]);
    }

    /**
     * Balances on the OTHER balance-sheet accounts — the ones documents post to
     * directly rather than through cash, receivables or payables.
     *
     * 2100 CTM Client Funds Held is the one that matters today. A Cash to
     * Master principal is billed to the client, so it lands in receivables as
     * an asset, and the matching obligation to the vessel master is coded to
     * 2100 on the invoice line. But this report only ever knew six accounts, so
     * that obligation was reported nowhere: the asset was on the books and the
     * liability was not, and the balance sheet was out by the difference.
     *
     * Derived from the line codes rather than a hardcoded list, so an account
     * added to the chart later is picked up without anybody remembering to come
     * back here. Cash, receivables, payables and equity are excluded because
     * they are already measured, properly, from payments and open documents.
     *
     * @return array<string, float> account code => credit-positive balance
     */
    private function otherBalanceSheetAccounts(Carbon $asOf): array
    {
        $handled = array_merge(array_values(self::CASH_ACCOUNTS), [
            self::RECEIVABLES, self::PAYABLES, self::EQUITY,
        ]);

        $balances = [];

        // A sale coded to a liability RAISES it — money collected to hand on.
        // A purchase coded to the same account PAYS IT DOWN again.
        foreach ([
            [$this->sales(null, $asOf), 1],
            [$this->purchases(null, $asOf), -1],
        ] as [$rows, $sign]) {
            foreach ($rows as $row) {
                foreach (($row['base_account_breakdown'] ?? []) as $code => $amount) {
                    if (in_array((string) $code, $handled, true)) {
                        continue;
                    }

                    $account = Account::find_by_code((string) $code);

                    if ($account === null || $account->statement() !== 'balance_sheet') {
                        continue;   // income and expense belong to the P&L
                    }

                    $balances[(string) $code] = round(
                        ($balances[(string) $code] ?? 0) + $sign * (float) $amount, 2
                    );
                }
            }
        }

        return array_filter($balances, fn ($v) => abs($v) > 0.005);
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

        // A USD cash account really does hold US dollars, so each account is
        // accumulated in its own currency and TRANSLATED at the closing rate
        // before the three are added into one total. That is the ordinary
        // treatment of a foreign bank balance — unlike a sale, which is fixed
        // at the rate on the day, cash is worth what it is worth now.
        $cashRate = [];
        foreach (self::CASH_ACCOUNTS as $currency => $code) {
            $cashRate[$code] = AccountingBooks::stampRate($currency);
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

        $cashInBase = [];
        foreach ($cash as $code => $amount) {
            $rate = $cashRate[$code] ?? null;
            $cashInBase[$code] = $rate === null ? null : round($amount * $rate, 2);
        }

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
            // Each account in its own currency, and the same accounts
            // translated. Nothing adds the first of these up.
            'cash' => $cash,
            'cash_in_base' => $cashInBase,
            'cash_rates' => $cashRate,
            'receivables' => $receivables,
            'payables' => $payables,
            'accrued_expenses' => $accrued,
            'equity' => 0.0,
            'gst_payable' => $this->gstPayableAt($asOf),
            // Liabilities and assets the documents post to directly — client
            // funds held, chiefly. Credit-positive.
            'other_accounts' => $this->otherBalanceSheetAccounts($asOf),
            'base_currency' => AccountingBooks::baseCurrency(),
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
        $output = AccountingBooks::baseSum(
            $this->sales(null, $asOf)->where('unclassified', false), 'base_tax'
        );
        $input = AccountingBooks::baseSum(
            $this->purchases(null, $asOf)->where('unclassified', false), 'base_tax'
        );

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

        // The proportion is dimensionless, but the tax itself is in the order's
        // own currency, so it is converted at the order's rate before being
        // added to a payable the balance sheet states in SGD. An order with no
        // rate contributes nothing rather than its face value.
        return (float) $entries->sum(function ($e) use ($tax) {
            $rate = $e['exchange_rate'] ?? null;

            if ($rate === null || (float) $e['amount'] == 0.0) {
                return 0.0;
            }

            return (float) ($tax[$e['id']] ?? 0)
                * ((float) $e['outstanding'] / (float) $e['amount'])
                * (float) $rate;
        });
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
        // Through AccountingBooks rather than off the model, so these arrive
        // already converted. Read straight from OperatingExpense they are face
        // values, and a euro-denominated overhead went onto a Singapore-dollar
        // balance sheet at its face amount.
        $overheads = AccountingBooks::baseSum(
            AccountingBooks::expenses(null, $asOf), 'base_gross'
        );

        // Payroll is the same story: a cost recorded with no payment against
        // it. Left out, the balance sheet goes out by the whole wage bill the
        // moment a month is finalised.
        $wages = AccountingBooks::baseSum(AccountingBooks::payroll(null, $asOf), 'base_net');

        return $overheads + $wages;
    }

    /**
     * Total still outstanding across every party of a kind, as at a date, in
     * the reporting currency.
     *
     * Each document is valued at the rate stamped on it, so a receivable on the
     * balance sheet is the same figure the sales register reported for that
     * invoice. It used to sum the face values, which put dirhams, euros and US
     * dollars into one Singapore-dollar line.
     */
    private function openTotal(string $type, Carbon $asOf): float
    {
        return (float) OpenEntries::build($type, $asOf, false)['base_grand_total']['outstanding'];
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

        $unrated = 0;

        $parties = collect($book['parties'])->map(function ($p) use ($bucketOf, $empty, &$grand, &$unrated) {
            $buckets = $empty;
            $entries = collect($p['entries'])->map(function ($e) use ($bucketOf, &$buckets, &$unrated) {
                $bucket = $bucketOf((int) $e['overdue_days']);

                // Bucketed on the CONVERTED amount. The buckets are added
                // across every party on the report, so a dirham invoice and a
                // dollar one landing in the same bucket have to be in the same
                // money first.
                if (($e['base_outstanding'] ?? null) === null) {
                    $unrated++;
                } else {
                    $buckets[$bucket] += (float) $e['base_outstanding'];
                }

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
                // What the party owes in their own currencies, kept for the
                // chase: "USD 4,000 and EUR 600" is what you say on the phone.
                'by_currency' => $p['totals'],
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
            'by_currency' => $book['grand_totals'],
            // Documents with no rate, absent from every bucket above.
            'unrated' => $unrated,
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

    /**
     * Put today's rate on every document that still has none.
     *
     * New documents take a rate the moment they are saved, so this is for the
     * ones that already existed when rates were introduced — fifteen purchase
     * orders is not something anyone should open one at a time.
     *
     * Today's rate, not the rate on the day of supply, because that is the only
     * one obtainable: the board-rate feed publishes current rates and nothing
     * historic. It goes ONTO each document, so the figures stop moving the
     * moment it runs, and any of them can still be corrected by hand
     * afterwards. For a document from months ago that correction may well be
     * worth making, and the response says which ones were touched so there is a
     * list to work from.
     */
    public function fillRates(Request $request)
    {
        $base = \App\Support\AccountingBooks::baseCurrency();
        $filled = [];
        $unpriced = [];

        // With ?restamp=1, documents that ALREADY have a rate are rewritten at
        // today's too. Opt-in and never the default: a stored rate is what a
        // past period was reported at, and quietly replacing it would restate
        // figures somebody has already filed.
        //
        // It exists because a rate can turn out to have been taken the wrong
        // way round — the posting rate crossed the bank's spread twice for a
        // while, putting USD in at 1.2922 where the converter says 1.2691 —
        // and correcting that across a whole set by hand is not realistic.
        $restamp = $request->boolean('restamp');

        // Scoped to the period on screen, and that matters most when
        // re-stamping. A January invoice must not be re-converted at
        // December's rate because somebody looking at December pressed a
        // button — that is the exact thing storing the rate was for. Each
        // type is filtered on the date the registers already file it under.
        // input(), not query(): read wherever they arrive. With query() the
        // dates are invisible unless they are in the URL, and a scope that
        // silently disappears on this endpoint means re-converting every
        // document ever instead of the month that was asked for.
        $from = $request->input('from');
        $to = $request->input('to');

        // And rather than trust that, refuse. Filling blanks across the whole
        // set is harmless; REPLACING stored rates across the whole set rewrites
        // periods that have been reported, so it has to name its period.
        abort_if(
            $restamp && (! $from || ! $to),
            422,
            'Say which period to re-convert — re-stamping every document at once is not something to do by accident.'
        );

        $targets = [
            \App\Models\CustomerInvoice::class => ['invoice_number', 'issue_date'],
            \App\Models\CreditMemo::class => ['cm_number', 'memo_date'],
            \App\Models\PurchaseOrder::class => ['po_number', 'issued_date'],
            \App\Models\OperatingExpense::class => ['label', 'period_start'],
        ];

        foreach ($targets as $model => [$numberColumn, $dateColumn]) {
            $model::query()
                ->when(! $restamp, fn ($q) => $q->whereNull('exchange_rate'))
                ->when($restamp, fn ($q) => $q->whereRaw('UPPER(currency) <> ?', [$base]))
                ->when($from, fn ($q) => $q->whereDate($dateColumn, '>=', $from))
                ->when($to, fn ($q) => $q->whereDate($dateColumn, '<=', $to))
                ->get()
                ->each(function ($doc) use ($base, $numberColumn, &$filled, &$unpriced) {
                    $rate = \App\Support\AccountingBooks::stampRate((string) $doc->currency);

                    if ($rate === null) {
                        $unpriced[] = (string) ($doc->{$numberColumn} ?: class_basename($doc).' #'.$doc->id);

                        return;
                    }

                    $doc->exchange_rate = $rate;
                    if (in_array('base_currency', $doc->getFillable(), true)) {
                        $doc->base_currency = $base;
                    }
                    $doc->save();

                    $filled[] = [
                        'number' => (string) ($doc->{$numberColumn} ?: class_basename($doc).' #'.$doc->id),
                        'currency' => (string) $doc->currency,
                        'rate' => (float) $rate,
                    ];
                });
        }

        return $this->ok([
            'filled' => $filled,
            'filled_count' => count($filled),
            'unpriced' => $unpriced,
            'base_currency' => $base,
            'from' => $from,
            'to' => $to,
        ]);
    }
}

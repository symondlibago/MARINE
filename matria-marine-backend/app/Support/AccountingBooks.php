<?php

namespace App\Support;

use App\Models\Account;
use App\Models\CreditMemo;
use App\Models\CustomerInvoice;
use App\Models\OperatingExpense;
use App\Models\PurchaseOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The books: every sale and every purchase in a period, classified.
 *
 * Built once and read by all nine accounting screens, so the sales register,
 * the GST return, the income statement and the trial balance can never
 * disagree about what a month's revenue was. Add a rule here and every report
 * picks it up; add it in a controller and only that screen gets it.
 *
 * Each row carries the whole chain of reasoning behind its number — account
 * code, account name, GST code, and the F5 box it lands in — so a figure on a
 * return can be traced back to the document that produced it without anybody
 * re-deriving anything.
 *
 * On currency: every document carries the rate it was posted at, and EVERY
 * money field on a row is published twice — once at face value, once converted.
 * Nothing anywhere is totalled on the face value. A row with no rate converts
 * to null rather than to its face value, so it falls out of the totals and is
 * named in `unrated` instead of being quietly counted at par.
 */
class AccountingBooks
{
    /**
     * Every money field on a row, and what its converted twin is called.
     *
     * The list is the point. For a while only net, tax, gross and revenue were
     * converted, so the sales register's own NET column totalled correctly in
     * Singapore dollars while BILLED, SETTLED and OUTSTANDING underneath it
     * still added 536,185 dirhams to a pile of US dollars. Any money field
     * missing from here is a total waiting to be wrong, so a new one goes in
     * here rather than being converted wherever it happens to be summed.
     */
    private const CONVERTED = [
        'subtotal' => 'base_subtotal',
        'delivery' => 'base_delivery',
        'expenses' => 'base_expenses',
        'net' => 'base_net',
        'tax_amount' => 'base_tax',
        'gross' => 'base_gross',
        'revenue_net' => 'base_revenue',
        'cost_net' => 'base_cost',
        'pass_through_net' => 'base_pass_through',
        'billed_net' => 'base_billed_net',
        'billed_gross' => 'base_billed_gross',
        'vendor_gross_amount' => 'base_vendor_gross',
        'credit_note_amount' => 'base_credit_note_amount',
        'settled' => 'base_settled',
        'outstanding' => 'base_outstanding',
    ];

    /**
     * Credit notes reduce a sale. They go into the register as NEGATIVE rows
     * rather than a separate list, because that is exactly how they behave on
     * the return: they come off Box 1 or Box 2 and off the output tax.
     */
    private const INVOICE = 1;

    private const CREDIT = -1;

    public static function baseCurrency(): string
    {
        return strtoupper(config('procurement.base_currency', 'SGD'));
    }

    /**
     * What a document converts to the reporting currency at.
     *
     * Returns null when nobody has set one yet, and that null is the point.
     * The old code answered 1.0 here, which is why an invoice for AED 536,185
     * went into the sales register as 536,185 Singapore dollars and the total
     * underneath it was meaningless. A rate that has not been decided is not a
     * rate of one, so documents without one are reported as needing attention
     * rather than silently converted at par.
     *
     * A document already in the reporting currency needs no stored rate: one
     * is simply the truth.
     */
    public static function rateOf(object $doc): ?float
    {
        if (strtoupper((string) $doc->currency) === self::baseCurrency()) {
            return 1.0;
        }

        $rate = $doc->exchange_rate ?? null;

        return $rate !== null && (float) $rate > 0 ? (float) $rate : null;
    }

    /** Documents whose figures cannot be converted, and so cannot be totalled. */
    public static function unrated(Collection $rows): Collection
    {
        return $rows->filter(fn ($r) => ($r['exchange_rate'] ?? null) === null)->values();
    }

    /**
     * Total a converted column.
     *
     * The ONLY way anything in the books is added up. Summing a face-value
     * column across a set that holds AED, EUR, SGD and USD produces a number
     * with no meaning, and it produced one on every screen for a while — so the
     * aggregates do not reach for the native field at all.
     *
     * A row with no rate holds null here and contributes nothing, which leaves
     * the total understated rather than invented. Every payload that calls this
     * publishes unrated() beside it so the gap is named rather than merely
     * absent.
     */
    public static function baseSum(Collection $rows, string $field): float
    {
        return round($rows->sum(fn ($r) => (float) ($r[$field] ?? 0)), 2);
    }

    /**
     * Today's rate for a document that has none, ready to be stored on it.
     *
     * Called when a foreign document is saved, so nobody has to go and type a
     * rate on every invoice and order — but it is recorded ON the document at
     * that moment rather than looked up again each time a report is drawn.
     *
     * The difference is the whole thing. Converting at report time would mean
     * last quarter's figures changing every morning as the market moves, and a
     * number filed with IRAS that the system can no longer reproduce. Stamping
     * it once gives the same absence of clicking and a set of books that stays
     * put.
     *
     * Null when the pair cannot be priced, which leaves the document unrated
     * and visible rather than converted at a number nobody can defend.
     */
    public static function stampRate(string $currency): ?float
    {
        $base = self::baseCurrency();

        if (strtoupper($currency) === $base) {
            return 1.0;
        }

        // Read straight off the board for this currency — the same number the
        // DBS converter on dbs.com.sg shows, which is what anyone checking
        // these figures will type into it.
        //
        // NOT FxRates::rateToBase(). That one deliberately crosses the bank's
        // spread in both directions, because it answers a different question:
        // what a vendor's foreign invoice costs us to settle. For USD it gives
        // 1.292213 against the converter's 1.2691, and for EUR 1.444371
        // against 1.4182 — fine for pricing a purchase, wrong for stating what
        // a sale was worth. The two are kept apart on purpose.
        $data = \App\Support\FxRates::for($currency);
        $rate = (float) ($data['rates'][$base] ?? 0);

        return $rate > 0 ? round($rate, 6) : null;
    }

    /* ------------------------------------------------------------------ */
    /*  Sales                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * Every sales/income document whose date falls in the range.
     *
     * This includes vendor credit notes because they are posted to a sales or
     * income account and must be visible in the Sales register. They are marked
     * out of scope for GST because they are not customer supplies.
     */
    public static function sales(?Carbon $from, ?Carbon $to): Collection
    {
        $invoices = CustomerInvoice::query()
            ->where('status', '!=', 'draft')
            ->with(['customer:id,name,customer_no', 'rfq:id,reference,ship_name', 'items'])
            ->when($from, fn ($q) => $q->whereDate('issue_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('issue_date', '<=', $to))
            ->get()
            ->map(fn (CustomerInvoice $i) => self::salesRow($i));

        $credits = CreditMemo::query()
            ->where('status', 'issued')
            ->with(['customer:id,name,customer_no', 'rfq:id,reference,ship_name', 'items', 'invoice:id,exchange_rate,currency'])
            ->when($from, fn ($q) => $q->whereDate('memo_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('memo_date', '<=', $to))
            ->get()
            ->map(fn (CreditMemo $c) => self::creditRow($c));

        return $invoices->concat($credits)
            ->concat(self::vendorCredits($from, $to))
            ->sortBy([['date', 'desc'], ['number', 'desc']])
            ->values();
    }

    /**
     * Split a document's lines into what we earned and what is only passing
     * through us.
     *
     * A line coded to an income account is revenue. One coded to a liability —
     * the Cash to Master principal, collected from the client to hand to a
     * vessel master — is money we are holding, never money we made. The client
     * still owes the whole invoice; only this split decides what counts as
     * profit. The purchase side works the same way against expense accounts.
     *
     * A line with no code at all stays on the document's own side: a missing
     * code is an oversight, and quietly dropping the amount from the totals
     * would be worse than counting it.
     *
     * @return array{kept: float, pass_through: float, by_account: array<string, float>}
     */
    private static function lineSplit(iterable $lines, string $amountField, ?string $fallbackCode, string $wantType): array
    {
        $kept = 0.0;
        $passed = 0.0;
        $byAccount = [];

        foreach ($lines as $line) {
            if (! empty($line->is_heading)) {
                continue;   // a label, not a posting
            }

            $amount = (float) $line->{$amountField};
            $code = $line->account_code ?: $fallbackCode;
            $account = Account::find_by_code($code);

            if ($account === null || $account->type === $wantType) {
                $kept += $amount;
            } else {
                $passed += $amount;
            }

            $key = $code ?: '—';
            $byAccount[$key] = round(($byAccount[$key] ?? 0) + $amount, 2);
        }

        return [
            'kept' => round($kept, 2),
            'pass_through' => round($passed, 2),
            'by_account' => $byAccount,
        ];
    }

    private static function salesRow(CustomerInvoice $i): array
    {
        $delivery = (float) $i->packing_cost + (float) $i->transportation_cost;
        $billedNet = (float) $i->subtotal + $delivery;
        $tax = (float) $i->tax_amount;
        $settlement = Settlement::of($i);

        // Packing and transportation are charges we make, so they are always
        // ours; only the line items can be pass-through.
        $split = self::lineSplit($i->items, 'line_total', $i->account_code, Account::INCOME);
        $saleLines = $split['kept'];
        $saleNet = $saleLines + $delivery;

        // Tax follows the part that is actually a supply. With nothing passing
        // through, the ratio is 1 and the invoice's own tax is used unchanged.
        $saleTax = $billedNet > 0 ? round($tax * ($saleNet / $billedNet), 2) : $tax;

        return self::classify([
            'revenue_net' => round($saleNet, 2),
            'account_breakdown' => $split['by_account'],
            'id' => $i->id,
            'kind' => 'invoice',
            'kind_label' => 'Invoice',
            'number' => $i->invoice_number,
            'date' => optional($i->issue_date)->toDateString(),
            'due_date' => optional($i->due_date)->toDateString(),
            'party_id' => $i->customer_id,
            'party_no' => $i->customer?->customer_no,
            'party_name' => $i->customer_name ?: $i->customer?->name,
            'party_reference' => $i->customer_reference,
            'reference' => $i->rfq?->reference,
            'vessel' => $i->rfq?->ship_name,
            'currency' => $i->currency,
            'exchange_rate' => self::rateOf($i),
            // The SALE, not the invoice. A Cash to Master principal is billed
            // to the client and collected from them, but it was never a supply
            // Matria made — it belongs to the vessel master. Leaving it in
            // these columns puts 10,000 of somebody else's money into the sales
            // register and into GST Box 2. The full invoice is still carried
            // below as billed_*, and settled/outstanding stay whole, because
            // the customer does owe and pay the lot.
            'subtotal' => round($saleLines, 2),
            'delivery' => round($delivery, 2),
            'net' => round($saleNet, 2),
            'tax_rate' => (float) $i->tax_rate,
            'tax_amount' => round($saleTax, 2),
            'gross' => round($saleNet + $saleTax, 2),
            'billed_net' => round($billedNet, 2),
            'billed_gross' => round($billedNet + $tax, 2),
            'pass_through_net' => $split['pass_through'],
            'status' => $i->status,
            'paid' => $i->status === 'paid' || $i->paid_at !== null,
            'settled' => $settlement['settled'],
            'outstanding' => $settlement['outstanding'],
            'sign' => self::INVOICE,
        ], $i->account_code, 'sales');
    }

    private static function creditRow(CreditMemo $c): array
    {
        $net = (float) $c->subtotal;
        $tax = (float) $c->tax_amount;

        // A credit undoes a sale, so it is split the same way the sale was.
        // Handing back a Cash to Master principal reverses a liability, not
        // revenue — it was never income going out, so it is not income coming
        // back either.
        $split = self::lineSplit($c->items, 'line_total', $c->account_code, Account::INCOME);
        $saleNet = $split['kept'];
        $saleTax = $net > 0 ? round($tax * ($saleNet / $net), 2) : $tax;

        return self::classify([
            'revenue_net' => round(-$saleNet, 2),
            'billed_net' => round(-$net, 2),
            'billed_gross' => round(-($net + $tax), 2),
            'pass_through_net' => round(-$split['pass_through'], 2),
            'account_breakdown' => $split['by_account'],
            'id' => $c->id,
            'kind' => 'credit_memo',
            'kind_label' => 'Credit note',
            'number' => $c->cm_number,
            'date' => optional($c->memo_date)->toDateString(),
            'due_date' => null,
            'party_id' => $c->customer_id,
            'party_no' => $c->customer?->customer_no,
            'party_name' => $c->customer_name ?: $c->customer?->name,
            'party_reference' => null,
            'reference' => $c->rfq?->reference,
            'vessel' => $c->rfq?->ship_name,
            'currency' => $c->currency,
            // Falls back to the invoice it credits. A credit note has no rate
            // of its own to decide — it undoes a sale, so it undoes it at the
            // figure that sale went into the books at. It also means setting
            // the invoice's rate fixes the credit note with it, instead of
            // leaving a memo stranded with nowhere on screen to type one.
            'exchange_rate' => self::rateOf($c) ?? ($c->invoice ? self::rateOf($c->invoice) : null),
            // Negative throughout: a credit note is a sale running backwards.
            // Only the part that was a sale, for the same reason the invoice
            // only counted that part in the first place.
            'subtotal' => round(-$saleNet, 2),
            'delivery' => 0.0,
            'net' => round(-$saleNet, 2),
            'tax_rate' => (float) $c->tax_rate,
            'tax_amount' => round(-$saleTax, 2),
            'gross' => round(-($saleNet + $saleTax), 2),
            'status' => $c->status,
            'paid' => true,
            'settled' => 0.0,
            'outstanding' => 0.0,
            'sign' => self::CREDIT,
        ], $c->account_code, 'sales');
    }


    /* ------------------------------------------------------------------ */
    /*  Purchases                                                         */
    /* ------------------------------------------------------------------ */

    /**
     * Purchase orders and operating expenses in the range.
     *
     * Both are purchases as far as the return is concerned — input tax is input
     * tax whether it came off a vendor's invoice for a job or off the office
     * electricity bill. They are told apart by `kind`, so a screen can split
     * them and the GST summary does not have to.
     *
     * Cancelled orders are excluded by PurchaseOrder::live(); a cancelled order
     * bought nothing.
     */
    public static function purchases(?Carbon $from, ?Carbon $to): Collection
    {
        $orders = PurchaseOrder::live()
            ->with(['vendor:id,name,vendor_no', 'rfq:id,reference,ship_name', 'items'])
            ->when($from, fn ($q) => $q->where(fn ($w) => $w->whereDate('issued_date', '>=', $from)
                ->orWhere(fn ($n) => $n->whereNull('issued_date')->whereDate('created_at', '>=', $from))))
            ->when($to, fn ($q) => $q->where(fn ($w) => $w->whereDate('issued_date', '<=', $to)
                ->orWhere(fn ($n) => $n->whereNull('issued_date')->whereDate('created_at', '<=', $to))))
            ->get()
            ->map(fn (PurchaseOrder $p) => self::purchaseRow($p));

        return $orders->concat(self::expenses($from, $to))
            ->concat(self::payroll($from, $to))
            ->sortBy([['date', 'desc'], ['number', 'desc']])
            ->values();
    }


    /**
     * Vendor credits posted to the chosen income account.
     *
     * These are income-statement rows, not customer supplies, so they are not
     * included in sales() or the GST sales boxes. The purchase register still
     * carries the net vendor charge for input-tax and payable reporting.
     */
    public static function vendorCredits(?Carbon $from, ?Carbon $to): Collection
    {
        return PurchaseOrder::live()
            ->where('has_credit_note', true)
            ->where('credit_note_amount', '>', 0)
            ->with(['vendor:id,name,vendor_no', 'rfq:id,reference,ship_name', 'items'])
            ->when($from, fn ($q) => $q->where(fn ($w) => $w->whereDate('issued_date', '>=', $from)
                ->orWhere(fn ($n) => $n->whereNull('issued_date')->whereDate('created_at', '>=', $from))))
            ->when($to, fn ($q) => $q->where(fn ($w) => $w->whereDate('issued_date', '<=', $to)
                ->orWhere(fn ($n) => $n->whereNull('issued_date')->whereDate('created_at', '<=', $to))))
            ->get()
            ->map(function (PurchaseOrder $p) {
                $amount = $p->vendorCreditAmount();
                $date = $p->issued_date ?: $p->created_at;

                $row = self::classify([
                    'id' => $p->id,
                    'kind' => 'vendor_credit_note',
                    'kind_label' => 'Vendor credit note',
                    'number' => $p->credit_note_number,
                    'date' => optional($date)->toDateString(),
                    'due_date' => null,
                    'party_id' => $p->vendor_id,
                    'party_no' => $p->vendor?->vendor_no,
                    'party_name' => $p->vendor?->name,
                    'party_reference' => null,
                    'reference' => $p->rfq?->reference,
                    'vessel' => $p->rfq?->ship_name ?: $p->ship_name,
                    'currency' => $p->currency,
                    'exchange_rate' => self::rateOf($p),
                    'subtotal' => round($amount, 2),
                    'delivery' => 0.0,
                    'net' => round($amount, 2),
                    'tax_rate' => 0.0,
                    'tax_amount' => 0.0,
                    'gross' => round($amount, 2),
                    'status' => $p->status,
                    'paid' => true,
                    'settled' => 0.0,
                    'outstanding' => 0.0,
                    'sign' => self::INVOICE,
                ], $p->credit_note_account_code ?: Account::DEFAULT_SALES, 'sales');

                // It is income, but not a supply made to a customer. Keep it
                // visible in Sales while excluding it from every GST box.
                return array_replace($row, [
                    'gst_code' => GstCodes::OS,
                    'gst_label' => GstCodes::label(GstCodes::OS),
                    'f5_box' => null,
                    'taxable' => false,
                ]);
            })
            ->sortBy([['date', 'desc'], ['number', 'desc']])
            ->values();
    }

    /**
     * Finalised payroll runs — the wage bill, as a cost of running the business.
     *
     * Only FINALISED runs count. A draft month is still being edited and is not
     * a committed cost; letting one into the books would move the profit every
     * time somebody corrected a line.
     *
     * The figure is `total_employer_cost`: gross plus employer CPF plus SDL.
     * The employee's own CPF and SHG come out of that gross, so they are not
     * additional cost — adding them would overstate wages by about a fifth.
     *
     * GST is forced to OUT OF SCOPE whatever account the run is booked to.
     * Salaries are neither a supply nor a purchase, so they belong in no F5 box
     * at all. Booked to 5100, which the chart marks SR, they would otherwise be
     * declared as taxable purchases in Box 5 every quarter — a real
     * misstatement on a filed return, from a field nobody would think to check.
     */
    public static function payroll(?Carbon $from, ?Carbon $to): Collection
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('payroll_runs')) {
            return collect();
        }

        return \App\Models\Payroll\Run::with('lines')
            ->where('status', 'finalised')
            // Dated when the money leaves, falling back to the month itself for
            // a run finalised before a payment date was set.
            ->when($from, fn ($q) => $q->where(fn ($w) => $w->whereDate('payment_date', '>=', $from)
                ->orWhere(fn ($n) => $n->whereNull('payment_date')->whereDate('period', '>=', $from))))
            ->when($to, fn ($q) => $q->where(fn ($w) => $w->whereDate('payment_date', '<=', $to)
                ->orWhere(fn ($n) => $n->whereNull('payment_date')->whereDate('period', '<=', $to))))
            ->get()
            // One run becomes three rows: what staff earned, the employer's CPF
            // on top, and the levy on top of that. The run already works all
            // three out — posting them separately is what lets the income
            // statement show the staff-cost breakdown instead of one figure.
            ->flatMap(function ($run) {
                $t = $run->totals();
                $to = $run->postingAccounts();

                $parts = [
                    ['Salaries', (float) ($t['gross_earnings'] ?? 0), $to['salaries']],
                    ['CPF (employer)', (float) ($t['employer_cpf'] ?? 0), $to['cpf']],
                    ['SDL & other contributions', (float) ($t['sdl'] ?? 0), $to['sdl']],
                ];

                // A run that predates the split, or one whose figures do not add
                // up to the recorded cost, stays a single row on its own account
                // rather than quietly losing the difference.
                $split = round(array_sum(array_column($parts, 1)), 2);
                if (abs($split - $run->costToBusiness()) > 0.01 || Account::chart()->isEmpty()) {
                    $parts = [[null, $run->costToBusiness(), $run->account_code]];
                }

                return collect($parts)
                    ->reject(fn ($p) => abs($p[1]) < 0.005)
                    ->map(fn ($p) => self::payrollRow($run, $t, $p[0], $p[1], $p[2]))
                    ->values();
            });
    }

    /** One posting from a payroll run: its label, its amount, its account. */
    private static function payrollRow($run, array $t, ?string $label, float $cost, ?string $code): array
    {
        $account = Account::find_by_code($code);

        $row = [
            'id' => $run->id,
            'kind' => 'payroll',
            'kind_label' => $label ? 'Payroll — '.$label : 'Payroll',
            'number' => 'Payroll '.optional($run->period)->format('M Y').($label ? ' · '.$label : ''),
            'vendor_invoice_number' => null,
            'date' => optional($run->payment_date ?: $run->period)->toDateString(),
            'due_date' => null,
            'party_id' => null,
            'party_no' => null,
            'party_name' => sprintf('%d employee(s)', $t['headcount'] ?? 0),
            'party_reference' => null,
            'reference' => null,
            'vessel' => null,
            'currency' => $run->currency ?: self::baseCurrency(),
            // Salaries are paid in the reporting currency, so the rate is one.
            // Spelled out rather than assumed: a run in anything else has no
            // rate to convert at, and should say so instead of posting at par.
            'exchange_rate' => strtoupper((string) ($run->currency ?: self::baseCurrency())) === self::baseCurrency() ? 1.0 : null,
            'subtotal' => $cost,
            'expenses' => 0.0,
            'net' => $cost,
            'tax_rate' => 0.0,
            'tax_amount' => 0.0,
            'gross' => $cost,
            'status' => $run->status,
            'paid' => true,
            'settled' => 0.0,
            'outstanding' => 0.0,
            'sign' => self::INVOICE,
        // Everything below is stated outright rather than derived
        // through classify(), because payroll's GST treatment comes
        // from what it IS, not from the account it is booked to.
            'account_code' => $code,
            'account_name' => $account?->name,
            'account_type' => $account?->type,
            'gst_code' => GstCodes::OS,
            'gst_label' => GstCodes::label(GstCodes::OS),
            'f5_box' => null,
            'taxable' => false,
            'unclassified' => $code === null,
            'base_currency' => self::baseCurrency(),
        // The same defaults classify() hands every other row. Payroll does not
        // go through it — its GST treatment comes from what it IS rather than
        // from an account — but the aggregates read these fields on every row
        // alike, so a payroll row that lacks them silently drops the wage bill
        // out of whichever total it was meant to be in.
            'revenue_net' => $cost,
            'cost_net' => $cost,
            'pass_through_net' => 0.0,
            'billed_net' => $cost,
            'billed_gross' => $cost,
            'account_breakdown' => $code ? [$code => $cost] : [],
        // Context the payroll screens already show, carried through
        // so the register can explain the figure without a join.
            'headcount' => $t['headcount'] ?? 0,
            'gross_earnings' => $t['gross_earnings'] ?? 0,
            'employer_cpf' => $t['employer_cpf'] ?? 0,
            'sdl' => $t['sdl'] ?? 0,
        ];

        return $row + self::converted($row);
    }

    private static function purchaseRow(PurchaseOrder $p): array
    {
        // What the vendor actually billed, where a receipt has been recorded;
        // otherwise the awarded cost is the best figure we have.
        $grossPurchase = $p->vendorGrossAmount();
        $net = $p->vendorNetAmount();
        $tax = (float) $p->tax_amount;
        $settlement = Settlement::of($p);
        $date = $p->issued_date ?: $p->created_at;

        // Lines coded somewhere other than an expense account are money moving
        // through us, not cost. Taken off the net rather than recomputed from
        // the lines, because a recorded vendor receipt overrides the line sum.
        $split = self::lineSplit($p->items, 'line_total', $p->account_code, Account::EXPENSE);
        $cost = round($net - $split['pass_through'], 2);

        return self::classify([
            'cost_net' => $cost,
            'pass_through_net' => $split['pass_through'],
            'account_breakdown' => $split['by_account'],
            'id' => $p->id,
            'kind' => 'purchase_order',
            'kind_label' => 'Purchase order',
            'number' => $p->po_number,
            'vendor_invoice_number' => $p->invoice_number,
            'credit_note_number' => $p->has_credit_note ? $p->credit_note_number : null,
            'credit_note_amount' => round($p->vendorCreditAmount(), 2),
            'credit_note_account_code' => $p->has_credit_note ? ($p->credit_note_account_code ?: Account::DEFAULT_SALES) : null,
            'vendor_gross_amount' => round($grossPurchase, 2),
            'date' => optional($date)->toDateString(),
            'due_date' => optional($p->expected_date)->toDateString(),
            'party_id' => $p->vendor_id,
            'party_no' => $p->vendor?->vendor_no,
            'party_name' => $p->vendor?->name,
            'party_reference' => null,
            'reference' => $p->rfq?->reference,
            'vessel' => $p->rfq?->ship_name ?: $p->ship_name,
            'currency' => $p->currency,
            'exchange_rate' => self::rateOf($p),
            'subtotal' => round($net, 2),
            'expenses' => round((float) $p->expenses, 2),
            'net' => round($net, 2),
            'tax_rate' => (float) $p->tax_rate,
            'tax_amount' => round($tax, 2),
            'gross' => round($net + $tax, 2),
            'status' => $p->status,
            'paid' => $p->paid_at !== null,
            'settled' => $settlement['settled'],
            'outstanding' => $settlement['outstanding'],
            'sign' => self::INVOICE,
        ], $p->account_code, 'purchase');
    }

    /**
     * Operating expense groups overlapping the range.
     *
     * A group carries a period rather than a single date, and its whole total
     * counts when that period overlaps — the same rule the existing P&L uses,
     * kept identical on purpose so the two reports agree.
     */
    public static function expenses(?Carbon $from, ?Carbon $to): Collection
    {
        return OperatingExpense::with('items')
            ->when($from, fn ($q) => $q->whereDate('period_end', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('period_start', '<=', $to))
            ->get()
            ->map(function (OperatingExpense $e) {
                $net = $e->total();
                $tax = (float) $e->tax_amount;

                return self::classify([
                    'id' => $e->id,
                    'kind' => 'operating_expense',
                    'kind_label' => 'Operating expense',
                    'number' => $e->label ?: 'Overhead #'.$e->id,
                    'vendor_invoice_number' => null,
                    'date' => optional($e->period_start)->toDateString(),
                    'due_date' => optional($e->period_end)->toDateString(),
                    'party_id' => null,
                    'party_no' => null,
                    'party_name' => $e->label ?: 'Business overhead',
                    'party_reference' => null,
                    'reference' => null,
                    'vessel' => null,
                    'currency' => $e->currency,
                    'exchange_rate' => self::rateOf($e),
                    'subtotal' => round($net, 2),
                    'expenses' => 0.0,
                    'net' => round($net, 2),
                    'tax_rate' => (float) $e->tax_rate,
                    'tax_amount' => round($tax, 2),
                    'gross' => round($net + $tax, 2),
                    'status' => 'recorded',
                    'paid' => true,
                    'settled' => 0.0,
                    'outstanding' => 0.0,
                    'sign' => self::INVOICE,
                    'line_count' => $e->items->count(),
                ], $e->account_code, 'purchase');
            });
    }

    /* ------------------------------------------------------------------ */
    /*  Classification                                                    */
    /* ------------------------------------------------------------------ */

    /**
     * Attach the accounting treatment to a row.
     *
     * This is the single place a document's account code turns into a GST code
     * and an F5 box. An unrecognised code produces `unclassified: true` and no
     * box at all — the amount is then reported as unclassified rather than
     * being dropped into a box nobody chose. Silence is what let the client's
     * spreadsheet under-declare a year of turnover.
     */
    private static function classify(array $row, ?string $code, string $side): array
    {
        $account = Account::find_by_code($code);
        $gst = $account?->gst_code;

        $box = $side === 'sales'
            ? GstCodes::salesBox($gst)
            : GstCodes::purchaseBox($gst);

        $taxable = $side === 'sales'
            ? GstCodes::chargesOutputTax($gst)
            : GstCodes::claimsInputTax($gst);

        // Defaults FIRST, conversion second, and in that order on purpose. The
        // converter can only convert fields it can see, so when it ran against
        // the bare $row it never saw billed_gross, settled or outstanding —
        // those arrived from the defaults below, after it had finished. The
        // register footers then had nothing converted to total and fell back to
        // adding face values across four currencies.
        $full = $row + [
            'account_code' => $code,
            'account_name' => $account?->name,
            'account_type' => $account?->type,
            'gst_code' => $gst,
            'gst_label' => GstCodes::label($gst),
            'f5_box' => $box,
            'taxable' => $taxable,
            'unclassified' => $account === null,
            // Rows built without a line split — credit notes, vendor credits —
            // are earned in full, so they default to their own net.
            'revenue_net' => $row['net'],
            'cost_net' => $row['net'],
            'pass_through_net' => 0.0,
            // Documents with nothing passing through were billed exactly what
            // they sold, so these default to the same figures.
            'billed_net' => $row['net'],
            'billed_gross' => $row['gross'],
            'account_breakdown' => [],
            'base_currency' => self::baseCurrency(),
        ];

        return $full + self::converted($full);
    }

    /**
     * The same figures in the reporting currency.
     *
     * Every one comes back null when the document has no rate, and null is
     * carried all the way to the screen on purpose: a row that cannot be
     * converted has to be visibly missing from the total, not quietly counted
     * as zero. Multiplying by a null rate would have given exactly that — a
     * row worth nothing, sitting in a total that looked complete.
     *
     * @return array<string, float|array|null>
     */
    private static function converted(array $row): array
    {
        $rate = $row['exchange_rate'] ?? null;
        $rate = ($rate !== null && (float) $rate > 0) ? (float) $rate : null;

        $out = [
            'exchange_rate' => $rate,
            'base_currency' => self::baseCurrency(),
        ];

        foreach (self::CONVERTED as $native => $converted) {
            $value = $row[$native] ?? null;

            $out[$converted] = ($rate === null || $value === null)
                ? null
                : round((float) $value * $rate, 2);
        }

        // The per-account split a row contributes to the income statement and
        // the trial balance. Converted member by member, because those two
        // reports are built by adding these up across documents — so leaving
        // them at face value puts dirhams into a Singapore-dollar account
        // balance, which is what the accountant found in the trial balance.
        $out['base_account_breakdown'] = $rate === null
            ? []
            : array_map(fn ($v) => round((float) $v * $rate, 2), $row['account_breakdown'] ?? []);

        return $out;
    }

    /**
     * Currencies seen in a set of rows, and whether they were mixed.
     *
     * Every payload carries this so a screen can say "these totals mix USD and
     * EUR" instead of presenting a meaningless sum as fact.
     */
    public static function currencyNote(Collection $rows): array
    {
        $seen = $rows->pluck('currency')->filter()->unique()->sort()->values();

        return [
            'base_currency' => self::baseCurrency(),
            'currencies' => $seen->all(),
            'multi_currency' => $seen->count() > 1,
        ];
    }
}

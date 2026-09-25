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
 * On currency: purchase orders and operating expenses carry an exchange rate to
 * base; customer invoices do not have that column yet, so their base amount is
 * their face value. Every payload says which currencies it saw and whether more
 * than one was involved, so a screen can warn rather than quietly add dollars
 * to euros.
 */
class AccountingBooks
{
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
            ->with(['customer:id,name,customer_no', 'rfq:id,reference,ship_name'])
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
            'exchange_rate' => 1.0,
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

        return self::classify([
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
            'exchange_rate' => 1.0,
            // Negative throughout: a credit note is a sale running backwards.
            'subtotal' => round(-$net, 2),
            'delivery' => 0.0,
            'net' => round(-$net, 2),
            'tax_rate' => (float) $c->tax_rate,
            'tax_amount' => round(-$tax, 2),
            'gross' => round(-($net + $tax), 2),
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
                    'exchange_rate' => (float) ($p->exchange_rate ?: 1),
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
            ->map(function ($run) {
                $cost = $run->costToBusiness();
                $t = $run->totals();
                $account = $run->accountRecord();

                return [
                    'id' => $run->id,
                    'kind' => 'payroll',
                    'kind_label' => 'Payroll',
                    'number' => 'Payroll '.optional($run->period)->format('M Y'),
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
                    'exchange_rate' => 1.0,
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
                    'account_code' => $run->account_code,
                    'account_name' => $account?->name,
                    'account_type' => $account?->type,
                    'gst_code' => GstCodes::OS,
                    'gst_label' => GstCodes::label(GstCodes::OS),
                    'f5_box' => null,
                    'taxable' => false,
                    'unclassified' => $run->account_code === null,
                    'base_currency' => self::baseCurrency(),
                    'base_net' => $cost,
                    'base_tax' => 0.0,
                    'base_gross' => $cost,
                    // Context the payroll screens already show, carried through
                    // so the register can explain the figure without a join.
                    'headcount' => $t['headcount'] ?? 0,
                    'gross_earnings' => $t['gross_earnings'] ?? 0,
                    'employer_cpf' => $t['employer_cpf'] ?? 0,
                    'sdl' => $t['sdl'] ?? 0,
                ];
            });
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
            'exchange_rate' => (float) ($p->exchange_rate ?: 1),
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
                    'exchange_rate' => (float) ($e->exchange_rate ?: 1),
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

        return $row + [
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
            'base_revenue' => round(($row['revenue_net'] ?? $row['net']) * $row['exchange_rate'], 2),
            'base_net' => round($row['net'] * $row['exchange_rate'], 2),
            'base_tax' => round($row['tax_amount'] * $row['exchange_rate'], 2),
            'base_gross' => round($row['gross'] * $row['exchange_rate'], 2),
        ];
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

<?php

namespace App\Http\Controllers;

use App\Models\Award;
use App\Models\CashToMasterRecord;
use App\Models\CustomerInvoice;
use App\Models\Offer;
use App\Models\OperatingExpense;
use App\Models\PurchaseOrder;
use App\Models\Quote;
use App\Models\Rfq;
use App\Models\RfqVendor;
use App\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ReportsController extends Controller
{
    private function range(Request $request): array
    {
        $from = $request->query('from') ? Carbon::parse($request->query('from'))->startOfDay() : null;
        $to = $request->query('to') ? Carbon::parse($request->query('to'))->endOfDay() : null;

        return [$from, $to];
    }

    private function baseCurrency(): string
    {
        return strtoupper(config('procurement.base_currency', 'USD'));
    }

    /** Amount of a PO converted to its base currency. */
    private function docBase($doc): float
    {
        return (float) $doc->subtotal * (float) $doc->exchange_rate;
    }

    /** Spend overview: ordered vs invoiced, by vendor, by vessel, monthly trend. */
    public function spend(Request $request)
    {
        [$from, $to] = $this->range($request);

        $pos = PurchaseOrder::live()
            ->with(['vendor:id,name', 'rfq:id,ship_name'])
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
            ->get();

        $poBase = fn (PurchaseOrder $p) => $this->docBase($p);

        $byVendor = $pos->groupBy('vendor_id')->map(fn ($g) => [
            'vendor' => $g->first()->vendor?->name ?? '—',
            'total' => round($g->sum($poBase), 2),
            'orders' => $g->count(),
        ])->sortByDesc('total')->values()->take(10);

        $byVessel = $pos->groupBy(fn ($p) => $p->rfq?->ship_name ?: 'Unassigned')->map(fn ($g, $vessel) => [
            'vessel' => $vessel,
            'total' => round($g->sum($poBase), 2),
            'orders' => $g->count(),
        ])->sortByDesc('total')->values()->take(10);

        $monthly = $pos->groupBy(fn ($p) => ($p->issued_date ?? $p->created_at)->format('Y-m'))
            ->map(fn ($g, $m) => ['month' => $m, 'ordered' => round($g->sum($poBase), 2)])
            ->sortKeys()->values();

        $multiBase = $pos->pluck('base_currency')->filter()->unique()->count() > 1;

        return response()->json(['success' => true, 'data' => [
            'base_currency' => $this->baseCurrency(),
            'multi_base' => $multiBase,
            'totals' => [
                'ordered' => round($pos->sum($poBase), 2),
                'po_count' => $pos->count(),
            ],
            'by_vendor' => $byVendor,
            'by_vessel' => $byVessel,
            'monthly' => $monthly,
        ]]);
    }

    /**
     * Accounting P&L — driven by CUSTOMER INVOICES (the real bill), by issue date.
     * Revenue = invoice total ex-GST; COGS = the vendor POs behind the job (receipt
     * where recorded, else awarded cost); job expenses = per-PO expenses; overhead =
     * business operating expenses in the range. Also surfaces A/R (unpaid invoices)
     * and A/P (unpaid POs).
     *
     *   Revenue − COGS − Job expenses = Gross profit − Overhead = Net profit
     *
     * NOTE: revenue is taken at the invoice's face value (assumed base currency, like
     * the prior report); only vendor costs carry a per-PO exchange rate.
     */
    public function accounting(Request $request)
    {
        [$from, $to] = $this->range($request);
        $base = $this->baseCurrency();

        // Revenue side: customer invoices whose issue date falls in the range.
        $invoices = CustomerInvoice::with(['rfq:id,reference,ship_name', 'creditMemos:id,customer_invoice_id,cm_number,status,subtotal'])
            ->when($from, fn ($q) => $q->where('issue_date', '>=', $from))
            ->when($to, fn ($q) => $q->where('issue_date', '<=', $to))
            ->orderByDesc('issue_date')
            ->get();

        // Cost side: the POs behind each job, grouped by enquiry.
        $rfqIds = $invoices->pluck('rfq_id')->filter()->unique()->all();
        $posByRfq = PurchaseOrder::live()
            ->withCount('attachments')
            ->with('vendor:id,name')
            ->whereIn('rfq_id', $rfqIds)
            ->get()
            ->groupBy('rfq_id');

        // A job's POs are attributed to the FIRST invoice seen for that enquiry, so a
        // second invoice on the same job doesn't double-count the vendor cost.
        $costedRfq = [];

        $rows = $invoices->map(function (CustomerInvoice $inv) use ($posByRfq, &$costedRfq) {
            $gross = (float) $inv->grand_total - (float) $inv->tax_amount; // ex-GST: tax isn't income
            // Issued credit memos reduce the sale (their subtotal is ex-GST too).
            $credits = (float) $inv->creditMemos->where('status', 'issued')->sum('subtotal');
            $gross = round($gross - $credits, 2);
            $invoicePaid = $inv->status === 'paid' || $inv->paid_at !== null;

            $pos = ($inv->rfq_id && ! isset($costedRfq[$inv->rfq_id]))
                ? $posByRfq->get($inv->rfq_id, collect())
                : collect();
            if ($inv->rfq_id) {
                $costedRfq[$inv->rfq_id] = true;
            }

            $vendorCost = 0.0;
            $expenses = 0.0;
            $costPaid = 0.0;
            $received = 0;
            $posPaid = 0;

            $vendors = $pos->map(function (PurchaseOrder $po) use (&$vendorCost, &$expenses, &$costPaid, &$received, &$posPaid) {
                $rate = (float) ($po->exchange_rate ?: 1);
                $cost = $po->vendorNetAmount() * $rate;
                $expRate = $po->expense_currency ? (float) ($po->expense_rate ?: 1) : $rate;
                $exp = (float) $po->expenses * $expRate;
                $hasReceipt = $po->receipt_amount !== null || $po->attachments_count > 0;
                $paid = $po->paid_at !== null;

                $vendorCost += $cost;
                $expenses += $exp;
                if ($hasReceipt) {
                    $received++;
                }
                if ($paid) {
                    $posPaid++;
                    $costPaid += $cost + $exp;
                }

                return [
                    'vendor' => $po->vendor?->name ?? '—',
                    'po_number' => $po->po_number,
                    'awarded' => round((float) $po->subtotal * $rate, 2),
                    'cost' => round($cost, 2),
                    'expenses' => round($exp, 2),
                    'has_receipt' => $hasReceipt,
                    'paid' => $paid,
                ];
            })->values();

            $poCount = $pos->count();
            $costIncurred = $vendorCost + $expenses;
            $collected = $invoicePaid ? $gross : 0.0;

            return [
                'invoice_id' => $inv->id,
                'invoice_number' => $inv->invoice_number,
                'qtn' => $inv->rfq?->reference ?? '—',
                'direct' => $inv->rfq_id === null,
                'customer' => $inv->customer_name,
                'vessel' => $inv->rfq?->ship_name,
                'date' => optional($inv->issue_date)->toDateString(),
                'currency' => $inv->currency,
                'gross' => round($gross, 2),
                'credits' => round($credits, 2),
                'credit_memo' => $inv->creditMemos->where('status', 'issued')->pluck('cm_number')->implode(', ') ?: null,
                'vendor_cost' => round($vendorCost, 2),
                'expenses' => round($expenses, 2),
                'markup' => round($gross - $vendorCost, 2),
                'net' => round($gross - $costIncurred, 2),
                'invoice_paid' => $invoicePaid,
                'collected' => round($collected, 2),
                'receivable' => round($gross - $collected, 2),
                'cost_paid' => round($costPaid, 2),
                'payable' => round($costIncurred - $costPaid, 2),
                'received' => $received,
                'pos_paid' => $posPaid,
                'po_count' => $poCount,
                'vendors' => $vendors,
            ];
        })->values();

        [$overhead, $overheadItems] = $this->overheadForRange($from, $to);

        // CTM is an agent service: only the fee is revenue, and only the
        // entered FX/transfer cost is overhead. The cash principal is neither.
        $ctm = \Illuminate\Support\Facades\Schema::hasTable('cash_to_master_records')
            ? CashToMasterRecord::with('customer:id,name')
                ->when($from, fn ($q) => $q->whereDate('transaction_date', '>=', $from))
                ->when($to, fn ($q) => $q->whereDate('transaction_date', '<=', $to))
                ->get()
            : collect();
        $ctmRevenue = round($ctm->sum(fn ($record) => $record->feeAmount() * (float) ($record->exchange_rate ?: 1)), 2);
        $ctmFx = round($ctm->sum(fn ($record) => $record->fxExpense() * (float) ($record->exchange_rate ?: 1)), 2);
        foreach ($ctm as $record) {
            if ($record->fxExpense() <= 0.005) {
                continue;
            }
            $overheadItems[] = [
                'id' => 'ctm-'.$record->id,
                'name' => 'CTM FX — '.$record->reference,
                'period_start' => $record->transaction_date?->toDateString(),
                'period_end' => $record->transaction_date?->toDateString(),
                'amount' => round($record->fxExpense() * (float) ($record->exchange_rate ?: 1), 2),
            ];
        }
        $overhead += $ctmFx;

        $sum = fn ($k) => round($rows->sum($k), 2);
        $invoiceRevenue = $sum('gross');
        $revenue = round($invoiceRevenue + $ctmRevenue, 2);
        $cogs = $sum('vendor_cost');
        $jobExpenses = $sum('expenses');
        $grossProfit = round($revenue - $cogs - $jobExpenses, 2);

        return response()->json(['success' => true, 'data' => [
            'base_currency' => $base,
            'multi_base' => $invoices->pluck('currency')->filter()->unique()->count() > 1,
            'rows' => $rows,
            'overhead_items' => $overheadItems,
            'totals' => [
                'jobs' => $rows->count(),
                'revenue' => $revenue,
                'invoice_revenue' => $invoiceRevenue,
                'ctm_fee_income' => $ctmRevenue,
                'ctm_fx_expense' => $ctmFx,
                'ctm_net_cash' => round($ctmRevenue - $ctmFx, 2),
                'ctm_count' => $ctm->count(),
                'cogs' => $cogs,
                'job_expenses' => $jobExpenses,
                'gross_profit' => $grossProfit,
                'overhead' => round($overhead, 2),
                'net_profit' => round($grossProfit - $overhead, 2),
                'collected' => $sum('collected'),
                'receivables' => $sum('receivable'),
                'cost_paid' => $sum('cost_paid'),
                'payables' => $sum('payable'),
            ],
        ]]);
    }

    /**
     * Total business overhead applying within [from, to], converted to base. Each
     * overhead group carries a period; a group is included when its period overlaps
     * the report range, counting its full total (no proration). Returns [total, rows].
     */
    private function overheadForRange(?Carbon $from, ?Carbon $to): array
    {
        $groups = OperatingExpense::with('items')
            ->when($from, fn ($q) => $q->whereDate('period_end', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('period_start', '<=', $to))
            ->orderByDesc('period_start')
            ->get();

        $total = 0.0;
        $rows = [];

        foreach ($groups as $g) {
            $amt = $g->totalBase();
            $total += $amt;
            $rows[] = [
                'id' => $g->id,
                'name' => $g->label ?: 'Overhead',
                'period_start' => $g->period_start?->toDateString(),
                'period_end' => $g->period_end?->toDateString(),
                'amount' => round($amt, 2),
            ];
        }

        return [$total, $rows];
    }

    /** Vendor scorecard: response/win/acceptance rates + ordered value. */
    public function vendors(Request $request)
    {
        [$from, $to] = $this->range($request);

        $rows = Vendor::orderBy('name')->get()->map(function (Vendor $v) use ($from, $to) {
            $sent = RfqVendor::where('vendor_id', $v->id)
                ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
                ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
                ->count();

            $quoted = Quote::where('vendor_id', $v->id)
                ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
                ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
                ->count();

            $pos = PurchaseOrder::live()->where('vendor_id', $v->id)
                ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
                ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
                ->get();

            $orders = $pos->count();
            $issued = $pos->whereIn('status', ['issued', 'received'])->count();
            $accepted = $pos->filter(fn ($p) => $p->accepted_at)->count();

            return [
                'vendor' => $v->name,
                'sent' => $sent,
                'quoted' => $quoted,
                'response_rate' => $sent ? (int) round($quoted / $sent * 100) : null,
                'orders' => $orders,
                'win_rate' => $quoted ? (int) round($orders / $quoted * 100) : null,
                'accept_rate' => $issued ? (int) round($accepted / $issued * 100) : null,
                'ordered_value' => round($pos->sum(fn ($p) => $this->docBase($p)), 2),
            ];
        })->filter(fn ($r) => $r['sent'] > 0 || $r['orders'] > 0)
            ->sortByDesc('ordered_value')->values();

        return response()->json(['success' => true, 'data' => [
            'base_currency' => $this->baseCurrency(),
            'rows' => $rows,
        ]]);
    }

    /** Pipeline funnel, open-item aging, and sourcing savings. */
    public function pipeline(Request $request)
    {
        [$from, $to] = $this->range($request);

        $rfqs = Rfq::withCount(['rfqVendors', 'quotes'])
            ->with(['items.awards', 'purchaseOrders'])
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
            ->get();

        $funnel = [
            ['stage' => 'Enquiries', 'count' => $rfqs->count()],
            ['stage' => 'Sent to vendors', 'count' => $rfqs->filter(fn ($r) => $r->rfq_vendors_count > 0)->count()],
            ['stage' => 'Quoted', 'count' => $rfqs->filter(fn ($r) => $r->quotes_count > 0)->count()],
            ['stage' => 'Awarded', 'count' => $rfqs->filter(fn ($r) => $r->items->contains(fn ($i) => $i->awards->isNotEmpty()))->count()],
            ['stage' => 'Ordered', 'count' => $rfqs->filter(fn ($r) => $r->purchaseOrders->isNotEmpty())->count()],
        ];

        // Aging reflects currently-open items (not date-filtered).
        $openPos = PurchaseOrder::where('status', 'issued')->get();

        return response()->json(['success' => true, 'data' => [
            'base_currency' => $this->baseCurrency(),
            'funnel' => $funnel,
            'aging_pos' => $this->ageBuckets($openPos, fn ($p) => $p->issued_date ?? $p->created_at),
            'savings' => $this->savings($from, $to),
        ]]);
    }

    private function ageBuckets($items, callable $dateFn): array
    {
        $defs = [
            ['label' => '0–30 days', 'lo' => 0, 'hi' => 30],
            ['label' => '31–60 days', 'lo' => 31, 'hi' => 60],
            ['label' => '61–90 days', 'lo' => 61, 'hi' => 90],
            ['label' => '90+ days', 'lo' => 91, 'hi' => PHP_INT_MAX],
        ];
        $out = array_map(fn ($d) => ['bucket' => $d['label'], 'count' => 0, 'value' => 0.0], $defs);
        $now = Carbon::now();

        foreach ($items as $it) {
            $date = $dateFn($it);
            if (! $date) {
                continue;
            }
            $age = Carbon::parse($date)->diffInDays($now);
            foreach ($defs as $i => $d) {
                if ($age >= $d['lo'] && $age <= $d['hi']) {
                    $out[$i]['count']++;
                    $out[$i]['value'] = round($out[$i]['value'] + $this->docBase($it), 2);
                    break;
                }
            }
        }

        return $out;
    }

    /** Estimated savings vs the average quote on competitively-sourced lines. */
    private function savings($from, $to): array
    {
        $awards = Award::with(['rfqItem.quoteItems', 'quoteItem.quote'])
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
            ->get();

        $total = 0.0;
        $lines = 0;

        foreach ($awards as $a) {
            $costs = $a->rfqItem?->quoteItems->pluck('unit_cost')->map(fn ($c) => (float) $c) ?? collect();
            if ($costs->count() < 2) {
                continue; // no competition, no measurable saving
            }
            $rate = (float) ($a->quoteItem?->quote?->exchange_rate ?? 1);
            $saving = ($costs->avg() - (float) $a->unit_cost) * (float) $a->qty_to_buy * $rate;
            if ($saving > 0) {
                $total += $saving;
                $lines++;
            }
        }

        return ['total' => round($total, 2), 'lines' => $lines, 'base_currency' => $this->baseCurrency()];
    }

    /* ------------------------------------------------------------------
     | Statement of account — one customer or one vendor at a time.
     |
     | Everything is reported PER CURRENCY and never summed across them.
     | Customer invoices carry no exchange rate, so a USD balance and an SGD
     | balance genuinely cannot be added together; showing one blended number
     | would be a made-up figure on a document about who owes what.
     |------------------------------------------------------------------ */

    /** An invoice counts as settled once it is marked paid either way. */
    private function invoicePaid(CustomerInvoice $inv): bool
    {
        return $inv->status === 'paid' || $inv->paid_at !== null;
    }

    /**
     * Fold rows into per-currency {billed, settled, outstanding} buckets.
     *
     * Each row carries its own settled figure (credit notes + payments
     * applied) rather than a paid/unpaid flag, so a part-paid invoice
     * contributes to both sides instead of landing wholly in one.
     */
    private function currencyTotals(iterable $rows): array
    {
        $by = [];

        foreach ($rows as $r) {
            $cur = $r['currency'] ?: $this->baseCurrency();
            $by[$cur] ??= ['currency' => $cur, 'billed' => 0.0, 'settled' => 0.0, 'outstanding' => 0.0, 'count' => 0];
            $by[$cur]['billed'] += $r['amount'];
            $by[$cur]['settled'] += $r['settled'];
            $by[$cur]['outstanding'] += $r['outstanding'];
            $by[$cur]['count']++;
        }

        return collect($by)->map(fn ($t) => [
            'currency' => $t['currency'],
            'billed' => round($t['billed'], 2),
            'settled' => round($t['settled'], 2),
            'outstanding' => round($t['outstanding'], 2),
            'count' => $t['count'],
        ])->sortByDesc('outstanding')->values()->all();
    }

    /**
     * Credit notes and payments already applied, keyed by document id.
     *
     * Loaded in two grouped queries for the whole page rather than a lookup
     * per row, so the party list stays one round trip regardless of size.
     */
    private function settlementIndex(string $type, array $docIds): array
    {
        if (! $docIds) {
            return ['credits' => [], 'paid' => []];
        }

        $column = $type === 'customer' ? 'customer_invoice_id' : 'purchase_order_id';

        $paid = \App\Models\PaymentAllocation::whereIn($column, $docIds)
            ->groupBy($column)
            ->selectRaw("$column as doc_id, SUM(amount) as total")
            ->pluck('total', 'doc_id')
            ->map(fn ($v) => round((float) $v, 2))
            ->all();

        $credits = $type === 'customer'
            ? \App\Models\CreditMemo::whereIn('customer_invoice_id', $docIds)
                ->where('status', 'issued')
                ->groupBy('customer_invoice_id')
                ->selectRaw('customer_invoice_id as doc_id, SUM(grand_total) as total')
                ->pluck('total', 'doc_id')
                ->map(fn ($v) => round((float) $v, 2))
                ->all()
            : [];

        return ['credits' => $credits, 'paid' => $paid];
    }

    /**
     * Settled / outstanding for one document, from the pre-loaded index —
     * the same arithmetic as {@see \App\Support\Settlement::of()} without the
     * per-row queries.
     *
     * @return array{settled: float, outstanding: float, paid: bool, partial: bool, credited: float, allocated: float, billed: float}
     */
    private function settlementOf(float $billed, int $docId, array $index, bool $manuallyPaid): array
    {
        return \App\Support\Settlement::resolve(
            $billed,
            $index['credits'][$docId] ?? 0.0,
            $index['paid'][$docId] ?? 0.0,
            $manuallyPaid,
        );
    }

    /**
     * Searchable list of customers or vendors with their headline balances.
     *
     * There are thousands of parties, so the search runs in SQL with a hard
     * limit and the money is aggregated in one grouped query per side rather
     * than a query per row.
     */
    public function statementParties(Request $request)
    {
        $data = $request->validate([
            'type' => ['required', 'in:customer,vendor'],
            'search' => ['nullable', 'string', 'max:200'],
        ]);

        $term = trim($data['search'] ?? '');
        // Not validated as a boolean: a query string carries it as the word
        // "true", which Laravel's boolean rule rejects. boolean() reads it the
        // way the browser actually sends it.
        $onlyOutstanding = $request->boolean('only_outstanding');
        $limit = 40;

        $model = $data['type'] === 'customer' ? \App\Models\Customer::class : Vendor::class;

        $query = $model::query()
            ->when($term !== '', fn ($q) => $q->where(function ($w) use ($term) {
                $w->where('name', 'like', '%'.$term.'%')->orWhere('email', 'like', '%'.$term.'%');
            }));

        // Narrow to parties that actually owe (or are owed) in SQL rather than
        // filtering a page in PHP — otherwise a debtor late in the alphabet
        // would silently never appear.
        if ($onlyOutstanding) {
            $debtors = $data['type'] === 'customer'
                ? CustomerInvoice::issued()->whereNull('paid_at')->where('status', '!=', 'paid')->distinct()->pluck('customer_id')
                : PurchaseOrder::live()->whereNull('paid_at')->distinct()->pluck('vendor_id');

            $query->whereIn('id', $debtors->filter()->all() ?: [0]);
        }

        $parties = $query->orderBy('name')->limit($limit + 1)->get(['id', 'name', 'email']);

        if ($parties->isEmpty()) {
            return response()->json(['success' => true, 'data' => ['parties' => [], 'truncated' => false]]);
        }

        $ids = $parties->pluck('id')->all();

        // One pass over the documents for the whole page of parties.
        if ($data['type'] === 'customer') {
            $docs = CustomerInvoice::issued()->whereIn('customer_id', $ids)
                ->get(['id', 'customer_id', 'currency', 'grand_total', 'status', 'paid_at', 'due_date'])
                ->groupBy('customer_id');
        } else {
            $docs = PurchaseOrder::live()->whereIn('vendor_id', $ids)
                ->get(['id', 'vendor_id', 'currency', 'subtotal', 'receipt_amount', 'has_credit_note', 'credit_note_amount', 'status', 'paid_at'])
                ->groupBy('vendor_id');
        }

        // Credit notes and payments for every document on this page, in two
        // grouped queries rather than a lookup per row.
        $index = $this->settlementIndex($data['type'], $docs->flatten()->pluck('id')->all());

        $today = Carbon::today();

        $rows = $parties->map(function ($p) use ($data, $docs, $index, $today) {
            $mine = $docs->get($p->id, collect());
            $isCustomer = $data['type'] === 'customer';

            $flat = $mine->map(function ($d) use ($isCustomer, $index) {
                $amount = $isCustomer
                    ? (float) $d->grand_total
                    : $d->vendorNetAmount();

                $s = $this->settlementOf(
                    round($amount, 2),
                    $d->id,
                    $index,
                    $isCustomer ? $this->invoicePaid($d) : $d->paid_at !== null
                );

                return [
                    'currency' => $d->currency,
                    'amount' => round($amount, 2),
                    'settled' => $s['settled'],
                    'outstanding' => $s['outstanding'],
                    'paid' => $s['paid'],
                ];
            });

            $totals = $this->currencyTotals($flat);

            // Age of the oldest document still carrying a balance. $flat keeps
            // $mine's keys, so a row's settlement is looked up by the same key.
            $oldest = null;
            if ($isCustomer) {
                $oldest = $mine->filter(fn ($d, $k) => ! $flat[$k]['paid'])
                    ->pluck('due_date')->filter()->min();
            }

            return [
                'id' => $p->id,
                'name' => $p->name,
                'email' => $p->email,
                'doc_count' => $mine->count(),
                'totals' => $totals,
                'overdue_days' => $oldest ? max(0, Carbon::parse($oldest)->diffInDays($today, false)) : null,
            ];
        });

        // Second pass in PHP: a credit note or a payment can clear an invoice
        // the SQL pre-filter above still counts as unpaid.
        if ($onlyOutstanding) {
            $rows = $rows->filter(fn ($r) => collect($r['totals'])->contains(fn ($t) => abs($t['outstanding']) > 0.005));
        }

        return response()->json(['success' => true, 'data' => [
            'parties' => $rows->take($limit)->values(),
            // One extra row was fetched purely to detect "there are more".
            'truncated' => $rows->count() > $limit,
        ]]);
    }

    /** Full statement of account for one customer or vendor. */
    public function statement(Request $request, string $type, int $id)
    {
        abort_unless(in_array($type, ['customer', 'vendor'], true), 404);

        [$from, $to] = $this->range($request);
        $today = Carbon::today();
        // Off by default: a draft is not money owed. On when the user wants to
        // see work in progress alongside what has actually been billed.
        $includeDrafts = $request->boolean('include_drafts');

        $party = $type === 'customer'
            ? \App\Models\Customer::find($id)
            : Vendor::find($id);

        abort_unless($party, 404);

        if ($type === 'customer') {
            $invoices = CustomerInvoice::issued($includeDrafts)
                ->with(['rfq:id,reference,ship_name'])
                ->where('customer_id', $id)
                ->when($from, fn ($q) => $q->where('issue_date', '>=', $from))
                ->when($to, fn ($q) => $q->where('issue_date', '<=', $to))
                ->orderByDesc('issue_date')->orderByDesc('id')
                ->get();

            $index = $this->settlementIndex('customer', $invoices->pluck('id')->all());

            $lines = $invoices->map(function (CustomerInvoice $inv) use ($today, $index) {
                $billed = round((float) $inv->grand_total, 2);
                $s = $this->settlementOf($billed, $inv->id, $index, $this->invoicePaid($inv));
                $due = $inv->due_date ? Carbon::parse($inv->due_date) : null;

                return [
                    'id' => $inv->id,
                    'kind' => 'invoice',
                    'number' => $inv->invoice_number,
                    'reference' => $inv->rfq?->reference,
                    'vessel' => $inv->rfq?->ship_name,
                    'date' => optional($inv->issue_date)->toDateString(),
                    'due_date' => $due?->toDateString(),
                    'currency' => $inv->currency,
                    'amount' => $billed,
                    'credited' => $s['credited'],
                    'allocated' => $s['allocated'],
                    'settled' => $s['settled'],
                    'outstanding' => $s['outstanding'],
                    'status' => $inv->status,
                    'paid' => $s['paid'],
                    'partial' => $s['partial'],
                    'paid_at' => optional($inv->paid_at)->toDateString(),
                    // Negative = still within terms; positive = days late.
                    'overdue_days' => (! $s['paid'] && $due) ? max(0, $due->diffInDays($today, false)) : 0,
                ];
            });

            $memos = \App\Models\CreditMemo::where('customer_id', $id)
                ->where('status', 'issued')
                ->when($from, fn ($q) => $q->where('memo_date', '>=', $from))
                ->when($to, fn ($q) => $q->where('memo_date', '<=', $to))
                ->orderByDesc('memo_date')
                ->get(['id', 'cm_number', 'memo_date', 'currency', 'grand_total', 'reason']);

            $credits = $memos->map(fn ($m) => [
                'id' => $m->id,
                'kind' => 'credit',
                'number' => $m->cm_number,
                'date' => optional($m->memo_date)->toDateString(),
                'currency' => $m->currency,
                'amount' => round((float) $m->grand_total, 2),
                'reason' => $m->reason,
            ]);
        } else {
            $orders = PurchaseOrder::live()
                ->with(['rfq:id,reference,ship_name'])
                ->where('vendor_id', $id)
                ->when($from, fn ($q) => $q->whereDate('created_at', '>=', $from))
                ->when($to, fn ($q) => $q->whereDate('created_at', '<=', $to))
                ->orderByDesc('issued_date')->orderByDesc('id')
                ->get();

            $index = $this->settlementIndex('vendor', $orders->pluck('id')->all());

            $lines = $orders->map(function (PurchaseOrder $po) use ($index) {
                // What we owe this vendor is the goods figure — the receipted
                // amount once known, otherwise what was ordered. Third-party
                // expenses are reported separately, not added to their balance.
                $amount = round($po->vendorNetAmount(), 2);
                $s = $this->settlementOf($amount, $po->id, $index, $po->paid_at !== null);

                return [
                    'id' => $po->id,
                    'kind' => 'po',
                    'number' => $po->po_number,
                    'reference' => $po->rfq?->reference,
                    'vessel' => $po->rfq?->ship_name ?: $po->ship_name,
                    'date' => optional($po->issued_date)->toDateString() ?: optional($po->created_at)->toDateString(),
                    'due_date' => optional($po->expected_date)->toDateString(),
                    'currency' => $po->currency,
                    'ordered' => round((float) $po->subtotal, 2),
                    'amount' => $amount,
                    'credited' => round($po->vendorCreditAmount(), 2),
                    'credit_note_number' => $po->has_credit_note ? $po->credit_note_number : null,
                    'allocated' => $s['allocated'],
                    'settled' => $s['settled'],
                    'outstanding' => $s['outstanding'],
                    'receipted' => $po->receipt_amount !== null,
                    'expenses' => round((float) $po->expenses, 2),
                    'expense_currency' => $po->expense_currency ?: $po->currency,
                    'status' => $po->status,
                    'paid' => $s['paid'],
                    'partial' => $s['partial'],
                    'paid_at' => optional($po->paid_at)->toDateString(),
                    'overdue_days' => 0,
                ];
            });

            $credits = collect();
        }

        // Credit notes are already netted off each line by settlementOf(), so
        // they are NOT subtracted again here — that would count them twice.
        $totals = $this->currencyTotals($lines->map(fn ($l) => [
            'currency' => $l['currency'],
            'amount' => $l['amount'],
            'settled' => $l['settled'],
            'outstanding' => $l['outstanding'],
        ]));

        // Ageing of what is still outstanding, per bucket. A part-paid invoice
        // ages only by its remaining balance, not its full face value.
        $aging = ['current' => 0.0, 'd30' => 0.0, 'd60' => 0.0, 'd90' => 0.0];
        foreach ($lines->where('paid', false) as $l) {
            $d = $l['overdue_days'];
            $key = $d <= 0 ? 'current' : ($d <= 30 ? 'd30' : ($d <= 60 ? 'd60' : 'd90'));
            $aging[$key] += $l['outstanding'];
        }
        $aging = array_map(fn ($v) => round($v, 2), $aging);

        // Payments recorded against this party in the same window, so the
        // statement shows the receipts as well as what they settled.
        $payments = \App\Models\Payment::with([
            'allocations.invoice:id,invoice_number',
            'allocations.purchaseOrder:id,po_number',
            'attachments:id,payment_id,original_name,mime_type,size,kind',
        ])
            ->where('party_type', $type)
            ->where($type === 'customer' ? 'customer_id' : 'vendor_id', $id)
            ->when($from, fn ($q) => $q->whereDate('payment_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('payment_date', '<=', $to))
            ->orderByDesc('payment_date')->orderByDesc('id')
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'number' => $p->payment_number,
                'date' => optional($p->payment_date)->toDateString(),
                'currency' => $p->currency,
                'amount' => round((float) $p->amount, 2),
                'allocated' => $p->allocatedAmount(),
                'unapplied' => $p->unappliedAmount(),
                'method' => $p->method,
                'reference' => $p->reference,
                'bank_account' => $p->bank_account,
                'account_code' => $p->account_code,
                'notes' => $p->notes,
                'applied_to' => $p->allocations->map(fn ($a) => [
                    'document_id' => $a->customer_invoice_id ?: $a->purchase_order_id,
                    'number' => $a->invoice?->invoice_number ?: $a->purchaseOrder?->po_number,
                    'amount' => round((float) $a->amount, 2),
                ])->values(),
                'attachments' => $p->attachments->map(fn ($f) => [
                    'id' => $f->id,
                    'original_name' => $f->original_name,
                    'mime_type' => $f->mime_type,
                    'size' => $f->size,
                    'kind' => $f->kind,
                ])->values(),
            ]);

        return response()->json(['success' => true, 'data' => [
            'party' => ['id' => $party->id, 'name' => $party->name, 'email' => $party->email, 'type' => $type],
            'lines' => $lines->values(),
            'credits' => $credits->values(),
            'payments' => $payments->values(),
            'totals' => $totals,
            'aging' => $aging,
            'stats' => $this->partyStats($type, $id, $from, $to, $lines, $payments, $includeDrafts),
            'include_drafts' => $includeDrafts,
            // So the screen can offer the toggle only when it would change something.
            'drafts_available' => $type === 'customer'
                ? CustomerInvoice::where('customer_id', $id)->where('status', 'draft')->count()
                : 0,
            // A single-currency party is the normal case; the UI simplifies then.
            'multi_currency' => count($totals) > 1,
        ]]);
    }

    /**
     * The statement as a PDF, for chasing a debt.
     *
     * Only documents that still carry a balance are printed — a statement of
     * account is a request for payment, so a fully settled invoice on it just
     * invites an argument. Reuses statement() so the paper and the screen can
     * never disagree.
     */
    public function statementPdf(Request $request, string $type, int $id)
    {
        abort_unless(in_array($type, ['customer', 'vendor'], true), 404);

        $payload = json_decode($this->statement($request, $type, $id)->getContent(), true)['data'];

        $party = $type === 'customer' ? \App\Models\Customer::find($id) : Vendor::find($id);
        abort_unless($party, 404);

        $open = collect($payload['lines'])
            ->filter(fn ($l) => $l['outstanding'] > \App\Support\Settlement::EPSILON)
            ->sortBy(fn ($l) => $l['due_date'] ?: $l['date'])
            ->values();

        // Totals are recomputed from the printed rows only, so the figure at
        // the bottom always equals the column above it.
        $totals = collect($open)->groupBy('currency')->map(fn ($rows, $cur) => [
            'currency' => $cur,
            'outstanding' => round($rows->sum('outstanding'), 2),
        ])->sortByDesc('outstanding')->values()->all();

        [$from, $to] = $this->range($request);
        $periodLabel = $from || $to
            ? ($from?->format('d.m.y') ?: 'start').' – '.($to?->format('d.m.y') ?: Carbon::today()->format('d.m.y'))
            : 'All open items';
        $periodSentence = $from || $to
            ? ' for the period '.($from?->format('d.m.Y') ?: 'the beginning').' to '.($to?->format('d.m.Y') ?: Carbon::today()->format('d.m.Y'))
            : '';

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.statement', [
            'party' => $party,
            'isCustomer' => $type === 'customer',
            'lines' => $open->all(),
            'totals' => $totals,
            'payments' => $payload['payments'],
            'aging' => $payload['aging'],
            'showAging' => $type === 'customer' && $open->isNotEmpty(),
            'multiCurrency' => count($totals) > 1,
            // Used to suppress a per-row currency tag when there is only one.
            'singleCurrency' => count($totals) === 1 ? $totals[0]['currency'] : null,
            'periodLabel' => $periodLabel,
            'periodSentence' => $periodSentence,
            'issuedOn' => Carbon::today()->format('d.m.Y'),
            'company' => config('procurement.company'),
            'logo' => is_file(public_path('logo.png'))
                ? 'data:image/png;base64,'.base64_encode(file_get_contents(public_path('logo.png')))
                : null,
        ]);

        return $pdf->download('Statement-'.\Illuminate\Support\Str::slug($party->name).'-'.Carbon::today()->format('Y-m-d').'.pdf');
    }

    /**
     * The at-a-glance panel: how much business this party has done and what
     * they still owe.
     *
     * Two different questions live here and are deliberately kept apart:
     *
     *   · activity — sales, payments, document counts — answers "in this
     *     period", so it follows the from/to filter;
     *   · balance — outstanding, overdue — answers "right now", so it ignores
     *     the filter. A debt does not stop existing because you narrowed the
     *     dates, and showing it as if it did would understate what to chase.
     */
    private function partyStats(string $type, int $id, ?Carbon $from, ?Carbon $to, $lines, $payments, bool $includeDrafts = false): array
    {
        $isCustomer = $type === 'customer';
        $today = Carbon::today();

        /* ---- activity in the selected period (from the rows already loaded) ---- */
        $byCurrency = [];
        foreach ($lines as $l) {
            $cur = $l['currency'] ?: $this->baseCurrency();
            $byCurrency[$cur] ??= ['currency' => $cur, 'sales' => 0.0, 'credited' => 0.0, 'received' => 0.0];
            // Net of credit notes — a credited invoice was never really a sale.
            $byCurrency[$cur]['sales'] += $l['amount'] - $l['credited'];
            $byCurrency[$cur]['credited'] += $l['credited'];
        }
        foreach ($payments as $p) {
            $cur = $p['currency'] ?: $this->baseCurrency();
            $byCurrency[$cur] ??= ['currency' => $cur, 'sales' => 0.0, 'credited' => 0.0, 'received' => 0.0];
            $byCurrency[$cur]['received'] += $p['amount'];
        }

        $period = collect($byCurrency)->map(fn ($v) => [
            'currency' => $v['currency'],
            'sales' => round($v['sales'], 2),
            'credited' => round($v['credited'], 2),
            'received' => round($v['received'], 2),
        ])->sortByDesc('sales')->values()->all();

        /* ---- balance as of today, ignoring the date filter ---- */
        if ($isCustomer) {
            $all = CustomerInvoice::issued($includeDrafts)->where('customer_id', $id)
                ->get(['id', 'currency', 'grand_total', 'status', 'paid_at', 'due_date']);
            $amountOf = fn ($d) => round((float) $d->grand_total, 2);
            $manual = fn ($d) => $this->invoicePaid($d);
        } else {
            $all = PurchaseOrder::live()->where('vendor_id', $id)
                ->get(['id', 'currency', 'subtotal', 'receipt_amount', 'has_credit_note', 'credit_note_amount', 'status', 'paid_at', 'expected_date']);
            $amountOf = fn ($d) => round($d->vendorNetAmount(), 2);
            $manual = fn ($d) => $d->paid_at !== null;
        }

        $index = $this->settlementIndex($type, $all->pluck('id')->all());

        $balance = [];
        $openCount = 0;
        $overdueCount = 0;
        $oldestDue = null;

        foreach ($all as $d) {
            $amount = $amountOf($d);
            $s = $this->settlementOf($amount, $d->id, $index, $manual($d));
            $cur = $d->currency ?: $this->baseCurrency();
            $balance[$cur] ??= ['currency' => $cur, 'billed' => 0.0, 'outstanding' => 0.0, 'overdue' => 0.0];
            $balance[$cur]['billed'] += $amount;
            $balance[$cur]['outstanding'] += $s['outstanding'];

            if ($s['outstanding'] > \App\Support\Settlement::EPSILON) {
                $openCount++;
                $due = $isCustomer ? $d->due_date : $d->expected_date;
                if ($due && Carbon::parse($due)->lt($today)) {
                    $balance[$cur]['overdue'] += $s['outstanding'];
                    $overdueCount++;
                }
                if ($due && (! $oldestDue || Carbon::parse($due)->lt($oldestDue))) {
                    $oldestDue = Carbon::parse($due);
                }
            }
        }

        $balance = collect($balance)->map(fn ($v) => [
            'currency' => $v['currency'],
            'billed' => round($v['billed'], 2),
            'outstanding' => round($v['outstanding'], 2),
            'overdue' => round($v['overdue'], 2),
        ])->sortByDesc('outstanding')->values()->all();

        /* ---- document counts in the period ---- */
        $inRange = fn ($q, string $column) => $q
            ->when($from, fn ($x) => $x->whereDate($column, '>=', $from))
            ->when($to, fn ($x) => $x->whereDate($column, '<=', $to));

        if ($isCustomer) {
            $counts = [
                'Enquiries' => $inRange(\App\Models\Rfq::where('customer_id', $id), 'created_at')->count(),
                'Quotations' => $inRange(\App\Models\Offer::where('customer_id', $id), 'created_at')->count(),
                'Delivery orders' => $inRange(\App\Models\DeliveryOrder::where('customer_id', $id), 'created_at')->count(),
                'Invoices' => $lines->count(),
                'Credit notes' => \App\Models\CreditMemo::where('customer_id', $id)->where('status', 'issued')
                    ->when($from, fn ($q) => $q->whereDate('memo_date', '>=', $from))
                    ->when($to, fn ($q) => $q->whereDate('memo_date', '<=', $to))->count(),
                'Payments' => $payments->count(),
            ];
        } else {
            $counts = [
                'Enquiries sent' => $inRange(\App\Models\RfqVendor::where('vendor_id', $id), 'created_at')->count(),
                'Quotes received' => $inRange(\App\Models\Quote::where('vendor_id', $id), 'created_at')->count(),
                'Purchase orders' => $lines->count(),
                'Payments' => $payments->count(),
            ];
        }

        /* ---- collection behaviour: how long they actually take to pay ---- */
        $avgDays = null;
        $lastPayment = null;

        $settled = \App\Models\PaymentAllocation::query()
            ->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
            ->where('payments.party_type', $type)
            ->where('payments.'.($isCustomer ? 'customer_id' : 'vendor_id'), $id)
            ->when($isCustomer, fn ($q) => $q
                ->join('customer_invoices', 'customer_invoices.id', '=', 'payment_allocations.customer_invoice_id')
                ->select('payments.payment_date', 'customer_invoices.issue_date as doc_date'))
            ->when(! $isCustomer, fn ($q) => $q
                ->join('purchase_orders', 'purchase_orders.id', '=', 'payment_allocations.purchase_order_id')
                ->select('payments.payment_date', 'purchase_orders.issued_date as doc_date'))
            ->get();

        $spans = $settled->filter(fn ($r) => $r->doc_date && $r->payment_date)
            ->map(fn ($r) => Carbon::parse($r->doc_date)->diffInDays(Carbon::parse($r->payment_date), false))
            ->filter(fn ($d) => $d >= 0);

        if ($spans->isNotEmpty()) {
            $avgDays = (int) round($spans->avg());
        }
        if ($settled->isNotEmpty()) {
            $lastPayment = $settled->max('payment_date');
        }

        return [
            // Echoed back so the panel can label itself honestly.
            'from' => $from?->toDateString(),
            'to' => $to?->toDateString(),
            'period' => $period,
            'balance' => $balance,
            'counts' => $counts,
            'open_documents' => $openCount,
            'overdue_documents' => $overdueCount,
            'oldest_due' => $oldestDue?->toDateString(),
            'oldest_due_days' => $oldestDue ? max(0, $oldestDue->diffInDays($today, false)) : null,
            'avg_days_to_pay' => $avgDays,
            'last_payment' => $lastPayment ? Carbon::parse($lastPayment)->toDateString() : null,
        ];
    }
}

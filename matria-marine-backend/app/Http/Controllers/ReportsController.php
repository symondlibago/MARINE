<?php

namespace App\Http\Controllers;

use App\Models\Award;
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

        $pos = PurchaseOrder::with(['vendor:id,name', 'rfq:id,ship_name'])
            ->where('status', '!=', 'cancelled')
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
        $invoices = CustomerInvoice::with('rfq:id,reference,ship_name')
            ->when($from, fn ($q) => $q->where('issue_date', '>=', $from))
            ->when($to, fn ($q) => $q->where('issue_date', '<=', $to))
            ->orderByDesc('issue_date')
            ->get();

        // Cost side: the POs behind each job, grouped by enquiry.
        $rfqIds = $invoices->pluck('rfq_id')->filter()->unique()->all();
        $posByRfq = PurchaseOrder::withCount('attachments')
            ->with('vendor:id,name')
            ->whereIn('rfq_id', $rfqIds)
            ->where('status', '!=', 'cancelled')
            ->get()
            ->groupBy('rfq_id');

        // A job's POs are attributed to the FIRST invoice seen for that enquiry, so a
        // second invoice on the same job doesn't double-count the vendor cost.
        $costedRfq = [];

        $rows = $invoices->map(function (CustomerInvoice $inv) use ($posByRfq, &$costedRfq) {
            $gross = (float) $inv->grand_total - (float) $inv->tax_amount; // ex-GST: tax isn't income
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
                $cost = ($po->receipt_amount !== null ? (float) $po->receipt_amount : (float) $po->subtotal) * $rate;
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

        $sum = fn ($k) => round($rows->sum($k), 2);
        $revenue = $sum('gross');
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

            $pos = PurchaseOrder::where('vendor_id', $v->id)->where('status', '!=', 'cancelled')
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
            ->with(['items.award', 'purchaseOrders'])
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
            ->get();

        $funnel = [
            ['stage' => 'Enquiries', 'count' => $rfqs->count()],
            ['stage' => 'Sent to vendors', 'count' => $rfqs->filter(fn ($r) => $r->rfq_vendors_count > 0)->count()],
            ['stage' => 'Quoted', 'count' => $rfqs->filter(fn ($r) => $r->quotes_count > 0)->count()],
            ['stage' => 'Awarded', 'count' => $rfqs->filter(fn ($r) => $r->items->contains(fn ($i) => $i->award))->count()],
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
}

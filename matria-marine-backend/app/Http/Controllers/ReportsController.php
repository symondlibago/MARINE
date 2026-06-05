<?php

namespace App\Http\Controllers;

use App\Models\Award;
use App\Models\PurchaseInvoice;
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

    /** Amount of a PO/invoice converted to its base currency. */
    private function docBase($doc): float
    {
        $amount = $doc instanceof PurchaseOrder ? $doc->subtotal : $doc->total;

        return (float) $amount * (float) $doc->exchange_rate;
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

        $invs = PurchaseInvoice::where('status', '!=', 'cancelled')
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

        $multiBase = $pos->pluck('base_currency')->merge($invs->pluck('base_currency'))->filter()->unique()->count() > 1;

        return response()->json(['success' => true, 'data' => [
            'base_currency' => $this->baseCurrency(),
            'multi_base' => $multiBase,
            'totals' => [
                'ordered' => round($pos->sum($poBase), 2),
                'invoiced' => round($invs->sum(fn (PurchaseInvoice $i) => $this->docBase($i)), 2),
                'po_count' => $pos->count(),
                'invoice_count' => $invs->count(),
            ],
            'by_vendor' => $byVendor,
            'by_vessel' => $byVessel,
            'monthly' => $monthly,
        ]]);
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
                'nav_code' => $v->nav_code,
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
            ->with(['items.award', 'purchaseOrders', 'purchaseInvoices'])
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
            ->get();

        $funnel = [
            ['stage' => 'Enquiries', 'count' => $rfqs->count()],
            ['stage' => 'Sent to vendors', 'count' => $rfqs->filter(fn ($r) => $r->rfq_vendors_count > 0)->count()],
            ['stage' => 'Quoted', 'count' => $rfqs->filter(fn ($r) => $r->quotes_count > 0)->count()],
            ['stage' => 'Awarded', 'count' => $rfqs->filter(fn ($r) => $r->items->contains(fn ($i) => $i->award))->count()],
            ['stage' => 'Ordered', 'count' => $rfqs->filter(fn ($r) => $r->purchaseOrders->isNotEmpty())->count()],
            ['stage' => 'Invoiced', 'count' => $rfqs->filter(fn ($r) => $r->purchaseInvoices->isNotEmpty())->count()],
        ];

        // Aging reflects currently-open items (not date-filtered).
        $openPos = PurchaseOrder::where('status', 'issued')->get();
        $openInvs = PurchaseInvoice::whereIn('status', ['draft', 'approved'])->get();

        return response()->json(['success' => true, 'data' => [
            'base_currency' => $this->baseCurrency(),
            'funnel' => $funnel,
            'aging_pos' => $this->ageBuckets($openPos, fn ($p) => $p->issued_date ?? $p->created_at),
            'aging_invoices' => $this->ageBuckets($openInvs, fn ($i) => $i->invoice_date ?? $i->created_at),
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

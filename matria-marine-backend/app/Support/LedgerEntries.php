<?php

namespace App\Support;

use App\Models\CreditMemo;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PurchaseOrder;
use App\Models\Vendor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Every movement on every account, in date order, grouped by party.
 *
 * The sibling of {@see OpenEntries}, and the distinction is the whole point:
 *
 *   · OpenEntries  — "who still owes me, as at this date"  (what is left)
 *   · LedgerEntries — "everything that happened, in order"  (what occurred)
 *
 * So invoices, credit notes and payments all appear here as their own rows,
 * settled or not, the way they do in a NAV ledger export. Balances are still
 * resolved through Settlement::resolve() so a remaining figure means the same
 * thing on every screen in the system.
 *
 * Cost is a fixed handful of queries regardless of how many parties come back.
 */
class LedgerEntries
{
    /** Beyond this the export stops being a spreadsheet and becomes a database. */
    public const MAX_ROWS = 20000;

    /**
     * @return array{
     *   type: string, from: ?string, to: ?string,
     *   parties: array, grand_totals: array,
     *   party_count: int, row_count: int, truncated: bool
     * }
     */
    public static function build(string $type, ?Carbon $from = null, ?Carbon $to = null, bool $includeDrafts = false): array
    {
        $isCustomer = $type === 'customer';
        $partyKey = $isCustomer ? 'customer_id' : 'vendor_id';

        $documents = self::documents($isCustomer, $from, $to, $includeDrafts);
        $credits = $isCustomer ? self::credits($from, $to) : collect();
        $payments = self::payments($isCustomer, $from, $to);

        // Balances are "as things stand now", not date-bounded: a remaining
        // amount answers what is still owed, which does not depend on the
        // window you happen to be looking through.
        $docIds = $documents->pluck('id')->all();
        $allocated = $docIds ? self::allocatedOn($isCustomer, $docIds) : [];
        $credited = ($isCustomer && $docIds) ? self::creditedOn($docIds) : [];

        $rows = collect()
            ->concat($documents->map(fn ($d) => self::documentRow($d, $isCustomer, $partyKey, $allocated, $credited)))
            ->concat($credits->map(fn ($c) => self::creditRow($c)))
            ->concat($payments->map(fn ($p) => self::paymentRow($p, $partyKey)))
            ->filter(fn ($r) => $r['party_id'] > 0);

        if ($rows->isEmpty()) {
            return self::empty($type, $from, $to);
        }

        $byParty = $rows->groupBy('party_id');
        $names = ($isCustomer ? Customer::class : Vendor::class)::whereIn('id', $byParty->keys())
            ->get(['id', 'name', 'email'])
            ->keyBy('id');

        $parties = $byParty
            ->map(function (Collection $partyRows, $pid) use ($names) {
                $ordered = $partyRows
                    ->sortBy([['date', 'asc'], ['number', 'asc']])
                    ->values();

                return [
                    'id' => (int) $pid,
                    'name' => $names[$pid]->name ?? 'Deleted party',
                    'email' => $names[$pid]->email ?? null,
                    'entries' => $ordered->all(),
                    'totals' => self::totalsFor($ordered),
                ];
            })
            ->sortBy(fn ($p) => mb_strtolower($p['name']))
            ->values();

        $rowCount = $parties->sum(fn ($p) => count($p['entries']));

        return [
            'type' => $type,
            'include_drafts' => $includeDrafts,
            'from' => $from?->toDateString(),
            'to' => $to?->toDateString(),
            'parties' => $parties->all(),
            'grand_totals' => self::grandTotals($parties),
            'party_count' => $parties->count(),
            'row_count' => $rowCount,
            // Reported, never silently applied — the caller decides what to say.
            'truncated' => $rowCount > self::MAX_ROWS,
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Loaders                                                            */
    /* ------------------------------------------------------------------ */

    private static function documents(bool $isCustomer, ?Carbon $from, ?Carbon $to, bool $includeDrafts = false): Collection
    {
        if ($isCustomer) {
            return CustomerInvoice::issued($includeDrafts)
                ->whereNotNull('customer_id')
                ->when($from, fn ($q) => $q->whereDate('issue_date', '>=', $from))
                ->when($to, fn ($q) => $q->whereDate('issue_date', '<=', $to))
                ->with('rfq:id,reference,ship_name')
                ->get(['id', 'customer_id', 'invoice_number', 'rfq_id', 'currency',
                    'grand_total', 'status', 'paid_at', 'issue_date', 'due_date']);
        }

        // Not every PO carries an issued date, so the range has to consider the
        // date it was raised as the fallback — the same rule the row shape uses.
        $inRange = fn ($q, string $op, Carbon $bound) => $q->where(fn ($w) => $w
            ->whereDate('issued_date', $op, $bound)
            ->orWhere(fn ($x) => $x->whereNull('issued_date')->whereDate('created_at', $op, $bound)));

        return PurchaseOrder::live()
            ->whereNotNull('vendor_id')
            ->when($from, fn ($q) => $inRange($q, '>=', $from))
            ->when($to, fn ($q) => $inRange($q, '<=', $to))
            ->with('rfq:id,reference,ship_name')
            ->get(['id', 'vendor_id', 'po_number', 'rfq_id', 'ship_name', 'currency',
                'subtotal', 'receipt_amount', 'status', 'paid_at', 'issued_date', 'expected_date', 'created_at']);
    }

    private static function credits(?Carbon $from, ?Carbon $to): Collection
    {
        return CreditMemo::query()
            ->where('status', 'issued')
            ->whereNotNull('customer_id')
            ->when($from, fn ($q) => $q->whereDate('memo_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('memo_date', '<=', $to))
            ->with('invoice:id,invoice_number')
            ->get(['id', 'customer_id', 'customer_invoice_id', 'cm_number', 'memo_date', 'currency', 'grand_total', 'reason']);
    }

    private static function payments(bool $isCustomer, ?Carbon $from, ?Carbon $to): Collection
    {
        return Payment::query()
            ->where('party_type', $isCustomer ? 'customer' : 'vendor')
            ->when($from, fn ($q) => $q->whereDate('payment_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('payment_date', '<=', $to))
            ->with(['allocations.invoice:id,invoice_number', 'allocations.purchaseOrder:id,po_number'])
            ->get();
    }

    private static function allocatedOn(bool $isCustomer, array $docIds): array
    {
        $column = $isCustomer ? 'customer_invoice_id' : 'purchase_order_id';

        return PaymentAllocation::query()
            ->whereIn($column, $docIds)
            ->groupBy($column)
            ->selectRaw("$column as doc_id, SUM(amount) as total")
            ->pluck('total', 'doc_id')
            ->map(fn ($v) => round((float) $v, 2))
            ->all();
    }

    private static function creditedOn(array $docIds): array
    {
        return CreditMemo::query()
            ->whereIn('customer_invoice_id', $docIds)
            ->where('status', 'issued')
            ->groupBy('customer_invoice_id')
            ->selectRaw('customer_invoice_id as doc_id, SUM(grand_total) as total')
            ->pluck('total', 'doc_id')
            ->map(fn ($v) => round((float) $v, 2))
            ->all();
    }

    /* ------------------------------------------------------------------ */
    /*  Row shapes — one per movement                                      */
    /* ------------------------------------------------------------------ */

    private static function documentRow($d, bool $isCustomer, string $partyKey, array $allocated, array $credited): array
    {
        $billed = $isCustomer
            ? round((float) $d->grand_total, 2)
            : round((float) ($d->receipt_amount !== null ? $d->receipt_amount : $d->subtotal), 2);

        $manuallyPaid = $isCustomer
            ? ($d->status === 'paid' || $d->paid_at !== null)
            : $d->paid_at !== null;

        $s = Settlement::resolve($billed, $credited[$d->id] ?? 0.0, $allocated[$d->id] ?? 0.0, $manuallyPaid);
        $date = $isCustomer ? $d->issue_date : ($d->issued_date ?: $d->created_at);

        return [
            'party_id' => (int) $d->{$partyKey},
            'kind' => $isCustomer ? 'invoice' : 'po',
            'type' => $isCustomer ? 'Invoice' : 'Purchase order',
            'number' => $isCustomer ? $d->invoice_number : $d->po_number,
            'reference' => $d->rfq?->reference,
            'vessel' => $d->rfq?->ship_name ?: ($d->ship_name ?? null),
            'description' => null,
            'date' => optional($date)->toDateString(),
            'due_date' => optional($isCustomer ? $d->due_date : $d->expected_date)->toDateString(),
            'currency' => $d->currency,
            // Positive: it increases what the party owes.
            'amount' => $billed,
            'settled' => $s['settled'],
            'outstanding' => $s['outstanding'],
            'open' => ! $s['paid'],
            'status' => $s['paid'] ? 'Closed' : ($s['partial'] ? 'Part-paid' : 'Open'),
            'bank_account' => null,
            'applied_to' => null,
        ];
    }

    private static function creditRow($c): array
    {
        return [
            'party_id' => (int) $c->customer_id,
            'kind' => 'credit',
            'type' => 'Credit note',
            'number' => $c->cm_number,
            'reference' => $c->invoice?->invoice_number,
            'vessel' => null,
            'description' => $c->reason,
            'date' => optional($c->memo_date)->toDateString(),
            'due_date' => null,
            'currency' => $c->currency,
            // Negative: it reduces what the party owes.
            'amount' => -round((float) $c->grand_total, 2),
            'settled' => 0.0,
            'outstanding' => 0.0,
            'open' => false,
            'status' => 'Credited',
            'bank_account' => null,
            'applied_to' => $c->invoice?->invoice_number,
        ];
    }

    private static function paymentRow(Payment $p, string $partyKey): array
    {
        $applied = $p->allocations
            ->map(fn ($a) => ($a->invoice?->invoice_number ?: $a->purchaseOrder?->po_number).' ('.number_format((float) $a->amount, 2).')')
            ->filter()->implode(', ');

        $unapplied = $p->unappliedAmount();

        return [
            'party_id' => (int) $p->{$partyKey},
            'kind' => 'payment',
            'type' => $p->direction === 'in' ? 'Payment received' : 'Payment made',
            'number' => $p->payment_number,
            'reference' => $p->reference,
            'vessel' => null,
            'description' => $p->notes,
            'date' => optional($p->payment_date)->toDateString(),
            'due_date' => null,
            'currency' => $p->currency,
            'amount' => -round((float) $p->amount, 2),
            'settled' => 0.0,
            // What of this payment is still sitting on account.
            'outstanding' => $unapplied,
            'open' => $unapplied > Settlement::EPSILON,
            'status' => $unapplied > Settlement::EPSILON ? 'On account' : 'Applied',
            'bank_account' => $p->bank_account,
            'applied_to' => $applied ?: null,
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Totals                                                             */
    /* ------------------------------------------------------------------ */

    private static function totalsFor(Collection $entries): array
    {
        $by = [];

        foreach ($entries as $e) {
            $cur = $e['currency'];
            $by[$cur] ??= ['currency' => $cur, 'billed' => 0.0, 'credited' => 0.0, 'paid' => 0.0, 'outstanding' => 0.0];

            if ($e['kind'] === 'credit') {
                $by[$cur]['credited'] += abs($e['amount']);
            } elseif ($e['kind'] === 'payment') {
                $by[$cur]['paid'] += abs($e['amount']);
            } else {
                $by[$cur]['billed'] += $e['amount'];
                $by[$cur]['outstanding'] += $e['outstanding'];
            }
        }

        return collect($by)->map(fn ($t) => [
            'currency' => $t['currency'],
            'billed' => round($t['billed'], 2),
            'credited' => round($t['credited'], 2),
            'paid' => round($t['paid'], 2),
            'outstanding' => round($t['outstanding'], 2),
        ])->sortByDesc('billed')->values()->all();
    }

    private static function grandTotals(Collection $parties): array
    {
        $by = [];

        foreach ($parties as $p) {
            foreach ($p['totals'] as $t) {
                $cur = $t['currency'];
                $by[$cur] ??= ['currency' => $cur, 'billed' => 0.0, 'credited' => 0.0, 'paid' => 0.0, 'outstanding' => 0.0, 'parties' => 0];
                foreach (['billed', 'credited', 'paid', 'outstanding'] as $k) {
                    $by[$cur][$k] += $t[$k];
                }
                $by[$cur]['parties']++;
            }
        }

        return collect($by)->map(fn ($t) => [
            'currency' => $t['currency'],
            'billed' => round($t['billed'], 2),
            'credited' => round($t['credited'], 2),
            'paid' => round($t['paid'], 2),
            'outstanding' => round($t['outstanding'], 2),
            'parties' => $t['parties'],
        ])->sortByDesc('billed')->values()->all();
    }

    private static function empty(string $type, ?Carbon $from, ?Carbon $to): array
    {
        return [
            'type' => $type,
            'from' => $from?->toDateString(),
            'to' => $to?->toDateString(),
            'parties' => [],
            'grand_totals' => [],
            'party_count' => 0,
            'row_count' => 0,
            'truncated' => false,
        ];
    }
}

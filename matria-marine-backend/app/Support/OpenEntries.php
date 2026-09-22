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
 * "Who owed us what, on this date" — every party with a balance, in one sweep.
 *
 * This is the as-of cousin of {@see Settlement}, which always answers "right
 * now". The difference matters: an invoice paid in September is still OPEN on
 * a 14 August report, which is what makes the figure reconcile against a bank
 * statement for that date. Both share Settlement::resolve() so the arithmetic
 * itself is defined once.
 *
 * Cost is a fixed handful of queries no matter how many parties come back —
 * the work is driven by open documents, not by the customer master.
 */
class OpenEntries
{
    /** Refuse to build a PDF larger than this; the caller reports why. */
    public const PDF_ENTRY_LIMIT = 2000;

    /**
     * @return array{
     *   as_of: string, type: string, include_unapplied: bool,
     *   parties: array, grand_totals: array, party_count: int, entry_count: int
     * }
     */
    public static function build(string $type, ?Carbon $asOf = null, bool $includeUnapplied = true, bool $includeDrafts = false): array
    {
        $asOf = ($asOf ?: Carbon::today())->endOfDay();
        $isCustomer = $type === 'customer';

        // 1. Every document that existed on the date. Settled ones are kept
        //    at this stage — they are not printed, but a payment applied to
        //    one of them is still a real application, not loose money.
        //
        //    No early return when this is empty: a party can hold money on
        //    account with no document raised against it yet, and that balance
        //    still has to be reported.
        $documents = self::documentsAsOf($isCustomer, $asOf, $includeDrafts);
        $docIds = $documents->pluck('id')->all();

        // 2 & 3. Payments applied and credit notes issued on or before the date.
        $allocated = $docIds ? self::allocatedAsOf($isCustomer, $docIds, $asOf) : [];
        $credited = ($isCustomer && $docIds) ? self::creditedAsOf($docIds, $asOf) : [];

        // 4. Resolve each document, keeping only the ones still carrying money.
        $open = $documents
            ->map(fn ($d) => self::entry($d, $isCustomer, $asOf, $allocated, $credited))
            ->filter(fn ($e) => $e['outstanding'] > Settlement::EPSILON)
            ->groupBy('party_id');

        // 5. Money received that is not sitting against any visible document —
        //    NAV's "unapplied entries". A payment banked before the date but
        //    applied to an invoice raised after it belongs here too, which is
        //    why visibility is judged against $docIds rather than the payment.
        $unapplied = $includeUnapplied
            ? self::unappliedAsOf($isCustomer, $asOf, $docIds)
            : collect();

        $partyIds = $open->keys()->merge($unapplied->keys())->unique()->filter()->values();

        if ($partyIds->isEmpty()) {
            return self::empty($type, $asOf, $includeUnapplied);
        }

        $names = ($isCustomer ? Customer::class : Vendor::class)::whereIn('id', $partyIds)
            ->get(['id', 'name', 'email'])
            ->keyBy('id');

        $parties = $partyIds
            ->map(function ($pid) use ($open, $unapplied, $names) {
                $entries = collect($open->get($pid, collect()))
                    ->sortBy(fn ($e) => $e['due_date'] ?: $e['date'])
                    ->values();
                $credits = collect($unapplied->get($pid, collect()))->values();

                return [
                    'id' => $pid,
                    'name' => $names[$pid]->name ?? 'Deleted party',
                    'email' => $names[$pid]->email ?? null,
                    'entries' => $entries->all(),
                    'unapplied' => $credits->all(),
                    'totals' => self::totalsFor($entries, $credits),
                ];
            })
            // A party whose unapplied credit exactly cancels their invoices has
            // nothing to chase and should not appear on a chase report.
            ->filter(fn ($p) => collect($p['totals'])->contains(fn ($t) => abs($t['balance']) > Settlement::EPSILON))
            ->sortBy(fn ($p) => mb_strtolower($p['name']))
            ->values();

        return [
            'as_of' => $asOf->toDateString(),
            'type' => $type,
            'include_unapplied' => $includeUnapplied,
            'parties' => $parties->all(),
            'grand_totals' => self::grandTotals($parties),
            'party_count' => $parties->count(),
            'entry_count' => $parties->sum(fn ($p) => count($p['entries']) + count($p['unapplied'])),
            // Context for the empty state. "Nobody owes anything" and "there was
            // nothing to look at" are different answers, and saying the first
            // when the second is true reads as a clean bill of health.
            'documents_seen' => count($docIds),
            'include_drafts' => $includeDrafts,
            // Nothing is left out once they are being included.
            'drafts_excluded' => $includeDrafts ? 0 : self::draftsExcluded($isCustomer, $asOf),
        ];
    }

    /**
     * Documents that exist on the date but are deliberately not receivables:
     * unfinished customer invoices, cancelled purchase orders. Reported so the
     * screen can say why a party is missing rather than leaving it a mystery.
     */
    private static function draftsExcluded(bool $isCustomer, Carbon $asOf): int
    {
        if ($isCustomer) {
            return CustomerInvoice::where('status', 'draft')
                ->whereNotNull('customer_id')
                ->whereDate('issue_date', '<=', $asOf)
                ->count();
        }

        return PurchaseOrder::where('status', 'cancelled')
            ->whereNotNull('vendor_id')
            ->where(fn ($q) => $q->whereDate('issued_date', '<=', $asOf)
                ->orWhere(fn ($w) => $w->whereNull('issued_date')->whereDate('created_at', '<=', $asOf)))
            ->count();
    }

    /* ------------------------------------------------------------------ */
    /*  Loaders — one query each                                          */
    /* ------------------------------------------------------------------ */

    /** Documents that existed on the date, whatever their balance. */
    private static function documentsAsOf(bool $isCustomer, Carbon $asOf, bool $includeDrafts = false): Collection
    {
        if ($isCustomer) {
            return CustomerInvoice::issued($includeDrafts)
                ->whereNotNull('customer_id')
                ->whereDate('issue_date', '<=', $asOf)
                ->with('rfq:id,reference,ship_name')
                ->get(['id', 'customer_id', 'invoice_number', 'rfq_id', 'currency',
                    'grand_total', 'status', 'paid_at', 'issue_date', 'due_date']);
        }

        return PurchaseOrder::live()
            ->whereNotNull('vendor_id')
            // Not every PO carries an issued date; fall back to when it was raised.
            ->where(fn ($q) => $q->whereDate('issued_date', '<=', $asOf)
                ->orWhere(fn ($w) => $w->whereNull('issued_date')->whereDate('created_at', '<=', $asOf)))
            ->with('rfq:id,reference,ship_name')
            ->get(['id', 'vendor_id', 'po_number', 'rfq_id', 'ship_name', 'currency',
                'subtotal', 'receipt_amount', 'has_credit_note', 'credit_note_number', 'credit_note_amount',
                'status', 'paid_at', 'issued_date', 'expected_date', 'created_at']);
    }

    /** Payments applied on or before the date, summed per document. */
    private static function allocatedAsOf(bool $isCustomer, array $docIds, Carbon $asOf): array
    {
        $column = $isCustomer ? 'customer_invoice_id' : 'purchase_order_id';

        return PaymentAllocation::query()
            ->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
            ->whereIn("payment_allocations.$column", $docIds)
            ->whereDate('payments.payment_date', '<=', $asOf)
            ->groupBy("payment_allocations.$column")
            ->selectRaw("payment_allocations.$column as doc_id, SUM(payment_allocations.amount) as total")
            ->pluck('total', 'doc_id')
            ->map(fn ($v) => round((float) $v, 2))
            ->all();
    }

    /** Credit notes issued on or before the date, summed per invoice. */
    private static function creditedAsOf(array $docIds, Carbon $asOf): array
    {
        return CreditMemo::query()
            ->whereIn('customer_invoice_id', $docIds)
            ->where('status', 'issued')
            ->whereDate('memo_date', '<=', $asOf)
            ->groupBy('customer_invoice_id')
            ->selectRaw('customer_invoice_id as doc_id, SUM(grand_total) as total')
            ->pluck('total', 'doc_id')
            ->map(fn ($v) => round((float) $v, 2))
            ->all();
    }

    /**
     * Money banked on or before the date that is not applied to any document
     * visible on that date, grouped by party.
     *
     * Two payments in one query: the payments themselves, and how much of each
     * lands on a visible document. Anything left over is money on account.
     */
    private static function unappliedAsOf(bool $isCustomer, Carbon $asOf, array $docIds): Collection
    {
        $partyColumn = $isCustomer ? 'customer_id' : 'vendor_id';
        $docColumn = $isCustomer ? 'customer_invoice_id' : 'purchase_order_id';

        $payments = Payment::query()
            ->where('party_type', $isCustomer ? 'customer' : 'vendor')
            ->whereNotNull($partyColumn)
            ->whereDate('payment_date', '<=', $asOf)
            ->get(['id', $partyColumn, 'payment_number', 'payment_date', 'currency', 'amount', 'reference', 'method']);

        if ($payments->isEmpty()) {
            return collect();
        }

        $visible = PaymentAllocation::query()
            ->whereIn('payment_id', $payments->pluck('id'))
            ->whereIn($docColumn, $docIds)
            ->groupBy('payment_id')
            ->selectRaw('payment_id, SUM(amount) as total')
            ->pluck('total', 'payment_id')
            ->map(fn ($v) => round((float) $v, 2))
            ->all();

        return $payments
            ->map(function ($p) use ($partyColumn, $visible) {
                $left = round((float) $p->amount - ($visible[$p->id] ?? 0.0), 2);

                return [
                    'party_id' => (int) $p->{$partyColumn},
                    'kind' => 'payment',
                    'number' => $p->payment_number,
                    'reference' => $p->reference,
                    'method' => $p->method,
                    'date' => optional($p->payment_date)->toDateString(),
                    'currency' => $p->currency,
                    'amount' => $left,
                ];
            })
            ->filter(fn ($r) => $r['amount'] > Settlement::EPSILON)
            ->groupBy('party_id');
    }

    /* ------------------------------------------------------------------ */
    /*  Shaping                                                            */
    /* ------------------------------------------------------------------ */

    /** One document, resolved to what was still owed on the date. */
    private static function entry($d, bool $isCustomer, Carbon $asOf, array $allocated, array $credited): array
    {
        $billed = $isCustomer
            ? round((float) $d->grand_total, 2)
            : round($d->vendorNetAmount(), 2);

        $s = Settlement::resolve(
            $billed,
            $credited[$d->id] ?? 0.0,
            $allocated[$d->id] ?? 0.0,
            self::wasManuallyPaidBy($d, $asOf),
        );

        $date = $isCustomer ? $d->issue_date : ($d->issued_date ?: $d->created_at);
        $due = $isCustomer ? $d->due_date : $d->expected_date;
        $dueDate = $due ? Carbon::parse($due) : null;

        return [
            'party_id' => (int) ($isCustomer ? $d->customer_id : $d->vendor_id),
            'id' => $d->id,
            'kind' => $isCustomer ? 'invoice' : 'po',
            'number' => $isCustomer ? $d->invoice_number : $d->po_number,
            'reference' => $d->rfq?->reference,
            'vessel' => $d->rfq?->ship_name ?: ($d->ship_name ?? null),
            'date' => optional($date)->toDateString(),
            'due_date' => $dueDate?->toDateString(),
            'currency' => $d->currency,
            'amount' => $s['billed'],
            'settled' => $s['settled'],
            'outstanding' => $s['outstanding'],
            // Ageing is measured from the report date, not from today.
            'overdue_days' => $dueDate && $dueDate->lt($asOf) ? $dueDate->diffInDays($asOf) : 0,
        ];
    }

    /**
     * Whether the old manual "paid" flag had been set BY the report date.
     *
     * A document ticked paid last week was not paid a year ago, so the flag
     * only counts when its date falls on or before the report date. With no
     * date recorded at all there is nothing better to go on than the status.
     */
    private static function wasManuallyPaidBy($d, Carbon $asOf): bool
    {
        if ($d->paid_at) {
            return Carbon::parse($d->paid_at)->lte($asOf);
        }

        return ($d->status ?? null) === 'paid';
    }

    /** Per-currency balance for one party: what is owed, less money on account. */
    private static function totalsFor(Collection $entries, Collection $unapplied): array
    {
        $by = [];

        foreach ($entries as $e) {
            $cur = $e['currency'];
            $by[$cur] ??= ['currency' => $cur, 'outstanding' => 0.0, 'unapplied' => 0.0];
            $by[$cur]['outstanding'] += $e['outstanding'];
        }
        foreach ($unapplied as $u) {
            $cur = $u['currency'];
            $by[$cur] ??= ['currency' => $cur, 'outstanding' => 0.0, 'unapplied' => 0.0];
            $by[$cur]['unapplied'] += $u['amount'];
        }

        return collect($by)->map(fn ($t) => [
            'currency' => $t['currency'],
            'outstanding' => round($t['outstanding'], 2),
            'unapplied' => round($t['unapplied'], 2),
            'balance' => round($t['outstanding'] - $t['unapplied'], 2),
        ])->sortByDesc('balance')->values()->all();
    }

    /** The same shape again across every party, for the foot of the report. */
    private static function grandTotals(Collection $parties): array
    {
        $by = [];

        foreach ($parties as $p) {
            foreach ($p['totals'] as $t) {
                $cur = $t['currency'];
                $by[$cur] ??= ['currency' => $cur, 'outstanding' => 0.0, 'unapplied' => 0.0, 'parties' => 0];
                $by[$cur]['outstanding'] += $t['outstanding'];
                $by[$cur]['unapplied'] += $t['unapplied'];
                $by[$cur]['parties']++;
            }
        }

        return collect($by)->map(fn ($t) => [
            'currency' => $t['currency'],
            'outstanding' => round($t['outstanding'], 2),
            'unapplied' => round($t['unapplied'], 2),
            'balance' => round($t['outstanding'] - $t['unapplied'], 2),
            'parties' => $t['parties'],
        ])->sortByDesc('balance')->values()->all();
    }

    private static function empty(string $type, Carbon $asOf, bool $includeUnapplied): array
    {
        return [
            'as_of' => $asOf->toDateString(),
            'type' => $type,
            'include_unapplied' => $includeUnapplied,
            'parties' => [],
            'grand_totals' => [],
            'party_count' => 0,
            'entry_count' => 0,
            'documents_seen' => 0,
            'include_drafts' => false,
            'drafts_excluded' => self::draftsExcluded($type === 'customer', $asOf),
        ];
    }
}

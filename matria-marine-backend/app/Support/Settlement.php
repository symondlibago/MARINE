<?php

namespace App\Support;

use App\Models\CreditMemo;
use App\Models\CustomerInvoice;
use App\Models\PaymentAllocation;
use App\Models\PurchaseOrder;

/**
 * One place that answers "how much is still owed on this document?".
 *
 * Before payments existed the system only knew paid / unpaid, carried on the
 * document's own paid_at flag. Now a document can be part-settled, so the
 * balance is derived:
 *
 *     outstanding = billed − credit notes − payments applied
 *
 * The old flag is still honoured: a document with no allocations against it
 * falls back to whatever the "mark as paid" toggle says, so every invoice
 * settled before this feature existed keeps reading correctly.
 */
class Settlement
{
    /** Money differences below this are rounding noise, not a balance. */
    public const EPSILON = 0.005;

    /** What the party was billed, before credits and payments. */
    public static function billed($doc): float
    {
        if ($doc instanceof CustomerInvoice) {
            return round((float) $doc->grand_total, 2);
        }

        // What we owe a vendor is the receipted figure once known, otherwise
        // what was ordered. Third-party expenses are reported separately and
        // are deliberately not part of the vendor balance.
        return round($doc->vendorNetAmount(), 2);
    }

    /** Issued customer credits; vendor credits are already netted into billed(). */
    public static function credited($doc): float
    {
        if (! $doc instanceof CustomerInvoice) {
            return 0.0;
        }

        return round((float) CreditMemo::where('customer_invoice_id', $doc->id)
            ->where('status', 'issued')
            ->sum('grand_total'), 2);
    }

    /** Payments applied to this document. */
    public static function allocated($doc): float
    {
        $column = $doc instanceof CustomerInvoice ? 'customer_invoice_id' : 'purchase_order_id';

        return round((float) PaymentAllocation::where($column, $doc->id)->sum('amount'), 2);
    }

    /**
     * THE definition of a document's balance. Everything that reports money
     * owed goes through here, so there is exactly one place this arithmetic
     * can be got wrong.
     *
     * Callers differ only in how they LOAD the three inputs:
     *
     *   · self::of()                       — one document, as it stands now
     *   · ReportsController::settlementOf() — many documents, from a preloaded
     *                                         index, still "now"
     *   · OpenEntries                       — many documents, bounded to a date
     *
     * @return array{billed: float, credited: float, allocated: float, settled: float, outstanding: float, paid: bool, partial: bool}
     */
    public static function resolve(float $billed, float $credited, float $allocated, bool $manuallyPaid): array
    {
        // Legacy fallback: nothing allocated and nothing credited, so the only
        // information available is the manual "mark as paid" flag.
        if ($allocated <= self::EPSILON && $credited <= self::EPSILON && $manuallyPaid) {
            $allocated = $billed;
        }

        $outstanding = round(max($billed - $credited - $allocated, 0), 2);
        $paid = $outstanding <= self::EPSILON;

        return [
            'billed' => round($billed, 2),
            'credited' => round($credited, 2),
            'allocated' => round($allocated, 2),
            'settled' => round(min($credited + $allocated, $billed), 2),
            'outstanding' => $outstanding,
            'paid' => $paid,
            // Something has been paid, but not all of it — the case the old
            // paid/unpaid flag could never express.
            'partial' => ! $paid && $allocated > self::EPSILON,
        ];
    }

    /**
     * The full picture for one document, as it stands right now.
     *
     * @return array{billed: float, credited: float, allocated: float, settled: float, outstanding: float, paid: bool, partial: bool}
     */
    public static function of($doc): array
    {
        return self::resolve(
            self::billed($doc),
            self::credited($doc),
            self::allocated($doc),
            self::manuallyPaid($doc),
        );
    }

    /**
     * The most that may still be applied to this document.
     *
     * Deliberately delegates to of() rather than recomputing: that keeps the
     * legacy fallback in play, so an invoice someone had already ticked as
     * paid by hand cannot then be paid a second time through a payment entry.
     */
    public static function room($doc): float
    {
        return self::of($doc)['outstanding'];
    }

    /** Whether the document carries the old manual "paid" marker. */
    public static function manuallyPaid($doc): bool
    {
        if ($doc instanceof CustomerInvoice) {
            return $doc->status === 'paid' || $doc->paid_at !== null;
        }

        return $doc->paid_at !== null;
    }

    /**
     * Push the derived balance back onto the document's own paid flag, so the
     * dashboards, reports and lists that read paid_at stay truthful.
     *
     * With no allocations there is nothing to derive from, so the manual flag
     * is left exactly as the user set it — unless $force is passed, which the
     * delete path uses to reverse a settlement it had previously written.
     */
    public static function sync($doc, bool $force = false): void
    {
        $allocated = self::allocated($doc);

        if ($allocated <= self::EPSILON && ! $force) {
            return;
        }

        $net = round(self::billed($doc) - self::credited($doc), 2);
        $paid = $allocated + self::EPSILON >= $net;

        if ($doc instanceof CustomerInvoice) {
            // A draft invoice is not a receivable; never flip it to paid.
            if ($doc->status === 'draft') {
                return;
            }
            $doc->forceFill([
                'status' => $paid ? 'paid' : 'sent',
                'paid_at' => $paid ? ($doc->paid_at ?: self::lastPaymentDate($doc)) : null,
            ])->save();

            return;
        }

        if ($doc instanceof PurchaseOrder) {
            $doc->forceFill([
                'paid_at' => $paid ? ($doc->paid_at ?: self::lastPaymentDate($doc)) : null,
            ])->save();
        }
    }

    /** The date of the newest payment applied — the honest "paid on" date. */
    private static function lastPaymentDate($doc)
    {
        $column = $doc instanceof CustomerInvoice ? 'customer_invoice_id' : 'purchase_order_id';

        $date = PaymentAllocation::where($column, $doc->id)
            ->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
            ->max('payments.payment_date');

        return $date ? \Illuminate\Support\Carbon::parse($date) : now();
    }
}

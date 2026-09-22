<?php

namespace App\Support;

use App\Models\DocumentCounter;
use Illuminate\Support\Facades\DB;

/**
 * Central document numbering (Matria "MMS-" scheme).
 *
 *   QTN     MMS-QTN-2026-00001       (enquiry / RFQ to vendors + Offer to client)
 *   PO      MMS-PO-2026-000001       (purchase order)
 *   DO      MMS-DO-2026-000001       (delivery order)
 *   ProINV  MMS-ProINV-2026-000001   (pro-forma invoice)
 *   INV     MMS-INV-2026-000001      (final invoice)
 *
 * Each type has its own running counter that NEVER resets across years — only
 * the year stamped into the string changes. The RFQ-to-vendor number adds a
 * per-vendor suffix (e.g. "-01") on top of the enquiry's QTN; see suffixVendor().
 */
class DocNumber
{
    /** type => [prefix, zero-pad width] */
    private const FORMATS = [
        'QTN' => ['MMS-QTN', 5],
        'PO' => ['MMS-PO', 6],
        'DO' => ['MMS-DO', 6],
        'ProINV' => ['MMS-ProINV', 6],
        'INV' => ['MMS-INV', 6],
        'CM' => ['MMS-CM', 6],
        'RCPT' => ['MMS-RCPT', 6],   // money received from a customer
        'PMT' => ['MMS-PMT', 6],     // money paid out to a vendor
        'CTM' => ['MMS-CTM', 6],     // reconciled Cash to Master record
    ];

    /** type => human label, in the order an admin would expect to see them. */
    private const LABELS = [
        'QTN' => 'Enquiry / Quotation',
        'PO' => 'Purchase order',
        'DO' => 'Delivery order',
        'ProINV' => 'Pro-forma invoice',
        'INV' => 'Customer invoice',
        'CM' => 'Credit note',
        'RCPT' => 'Payment received',
        'PMT' => 'Payment made',
        'CTM' => 'Cash to Master',
    ];

    /**
     * Where each type's issued numbers actually live: [model, column].
     *
     * Used only by {@see DocumentSeries} to work out the highest number ever
     * issued, so the counter can never be wound back onto a number that is
     * already on a document. Kept here beside FORMATS because the two must
     * stay in step — adding a type means adding it in both places.
     */
    private const SOURCES = [
        'QTN' => [[\App\Models\Rfq::class, 'reference']],
        'PO' => [[\App\Models\PurchaseOrder::class, 'po_number']],
        'DO' => [[\App\Models\DeliveryOrder::class, 'do_number']],
        // Two homes: a delivery order's proforma, and a quotation's own — raised
        // for customers who pay up front and never see a delivery order.
        'ProINV' => [
            [\App\Models\DeliveryOrder::class, 'proforma_number'],
            [\App\Models\Offer::class, 'proforma_number'],
        ],
        // Two homes: the customer invoice itself, and the copy stamped onto a
        // purchase order when its final invoice is raised.
        'INV' => [
            [\App\Models\CustomerInvoice::class, 'invoice_number'],
            [\App\Models\PurchaseOrder::class, 'invoice_number'],
        ],
        'CM' => [[\App\Models\CreditMemo::class, 'cm_number']],
        // Receipts and payments share a column and are told apart by prefix.
        'RCPT' => [[\App\Models\Payment::class, 'payment_number']],
        'PMT' => [[\App\Models\Payment::class, 'payment_number']],
        'CTM' => [[\App\Models\CashToMasterRecord::class, 'reference']],
    ];

    /** @return list<string> every numbering key, in display order */
    public static function types(): array
    {
        return array_keys(self::FORMATS);
    }

    public static function knows(string $type): bool
    {
        return isset(self::FORMATS[$type]);
    }

    public static function label(string $type): string
    {
        return self::LABELS[$type] ?? $type;
    }

    /** @return array{0: string, 1: int} [prefix, zero-pad width] */
    public static function format(string $type): array
    {
        return self::FORMATS[$type];
    }

    /** @return list<array{0: class-string, 1: string}> */
    public static function sources(string $type): array
    {
        return self::SOURCES[$type] ?? [];
    }

    /** Render a sequence the way it would be stamped onto a document. */
    public static function preview(string $type, int $seq, ?int $year = null): string
    {
        [$prefix, $width] = self::FORMATS[$type];

        return sprintf('%s-%d-%s', $prefix, $year ?: now()->year, str_pad((string) $seq, $width, '0', STR_PAD_LEFT));
    }

    /** Atomically take the next number for a document type. */
    public static function next(string $type): string
    {
        [$prefix, $width] = self::FORMATS[$type];

        $seq = DB::transaction(function () use ($type) {
            // Make sure the row exists, then lock it for the increment so two
            // documents created at the same moment can't grab the same number.
            DocumentCounter::firstOrCreate(['key' => $type], ['seq' => 0]);
            $row = DocumentCounter::where('key', $type)->lockForUpdate()->first();
            $row->seq += 1;
            $row->save();

            return $row->seq;
        });

        return sprintf('%s-%d-%s', $prefix, now()->year, str_pad((string) $seq, $width, '0', STR_PAD_LEFT));
    }

    /** The per-vendor RFQ number: the enquiry's QTN plus a 2-digit vendor suffix. */
    public static function vendorSuffix(string $enquiryNumber, int $seq): string
    {
        return $enquiryNumber.'-'.str_pad((string) $seq, 2, '0', STR_PAD_LEFT);
    }
}

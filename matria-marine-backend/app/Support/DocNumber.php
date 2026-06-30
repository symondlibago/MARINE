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
    ];

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

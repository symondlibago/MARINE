<?php

namespace App\Support;

/**
 * The GST treatments Matria uses, and the IRAS F5 boxes each one feeds.
 *
 * A constant class rather than a table, deliberately: these are fixed by IRAS,
 * not by us. A table invites somebody to add a fifth code or rename one, and
 * every figure on the return would quietly start disagreeing with the boxes it
 * is meant to fill.
 *
 * A document never stores a GST code. It stores an ACCOUNT code, and the
 * treatment is read from accounts.gst_code when a report runs — one field, so
 * the account and its GST treatment cannot drift apart. That is the whole point
 * of the design: the client's spreadsheet kept them as two independent columns,
 * the GST column ended up holding account codes, and Box 2 read zero against
 * 897,471 of zero-rated sales for a year.
 */
final class GstCodes
{
    public const SR = 'SR';    // standard-rated — output tax charged at the prevailing rate
    public const ZI = 'ZI';    // zero-rated — exports and international services
    public const ESN = 'ESN';  // exempt — non-regulation 33
    public const OS = 'OS';    // out of scope — not a supply at all

    /** code => short label for a dropdown or a column header */
    public const LABELS = [
        self::SR => 'Standard-rated',
        self::ZI => 'Zero-rated',
        self::ESN => 'Exempt',
        self::OS => 'Out of scope',
    ];

    /** code => the fuller wording, for a tooltip or the GST summary legend */
    public const DESCRIPTIONS = [
        self::SR => 'Standard-rated supply or purchase — GST charged at the prevailing rate.',
        self::ZI => 'Zero-rated — exports and international services. GST at 0%, but the value is still declared.',
        self::ESN => 'Exempt supply (non-regulation 33) — no GST, and no input tax claim.',
        self::OS => 'Out of scope — not a supply. Never appears on the return.',
    ];

    /**
     * Which F5 box a SALE lands in.
     *
     * Box 1 standard-rated, Box 2 zero-rated, Box 3 exempt. Box 4 is their sum
     * and is computed, never assigned. Out-of-scope sales are on no box at all.
     */
    private const SALES_BOX = [
        self::SR => 1,
        self::ZI => 2,
        self::ESN => 3,
        self::OS => null,
    ];

    /**
     * Which F5 box a PURCHASE lands in.
     *
     * Box 5 is "total value of taxable purchases", which IRAS defines as
     * standard-rated plus zero-rated. Exempt and out-of-scope purchases are
     * excluded from it entirely.
     */
    private const PURCHASE_BOX = [
        self::SR => 5,
        self::ZI => 5,
        self::ESN => null,
        self::OS => null,
    ];

    /** @return list<string> every code, in the order they appear on the return */
    public static function all(): array
    {
        return array_keys(self::LABELS);
    }

    public static function knows(?string $code): bool
    {
        return $code !== null && isset(self::LABELS[$code]);
    }

    public static function label(?string $code): string
    {
        return self::LABELS[$code] ?? ($code ?: '—');
    }

    /** The F5 box a sale on this code belongs to, or null if it is on no box. */
    public static function salesBox(?string $code): ?int
    {
        return self::SALES_BOX[$code] ?? null;
    }

    /** The F5 box a purchase on this code belongs to, or null. */
    public static function purchaseBox(?string $code): ?int
    {
        return self::PURCHASE_BOX[$code] ?? null;
    }

    /** Does a sale on this code carry output tax (Box 6)? Standard-rated only. */
    public static function chargesOutputTax(?string $code): bool
    {
        return $code === self::SR;
    }

    /** Is input tax on a purchase on this code claimable (Box 7)? */
    public static function claimsInputTax(?string $code): bool
    {
        return $code === self::SR;
    }

    /** Shape the GST codes for a dropdown or a report legend. */
    public static function options(): array
    {
        return array_map(fn (string $code) => [
            'code' => $code,
            'label' => self::LABELS[$code],
            'description' => self::DESCRIPTIONS[$code],
            'sales_box' => self::SALES_BOX[$code],
            'purchase_box' => self::PURCHASE_BOX[$code],
        ], self::all());
    }
}

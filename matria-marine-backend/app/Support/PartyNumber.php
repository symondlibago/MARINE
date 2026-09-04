<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\DocumentCounter;
use App\Models\Vendor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Customer and vendor numbers — 10001 up, 20001 up.
 *
 * Separate from {@see DocNumber} on purpose. That one issues document strings
 * ("MMS-INV-2026-000123"); these are plain running numbers on a party, and
 * mixing them would put two different shapes behind one formatter. They do
 * share the `document_counters` table, so both get the same row-level lock and
 * two people creating a customer at the same moment cannot collide.
 *
 * The number is a stable key for the accounting reports and for matching
 * against the group's records. It is NOT embedded in the party's name — the
 * client's spreadsheet did that ("20250001 Bharani - Orient Express Lines")
 * because it had nowhere else to put it. Here the name stays clean.
 */
final class PartyNumber
{
    /**
     * kind => [counter key, first number, model, column]
     *
     * The two ranges are a million apart in practice — a number tells you at a
     * glance whether you are looking at a customer or a vendor.
     */
    private const SERIES = [
        'customer' => ['CUSTNO', 10001, Customer::class, 'customer_no'],
        'vendor' => ['VENDNO', 20001, Vendor::class, 'vendor_no'],
    ];

    /** @return list<string> */
    public static function kinds(): array
    {
        return array_keys(self::SERIES);
    }

    public static function knows(string $kind): bool
    {
        return isset(self::SERIES[$kind]);
    }

    /** The first number a series issues, before anything has been allocated. */
    public static function start(string $kind): int
    {
        return self::SERIES[$kind][1];
    }

    /**
     * Is the column actually deployed?
     *
     * Railway runs migrations by hand, so there is always a window where this
     * code is live and the column is not. Numbering stays switched off in that
     * window rather than throwing — creating a customer must not start failing
     * because a migration has not been run yet. Cached, so it costs one query
     * per request at most.
     */
    public static function ready(string $kind): bool
    {
        [, , $model, $column] = self::SERIES[$kind];
        $table = (new $model)->getTable();

        static $cache = [];

        return $cache[$table.'.'.$column] ??= Schema::hasColumn($table, $column);
    }

    /**
     * Atomically take the next number for a customer or a vendor.
     *
     * The counter is re-based against the highest number actually on a row
     * before it increments. That costs one MAX() per allocation — nothing, at
     * the rate parties get created — and it means a row inserted straight into
     * the database (a back-fill, a restore, an import) can never hand the same
     * number out twice afterwards.
     */
    public static function next(string $kind): int
    {
        [$key, $start, $model, $column] = self::SERIES[$kind];

        return DB::transaction(function () use ($key, $start, $model, $column) {
            DocumentCounter::firstOrCreate(['key' => $key], ['seq' => $start - 1]);
            $row = DocumentCounter::where('key', $key)->lockForUpdate()->first();

            $highestIssued = (int) $model::max($column);
            $row->seq = max((int) $row->seq, $highestIssued, $start - 1) + 1;
            $row->save();

            return (int) $row->seq;
        });
    }

    /**
     * Give a party a number if it has not got one, and return it.
     *
     * Idempotent: a party that already has a number keeps it. Returns null when
     * the column is not deployed yet — see {@see ready()}. Called from the
     * models' `created` event, so every path that makes a customer or a vendor
     * gets a number without any controller having to remember to ask.
     */
    public static function assign(Customer|Vendor $party): ?int
    {
        $kind = $party instanceof Customer ? 'customer' : 'vendor';
        $column = self::SERIES[$kind][3];

        if (! self::ready($kind)) {
            return null;
        }

        if ($party->{$column}) {
            return (int) $party->{$column};
        }

        $number = self::next($kind);
        $party->forceFill([$column => $number])->save();

        return $number;
    }
}

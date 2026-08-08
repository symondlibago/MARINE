<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Move the customer invoice counter on again, so the next issued invoice is
 * MMS-INV-{year}-000300.
 *
 * A follow-up to the earlier jump to 000100: more of the office's older manual
 * invoices turned up in that range, so the gap needed to be bigger. This is a
 * separate migration rather than an edit to the previous one — that one has
 * already run in production, and Laravel would never replay it.
 *
 * Same forward-only guard: if more than 299 invoices have somehow been issued,
 * the counter is left alone. Handing an already-used number to a new invoice
 * would be far worse than a smaller gap.
 */
return new class extends Migration
{
    private const START_BEFORE = 299; // next number issued becomes 300

    public function up(): void
    {
        $row = DB::table('document_counters')->where('key', 'INV')->first();

        if (! $row) {
            DB::table('document_counters')->insert([
                'key' => 'INV',
                'seq' => self::START_BEFORE,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return;
        }

        if ((int) $row->seq >= self::START_BEFORE) {
            return; // already past 300 — never renumber backwards
        }

        DB::table('document_counters')
            ->where('key', 'INV')
            ->update(['seq' => self::START_BEFORE, 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Deliberately irreversible: winding the counter back could hand an
        // already-used invoice number to a new invoice.
    }
};

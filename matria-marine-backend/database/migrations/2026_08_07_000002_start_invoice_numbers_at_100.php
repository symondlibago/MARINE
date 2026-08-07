<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Start customer invoice numbers at MMS-INV-{year}-000100.
 *
 * The office still has older manual invoices in circulation, and the system's
 * low numbers were about to collide with them. Jumping the counter to 99 makes
 * the next issued invoice 000100 and leaves a clean gap below it.
 *
 * Guarded so it can only ever move the counter FORWARD. If production has
 * already issued more than 99 invoices the counter is left exactly as it is —
 * re-using a number would give two customers the same invoice reference.
 *
 * Only the final invoice (INV) is touched. Pro-forma, credit memo, PO, DO and
 * quotation counters keep their own sequences.
 */
return new class extends Migration
{
    private const START_BEFORE = 99; // next number issued becomes 100

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
            return; // already past 100 — never renumber backwards
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

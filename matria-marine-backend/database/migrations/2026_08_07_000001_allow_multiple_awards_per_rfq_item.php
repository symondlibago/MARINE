<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Allow one enquiry line to be split across several vendors.
 *
 * A vendor often cannot supply the whole quantity — "the offer is 1 box only" —
 * so the buyer needs to take part of the line from one vendor and the rest from
 * another. Until now `awards.rfq_item_id` carried a UNIQUE index, which made
 * one-vendor-per-line a hard database rule.
 *
 * The unique key becomes (rfq_item_id, vendor_id): still no duplicate award of
 * the same line to the same vendor, but any number of vendors per line.
 *
 * Order matters. `awards_rfq_item_id_foreign` is backed by the unique index we
 * are removing, so the replacement index — whose leftmost column is also
 * rfq_item_id — must exist BEFORE the old one is dropped, or MySQL refuses with
 * "Cannot drop index needed in a foreign key constraint".
 *
 * Existing data is untouched: every current line has at most one award, so the
 * new composite key holds without changing a single row.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->hasIndex('awards_rfq_item_vendor_unique')) {
            DB::statement('ALTER TABLE `awards` ADD UNIQUE `awards_rfq_item_vendor_unique` (`rfq_item_id`, `vendor_id`)');
        }

        if ($this->hasIndex('awards_rfq_item_id_unique')) {
            DB::statement('ALTER TABLE `awards` DROP INDEX `awards_rfq_item_id_unique`');
        }
    }

    public function down(): void
    {
        // Only reversible while no line is actually split — collapsing two
        // vendors back into one row would silently lose an award.
        $split = DB::table('awards')
            ->select('rfq_item_id')
            ->groupBy('rfq_item_id')
            ->havingRaw('COUNT(*) > 1')
            ->count();

        if ($split > 0) {
            throw new RuntimeException(
                "Cannot roll back: {$split} enquiry line(s) are split across multiple vendors. "
                .'Un-award the extra vendors first.'
            );
        }

        if (! $this->hasIndex('awards_rfq_item_id_unique')) {
            DB::statement('ALTER TABLE `awards` ADD UNIQUE `awards_rfq_item_id_unique` (`rfq_item_id`)');
        }

        if ($this->hasIndex('awards_rfq_item_vendor_unique')) {
            DB::statement('ALTER TABLE `awards` DROP INDEX `awards_rfq_item_vendor_unique`');
        }
    }

    private function hasIndex(string $name): bool
    {
        return count(DB::select(
            'SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
            ['awards', $name]
        )) > 0;
    }
};

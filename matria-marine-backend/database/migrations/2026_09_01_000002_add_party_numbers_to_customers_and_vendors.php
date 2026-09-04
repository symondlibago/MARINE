<?php

use App\Models\DocumentCounter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Customer numbers from 10001, vendor numbers from 20001.
 *
 * Additive. A new nullable column on each table plus a back-fill that only ever
 * writes into that new column — no existing field is touched, so nothing that
 * reads customers or vendors today can behave differently afterwards.
 *
 * Numbers are handed out in creation order, oldest party first, so the sequence
 * matches the order the business actually acquired them.
 *
 * Adding the column and filling it are kept as two independent, separately
 * idempotent steps. Vendors is a 7,500-row table; if the migration is
 * interrupted part way through the fill, re-running it finishes the job instead
 * of seeing the column already there and declaring itself done.
 */
return new class extends Migration
{
    /** table => [column, first number, counter key] */
    private const SERIES = [
        'customers' => ['customer_no', 10001, 'CUSTNO'],
        'vendors' => ['vendor_no', 20001, 'VENDNO'],
    ];

    public function up(): void
    {
        foreach (self::SERIES as $table => [$column, $start, $key]) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            if (! Schema::hasColumn($table, $column)) {
                Schema::table($table, function (Blueprint $blueprint) use ($column) {
                    $blueprint->unsignedInteger($column)->nullable()->unique()->after('id');
                });
            }

            // Always runs. It targets NULLs only, so it is safe on a fresh
            // column, on a half-filled one, and on an already-complete one.
            $this->backfill($table, $column, $start);
            $this->parkCounter($table, $column, $start, $key);
        }
    }

    /**
     * Number every unnumbered row, oldest first.
     *
     * On MySQL this is a single ordered UPDATE walking a counter variable —
     * 7,500 rows in one statement rather than 7,500 round trips, which matters
     * when the migration is run against a hosted database over the network.
     * Other drivers get the portable loop.
     */
    private function backfill(string $table, string $column, int $start): void
    {
        $resume = max((int) DB::table($table)->max($column), $start - 1);

        if (DB::table($table)->whereNull($column)->doesntExist()) {
            return;
        }

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('SET @party_no := ?', [$resume]);
            DB::update(
                "UPDATE `{$table}` SET `{$column}` = (@party_no := @party_no + 1) WHERE `{$column}` IS NULL ORDER BY `id`"
            );

            return;
        }

        $next = $resume + 1;
        DB::table($table)->select('id')->whereNull($column)->orderBy('id')
            ->chunkById(500, function ($rows) use ($table, $column, &$next) {
                foreach ($rows as $row) {
                    DB::table($table)->where('id', $row->id)->update([$column => $next++]);
                }
            });
    }

    /**
     * Park the counter on the highest number issued, so the next party created
     * continues the sequence. `seq` holds the LAST number given out.
     */
    private function parkCounter(string $table, string $column, int $start, string $key): void
    {
        DocumentCounter::updateOrCreate(
            ['key' => $key],
            ['seq' => max((int) DB::table($table)->max($column), $start - 1)]
        );
    }

    public function down(): void
    {
        foreach (self::SERIES as $table => [$column, , $key]) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, $column)) {
                Schema::table($table, function (Blueprint $blueprint) use ($column) {
                    $blueprint->dropUnique([$column]);
                    $blueprint->dropColumn($column);
                });
            }

            DocumentCounter::where('key', $key)->delete();
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The rate each sale was converted at, so the books can be totalled.
 *
 * Purchase orders have carried an exchange rate from the start; invoices and
 * credit notes never did, and AccountingBooks filled the gap with 1.0. So an
 * invoice for AED 536,185 was added to the register as though it were 536,185
 * Singapore dollars, and the sales total was nonsense the moment a customer
 * was billed in anything but the reporting currency.
 *
 * NULLABLE on purpose, and left null for every document not already in the
 * reporting currency. A rate nobody has set is not the same thing as a rate of
 * one, and writing a guessed number here would recreate the exact bug this
 * fixes — quietly, and with a figure that looks deliberate. The accounting
 * screens list the documents still waiting for a rate instead, and refuse to
 * present a converted total until they have one.
 *
 * Documents already in the reporting currency get 1.0, which is simply true.
 */
return new class extends Migration
{
    /** [table, currency column] — both sides of a sale. */
    private const TABLES = [
        'customer_invoices',
        'credit_memos',
    ];

    public function up(): void
    {
        $base = strtoupper((string) config('procurement.base_currency', 'SGD'));

        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) use ($table) {
                if (! Schema::hasColumn($table, 'exchange_rate')) {
                    // Eight places: a rate like 0.36852100 needs room, and the
                    // purchase order column is already decimal(16, 8).
                    $t->decimal('exchange_rate', 16, 8)->nullable()->after('currency');
                }
                if (! Schema::hasColumn($table, 'base_currency')) {
                    $t->string('base_currency', 3)->nullable()->after('exchange_rate');
                }
            });

            // Already in the reporting currency, so the rate is one. Anything
            // else is left null for someone to set.
            DB::table($table)
                ->whereNull('exchange_rate')
                ->whereRaw('UPPER(currency) = ?', [$base])
                ->update(['exchange_rate' => 1, 'base_currency' => $base]);
        }

        $this->retireStaleBuyingRates($base);
    }

    /**
     * The buying side had a rate column all along — and every row in it says
     * 1.00000000, including orders in EUR and SGD against a USD base.
     *
     * A rate of one was also what the column defaulted to, so there is no way
     * to tell an order somebody priced from one nobody touched. With the books
     * moving to Singapore dollars it hardly matters: one is right only for an
     * order already in the reporting currency, and every other row has to be
     * set by hand whatever it says now. So those are emptied, and show up on
     * the same "waiting for a rate" list as the invoices.
     */
    private function retireStaleBuyingRates(string $base): void
    {
        // Dropping a NOT NULL needs vendor syntax, and the test suite runs on
        // sqlite where ALTER ... MODIFY does not exist. Only MySQL carries the
        // live data this is here to correct, so the rewrite is done there and
        // skipped elsewhere — a fresh sqlite schema has nothing stale in it.
        $isMysql = DB::getDriverName() === 'mysql';

        foreach (['purchase_orders', 'operating_expenses'] as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'exchange_rate')) {
                continue;
            }

            // Was NOT NULL with a default of 1 — which is how an unset rate
            // came to look like a real one.
            if ($isMysql) {
                DB::statement("ALTER TABLE `{$table}` MODIFY `exchange_rate` DECIMAL(16, 8) NULL DEFAULT NULL");

                DB::table($table)
                    ->whereRaw('UPPER(currency) <> ?', [$base])
                    ->where('exchange_rate', 1)
                    ->update(['exchange_rate' => null]);
            }

            DB::table($table)
                ->whereRaw('UPPER(currency) = ?', [$base])
                ->update(['exchange_rate' => 1]);
        }

        // One reporting currency, not whichever was configured the day each
        // order happened to be raised.
        if (Schema::hasColumn('purchase_orders', 'base_currency')) {
            if ($isMysql) {
                DB::statement("ALTER TABLE `purchase_orders` MODIFY `base_currency` CHAR(3) NOT NULL DEFAULT '{$base}'");
            }

            DB::table('purchase_orders')->update(['base_currency' => $base]);
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) use ($table) {
                foreach (['exchange_rate', 'base_currency'] as $column) {
                    if (Schema::hasColumn($table, $column)) {
                        $t->dropColumn($column);
                    }
                }
            });
        }
    }
};

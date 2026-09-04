<?php

use App\Models\Account;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Classify money as it is recorded: an account code on every financial document,
 * and — on the purchase side, which has neither today — the GST amount too.
 *
 * Additive throughout. Every column is new, every existing column is left
 * exactly as it was, and nothing that reads these tables today can see a
 * different figure afterwards.
 *
 * Two things are worth spelling out.
 *
 * 1. There is NO `gst_code` column. The GST treatment is read from
 *    `accounts.gst_code` through the account code at report time. The client's
 *    spreadsheet kept them as two independent columns, the GST column filled up
 *    with account codes, and their Box 2 read zero against a year of zero-rated
 *    sales. One field cannot disagree with itself.
 *
 * 2. Without `tax_rate` / `tax_amount` on purchases there is no Box 5 and no
 *    Box 7 — input tax simply cannot be reported. That is the single biggest
 *    gap in the current schema and the reason this migration touches the
 *    purchase side at all.
 */
return new class extends Migration
{
    /**
     * table => [default account, does it need GST amount columns?]
     *
     * Sales default to 4100: marine supply is zero-rated export almost every
     * time, and Dennis picks 4000 on the rare standard-rated job. A credit note
     * reverses a sale, so it follows the same default. What we buy for a job is
     * cost of sales; our own overheads are operating expenses.
     */
    private const TARGETS = [
        'customer_invoices' => [Account::DEFAULT_SALES, false],
        'credit_memos' => [Account::DEFAULT_SALES, false],
        'purchase_orders' => [Account::DEFAULT_PURCHASE, true],
        'operating_expenses' => [Account::DEFAULT_EXPENSE, true],
    ];

    public function up(): void
    {
        foreach (self::TARGETS as $table => [$default, $needsTax]) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            if (! Schema::hasColumn($table, 'account_code')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    // Nullable, and deliberately WITHOUT a database default.
                    //
                    // A column default would fill every existing row the moment
                    // the column is added, which makes the derivation below
                    // (there is nothing left that is NULL) quietly do nothing —
                    // and it puts a second, invisible source of truth beside
                    // the application's default. The models set the code on
                    // create; see App\Models\Concerns\HasAccountCode.
                    //
                    // NULL stays meaningful: it means "not classified", and the
                    // reports show it as such rather than dropping the amount
                    // into a box nobody chose.
                    $blueprint->string('account_code', 20)->nullable();
                    $blueprint->index('account_code');
                });
            }

            if ($needsTax) {
                Schema::table($table, function (Blueprint $blueprint) use ($table) {
                    if (! Schema::hasColumn($table, 'tax_rate')) {
                        $blueprint->decimal('tax_rate', 6, 3)->default(0);
                    }
                    if (! Schema::hasColumn($table, 'tax_amount')) {
                        $blueprint->decimal('tax_amount', 16, 4)->default(0);
                    }
                });
            }

            $this->backfill($table, $default);
        }
    }

    /**
     * Give existing documents an account code.
     *
     * A default column value only applies to rows inserted afterwards, so
     * without this every document already in the system would be invisible to
     * the reports.
     *
     * On the sales side the code is DERIVED, not assumed: an invoice that
     * charged GST was standard-rated and belongs on 4000; one that charged none
     * was zero-rated and belongs on 4100. That is read off `tax_amount`, which
     * the document already recorded — we are classifying what the data says,
     * not guessing.
     *
     * Purchases get 5000 and operating expenses 5100 flat, with GST left at
     * zero, because no input tax was ever recorded against them. Inventing a
     * figure there would put a number on a tax return that no receipt supports.
     */
    private function backfill(string $table, string $default): void
    {
        if (DB::table($table)->whereNull('account_code')->doesntExist()) {
            return;
        }

        // Sales first, so the derived 4000 wins before the flat default fills
        // whatever is left. Only rows still NULL are touched, which is what
        // makes re-running this safe and keeps any manual correction intact.
        if (in_array($table, ['customer_invoices', 'credit_memos'], true)) {
            DB::table($table)
                ->whereNull('account_code')
                ->where('tax_amount', '>', 0)
                ->update(['account_code' => Account::DEFAULT_SALES_STANDARD]);
        }

        DB::table($table)->whereNull('account_code')->update(['account_code' => $default]);
    }

    public function down(): void
    {
        foreach (self::TARGETS as $table => [, $needsTax]) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table, $needsTax) {
                if (Schema::hasColumn($table, 'account_code')) {
                    // A string is the index NAME; an array would make Laravel
                    // derive a name from those strings as if they were columns.
                    $blueprint->dropIndex($table.'_account_code_index');
                    $blueprint->dropColumn('account_code');
                }

                // Only drop the tax columns where THIS migration added them —
                // customer_invoices and credit_memos had them all along.
                if ($needsTax) {
                    foreach (['tax_rate', 'tax_amount'] as $column) {
                        if (Schema::hasColumn($table, $column)) {
                            $blueprint->dropColumn($column);
                        }
                    }
                }
            });
        }
    }
};

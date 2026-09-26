<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An account code on every credit-memo LINE.
 *
 * A credit memo undoes part of an invoice, so it has to undo it in the same
 * place. Now that invoice lines are coded one by one, a single code on the memo
 * is not enough: crediting the handling fee off a Cash to Master invoice should
 * reverse income, while crediting the principal should reverse the liability —
 * money handed back that was never ours to begin with.
 *
 * Each line takes its code from the invoice line it credits, so nobody has to
 * think about it. Existing memo lines inherit their memo's own code.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('credit_memo_items', 'account_code')) {
            Schema::table('credit_memo_items', function (Blueprint $table) {
                $table->string('account_code', 20)->nullable()->after('unit_price');
            });
        }

        // Prefer the invoice line's own code; fall back to the memo's.
        DB::statement('
            UPDATE credit_memo_items
               SET account_code = COALESCE(
                   (SELECT account_code FROM customer_invoice_items
                     WHERE customer_invoice_items.id = credit_memo_items.customer_invoice_item_id),
                   (SELECT account_code FROM credit_memos
                     WHERE credit_memos.id = credit_memo_items.credit_memo_id)
               )
             WHERE account_code IS NULL
        ');
    }

    public function down(): void
    {
        Schema::table('credit_memo_items', function (Blueprint $table) {
            $table->dropColumn('account_code');
        });
    }
};

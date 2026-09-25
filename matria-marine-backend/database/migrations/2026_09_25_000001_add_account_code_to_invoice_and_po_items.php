<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An account code on every invoice and purchase-order LINE.
 *
 * Enquiry and offer lines already carry one; the invoice and the purchase order
 * only had a single code for the whole document. That is too coarse for a Cash
 * to Master invoice, where one document holds three quite different things:
 *
 *   Cash to Master  10,000.00  -> 2100  client funds held   (a liability)
 *   Bank charge        250.00  -> 4200  CTM service fee     (income)
 *   Onboard            250.00  -> 4100  export services     (income)
 *
 * The client owes the full 10,500.00, but only 500.00 of it is ours. Coding the
 * principal to a liability account is what lets the books tell the difference —
 * revenue counts income-account lines only, so the 10,000 stops being profit.
 *
 * Existing lines inherit the document's own code, so every invoice and order
 * already raised keeps exactly the classification it has today.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('customer_invoice_items', 'account_code')) {
            Schema::table('customer_invoice_items', function (Blueprint $table) {
                $table->string('account_code', 20)->nullable()->after('unit_price');
            });
        }

        if (! Schema::hasColumn('purchase_order_items', 'account_code')) {
            Schema::table('purchase_order_items', function (Blueprint $table) {
                $table->string('account_code', 20)->nullable()->after('unit_cost');
            });
        }

        // Inherit the document's code. A correlated subquery rather than a JOIN
        // update, so this runs the same on MySQL and on the sqlite test suite.
        DB::statement('
            UPDATE customer_invoice_items
               SET account_code = (
                   SELECT account_code FROM customer_invoices
                    WHERE customer_invoices.id = customer_invoice_items.customer_invoice_id
               )
             WHERE account_code IS NULL
        ');

        DB::statement('
            UPDATE purchase_order_items
               SET account_code = (
                   SELECT account_code FROM purchase_orders
                    WHERE purchase_orders.id = purchase_order_items.purchase_order_id
               )
             WHERE account_code IS NULL
        ');
    }

    public function down(): void
    {
        Schema::table('customer_invoice_items', function (Blueprint $table) {
            $table->dropColumn('account_code');
        });
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropColumn('account_code');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The discount we give the CUSTOMER, per offer line.
 *
 * offer_items already carries discount_pct / discount_amount, but those are the
 * VENDOR's discount: the vendor knocks money off what we pay, we keep it, and
 * the customer never sees it. These two are the opposite — they come off what
 * the customer is charged, so they reduce the line total and the offer subtotal.
 *
 * Both default to 0, so every offer already raised keeps its totals and prints
 * exactly as it does today.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offer_items', function (Blueprint $table) {
            $table->decimal('cust_discount_pct', 8, 2)->default(0)->after('unit_price');
            $table->decimal('cust_discount_amount', 14, 2)->default(0)->after('cust_discount_pct');
        });
    }

    public function down(): void
    {
        Schema::table('offer_items', function (Blueprint $table) {
            $table->dropColumn(['cust_discount_pct', 'cust_discount_amount']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A discount across the whole quotation, on top of any given line by line.
 *
 * Offer lines already carry `cust_discount_pct` for "10% off this item". This
 * is the other way people actually work: the price is agreed, then a round
 * figure comes off the bottom. Doing that by editing twenty lines is tedious
 * and rounds badly, so it is taken off the subtotal instead.
 *
 * It applies BEFORE GST, because a discount reduces what is supplied and the
 * tax follows the lower figure.
 *
 * Both default to 0, so every quotation already raised keeps its totals.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->decimal('discount_pct', 8, 2)->default(0)->after('markup_total');
            $table->decimal('discount_amount', 16, 2)->default(0)->after('discount_pct');
        });
    }

    public function down(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->dropColumn(['discount_pct', 'discount_amount']);
        });
    }
};

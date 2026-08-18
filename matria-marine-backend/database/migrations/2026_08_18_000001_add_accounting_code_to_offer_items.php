<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The office's own cost coding, carried onto the quotation.
 *
 * Copied from the enquiry line when the offer is built so staff can see it
 * while pricing. It stays INTERNAL — the same rule as on the enquiry: never
 * printed on the customer's quotation or sent to a vendor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offer_items', function (Blueprint $table) {
            $table->string('accounting_code', 100)->nullable()->after('customs_code');
        });
    }

    public function down(): void
    {
        Schema::table('offer_items', function (Blueprint $table) {
            $table->dropColumn('accounting_code');
        });
    }
};

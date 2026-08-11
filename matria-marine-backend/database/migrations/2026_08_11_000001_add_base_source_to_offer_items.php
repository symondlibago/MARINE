<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Record WHICH vendor each offer line was priced from.
 *
 * The vendor selected on Compare & Award sets the offer price, but that
 * selection can legitimately change afterwards — quote the customer from Seven
 * Seas today, buy from Parisilk next week. The offer keeps its own snapshot of
 * the price, so without this column there is no way to tell later where that
 * figure came from.
 *
 * Stored as plain text rather than a vendor id: it is a note about a decision
 * already made, and must keep reading correctly even if the selection moves or
 * the vendor record is renamed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offer_items', function (Blueprint $table) {
            $table->string('base_source', 160)->nullable()->after('base_price');
        });
    }

    public function down(): void
    {
        Schema::table('offer_items', function (Blueprint $table) {
            $table->dropColumn('base_source');
        });
    }
};

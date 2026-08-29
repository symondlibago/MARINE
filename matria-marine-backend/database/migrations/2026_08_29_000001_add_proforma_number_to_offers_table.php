<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A quotation can now issue its own pro-forma invoice, for the customers who
 * pay before anything ships and never see a delivery order. The number is kept
 * so re-downloading the same proforma gives the same document rather than
 * burning a new number every time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->string('proforma_number')->nullable()->after('offer_number');
        });
    }

    public function down(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->dropColumn('proforma_number');
        });
    }
};

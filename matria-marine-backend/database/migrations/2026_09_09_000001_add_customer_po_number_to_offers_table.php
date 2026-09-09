<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            // This normally arrives after the enquiry, so keep it separate
            // from the customer's original enquiry reference.
            $table->string('customer_po_number')->nullable()->after('origin_type');
        });
    }

    public function down(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->dropColumn('customer_po_number');
        });
    }
};

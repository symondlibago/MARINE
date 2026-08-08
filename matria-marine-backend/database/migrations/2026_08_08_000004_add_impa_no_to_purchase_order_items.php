<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Carry the IMPA code onto the purchase order.
 *
 * A PO snapshots its lines at generation time rather than reading the enquiry
 * live, so the code needs its own column here or it would vanish between the
 * enquiry and the order the vendor actually receives.
 *
 * The accounting code is NOT copied: it is internal cost coding and the PO is
 * a vendor-facing document.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->string('impa_no', 60)->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropColumn('impa_no');
        });
    }
};

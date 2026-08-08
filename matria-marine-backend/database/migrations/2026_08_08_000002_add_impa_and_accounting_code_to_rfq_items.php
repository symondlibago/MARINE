<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two extra references per enquiry line.
 *
 * impa_no          the IMPA catalogue code the vendor matches the part against
 *                  (also covers ISSA and maker part numbers — it is free text,
 *                  not a lookup). Vendor-facing.
 * accounting_code  the office's own cost coding. INTERNAL — deliberately never
 *                  printed on anything a vendor or customer receives.
 *
 * Both nullable so every existing line stays valid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rfq_items', function (Blueprint $table) {
            $table->string('impa_no', 60)->nullable()->after('description');
            $table->string('accounting_code', 100)->nullable()->after('impa_no');
        });
    }

    public function down(): void
    {
        Schema::table('rfq_items', function (Blueprint $table) {
            $table->dropColumn(['impa_no', 'accounting_code']);
        });
    }
};

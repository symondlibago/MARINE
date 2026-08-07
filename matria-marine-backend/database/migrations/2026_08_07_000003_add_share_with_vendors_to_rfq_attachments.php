<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let specific enquiry files be emailed to vendors.
 *
 * Enquiry attachments were built as strictly internal — the customer's own
 * paperwork must never reach a supplier. Vendors do, however, need the drawings
 * and spec sheets for the items being quoted.
 *
 * So sharing is opt-in per file and defaults to FALSE: every file already
 * uploaded stays internal, and a file only goes out once someone deliberately
 * ticks it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rfq_attachments', function (Blueprint $table) {
            $table->boolean('share_with_vendors')->default(false)->after('size');
        });
    }

    public function down(): void
    {
        Schema::table('rfq_attachments', function (Blueprint $table) {
            $table->dropColumn('share_with_vendors');
        });
    }
};

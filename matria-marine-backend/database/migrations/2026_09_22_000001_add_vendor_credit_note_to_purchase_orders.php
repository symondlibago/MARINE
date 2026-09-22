<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->boolean('has_credit_note')->default(false)->after('receipt_amount');
            $table->string('credit_note_number', 100)->nullable()->after('has_credit_note');
            $table->decimal('credit_note_amount', 16, 4)->default(0)->after('credit_note_number');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn(['has_credit_note', 'credit_note_number', 'credit_note_amount']);
        });
    }
};

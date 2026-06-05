<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->string('token', 64)->nullable()->unique()->after('po_number'); // vendor magic link
            $table->timestamp('opened_at')->nullable()->after('issued_date');       // vendor first viewed
            $table->timestamp('accepted_at')->nullable()->after('opened_at');       // vendor confirmed
            $table->string('accepted_by_name')->nullable()->after('accepted_at');
            $table->text('acceptance_note')->nullable()->after('accepted_by_name');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropUnique(['token']);
            $table->dropColumn(['token', 'opened_at', 'accepted_at', 'accepted_by_name', 'acceptance_note']);
        });
    }
};

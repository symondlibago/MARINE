<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rfq_vendors', function (Blueprint $table) {
            $table->string('channel')->default('email')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('rfq_vendors', function (Blueprint $table) {
            $table->dropColumn('channel');
        });
    }
};

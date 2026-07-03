<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {

        Schema::create('operating_expenses', function (Blueprint $table) {
            $table->id();
            $table->string('label')->nullable();                    // optional, e.g. "July 2026"
            $table->date('period_start');
            $table->date('period_end');
            $table->char('currency', 3)->default('USD');
            $table->decimal('exchange_rate', 16, 8)->default(1);    // currency -> base_currency
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operating_expenses');
    }
};

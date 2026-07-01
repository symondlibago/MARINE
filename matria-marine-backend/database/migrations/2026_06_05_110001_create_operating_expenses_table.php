<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Business overhead (rent, salaries, software…) — NOT tied to any job.
        // The accounting report subtracts these from gross profit to get net profit.
        Schema::create('operating_expenses', function (Blueprint $table) {
            $table->id();
            $table->string('name');                                 // e.g. Rent, Salaries
            $table->string('category')->nullable();                 // optional grouping
            $table->decimal('amount', 16, 4);                       // in `currency`
            $table->char('currency', 3)->default('USD');
            $table->decimal('exchange_rate', 16, 8)->default(1);    // currency -> base_currency
            $table->date('effective_date');                         // the month it belongs to
            $table->boolean('recurring')->default(false);           // repeats every month from effective_date
            $table->date('end_date')->nullable();                   // optional last month for a recurring cost
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

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rfq_id')->constrained('rfqs')->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();
            $table->char('currency', 3)->default('USD');
            $table->decimal('exchange_rate', 16, 8)->default(1); // multiply unit_cost to get base currency
            $table->date('valid_until')->nullable();
            $table->string('status')->default('submitted');      // submitted|selected|rejected
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->unique(['rfq_id', 'vendor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotes');
    }
};

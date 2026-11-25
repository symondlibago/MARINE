<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provision_suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vessel_id')->nullable()->constrained('provision_vessels')->nullOnDelete();
            $table->string('name');
            $table->string('port')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('currency', 8)->default('USD');
            $table->decimal('exchange_rate', 14, 6)->default(1); // supplier currency -> USD
            $table->string('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['vessel_id', 'is_active']);
        });

        Schema::create('provision_supplier_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained('provision_suppliers')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('provision_items')->cascadeOnDelete();
            $table->decimal('price', 12, 4);       // in the supplier's own currency
            $table->date('quoted_at')->nullable();
            $table->timestamps();

            $table->unique(['supplier_id', 'item_id']);
            $table->index('item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provision_supplier_prices');
        Schema::dropIfExists('provision_suppliers');
    }
};

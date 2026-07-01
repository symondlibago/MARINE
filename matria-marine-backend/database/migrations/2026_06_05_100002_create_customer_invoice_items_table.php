<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_invoice_id')->constrained('customer_invoices')->cascadeOnDelete();
            $table->boolean('is_heading')->default(false);          // section heading row (e.g. "Lashing Materials")
            $table->text('description')->nullable();
            $table->string('code')->nullable();                     // part no.
            $table->string('unit')->nullable();
            $table->decimal('qty', 12, 3)->default(0);
            $table->decimal('unit_price', 14, 2)->default(0);       // customer price per unit
            $table->decimal('line_total', 16, 2)->default(0);       // qty * unit_price
            $table->text('remarks')->nullable();                    // prints below the item
            $table->integer('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_invoice_items');
    }
};

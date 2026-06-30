<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->foreignId('rfq_item_id')->nullable()->constrained('rfq_items')->nullOnDelete();
            $table->foreignId('award_id')->nullable()->constrained('awards')->nullOnDelete();
            $table->string('description');
            $table->string('unit')->nullable();
            $table->decimal('qty', 12, 3)->default(0);
            $table->decimal('unit_cost', 14, 4)->default(0);             // in PO currency
            $table->decimal('line_total', 16, 4)->default(0);            // qty * unit_cost
            $table->text('remarks')->nullable();                         // carried from the awarded vendor's quote line
            $table->integer('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_items');
    }
};

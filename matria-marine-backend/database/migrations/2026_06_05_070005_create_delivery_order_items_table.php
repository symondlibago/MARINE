<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_order_id')->constrained('delivery_orders')->cascadeOnDelete();
            $table->foreignId('offer_item_id')->nullable()->constrained('offer_items')->nullOnDelete();
            $table->text('description')->nullable();
            $table->string('code')->nullable();
            $table->string('unit')->nullable();
            $table->decimal('qty', 12, 3)->default(0);
            $table->decimal('unit_price', 14, 2)->default(0);       // customer price per unit
            $table->decimal('discount_amount', 14, 2)->default(0);  // per unit
            $table->decimal('line_total', 16, 2)->default(0);
            $table->text('remarks')->nullable();                    // carried from the offer line
            $table->integer('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_order_items');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('awards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rfq_item_id')->constrained('rfq_items')->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();
            $table->foreignId('quote_item_id')->nullable()->constrained('quote_items')->nullOnDelete();
            $table->decimal('qty_to_buy', 12, 3)->default(0);
            $table->decimal('unit_cost', 14, 4)->default(0);
            $table->timestamps();

            $table->unique('rfq_item_id'); // one award per line item
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('awards');
    }
};

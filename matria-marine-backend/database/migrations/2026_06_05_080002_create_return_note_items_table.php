<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('return_note_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('return_note_id')->constrained('return_notes')->cascadeOnDelete();
            $table->foreignId('purchase_order_item_id')->nullable()->constrained('purchase_order_items')->nullOnDelete();
            $table->string('description');
            $table->string('unit')->nullable();
            $table->decimal('qty', 12, 3)->default(0);           // returned quantity
            $table->decimal('unit_cost', 14, 4)->default(0);     // snapshot from the PO line
            $table->decimal('line_total', 16, 4)->default(0);    // qty * unit_cost (credit)
            $table->string('reason')->nullable();
            $table->integer('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('return_note_items');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('documents')->cascadeOnDelete();
            $table->boolean('is_heading')->default(false);   // bold section-title row (no qty/price)
            $table->text('description');
            $table->string('unit')->nullable();              // pcs, mtrs, can, ltrs, hrs...
            $table->decimal('qty', 12, 3)->nullable();
            $table->decimal('unit_price', 14, 2)->nullable();
            $table->decimal('amount', 16, 2)->default(0);
            $table->integer('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_items');
    }
};

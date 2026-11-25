<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provision_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vessel_id')->constrained('provision_vessels')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('provision_categories')->cascadeOnDelete();
            $table->string('code', 20);                 // stable 7-digit workbook code
            $table->string('name');
            $table->string('variant')->nullable();      // origin / species / size
            $table->string('unit', 16)->default('PCS');
            $table->decimal('unit_price', 12, 4)->nullable();   // blank until the office fills it
            $table->unsignedSmallInteger('shelf_life_days')->nullable();
            $table->decimal('current_stock', 12, 3)->default(0); // ROB — the field crew edits most
            // Fallback usage from the workbook, used until a real period is closed.
            $table->decimal('baseline_consumption', 12, 3)->nullable();
            $table->unsignedSmallInteger('baseline_period_days')->nullable();
            // One-off manual target for a brand-new item with no usage history.
            $table->decimal('manual_target', 12, 3)->nullable();
            $table->string('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->unique(['vessel_id', 'code']);
            $table->index(['vessel_id', 'category_id']);
            $table->index(['vessel_id', 'is_active']);
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provision_items');
    }
};

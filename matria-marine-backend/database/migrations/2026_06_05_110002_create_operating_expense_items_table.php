<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Individual overhead lines within a period group (name + amount).
        Schema::create('operating_expense_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('operating_expense_id')->constrained('operating_expenses')->cascadeOnDelete();
            $table->string('name');
            $table->string('category')->nullable();
            $table->decimal('amount', 16, 4)->default(0); // in the group's currency
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operating_expense_items');
    }
};

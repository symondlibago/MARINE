<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;


return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provision_supply_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vessel_id')->constrained('provision_vessels')->cascadeOnDelete();
            $table->string('label')->nullable();          // e.g. "May 2026"
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->unsignedSmallInteger('days')->default(30);
            $table->unsignedSmallInteger('crew_size')->nullable();
            $table->enum('status', ['open', 'closed'])->default('open');
            $table->timestamp('closed_at')->nullable();
            $table->string('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['vessel_id', 'status']);
            $table->index(['vessel_id', 'end_date']);
        });

        Schema::create('provision_period_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_id')->constrained('provision_supply_periods')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('provision_items')->cascadeOnDelete();
            $table->decimal('beginning_qty', 12, 3)->default(0);
            $table->decimal('supplied_qty', 12, 3)->default(0);
            $table->decimal('ending_qty', 12, 3)->nullable();  // counted at period close
            $table->timestamps();

            $table->unique(['period_id', 'item_id']);
            $table->index('item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provision_period_items');
        Schema::dropIfExists('provision_supply_periods');
    }
};

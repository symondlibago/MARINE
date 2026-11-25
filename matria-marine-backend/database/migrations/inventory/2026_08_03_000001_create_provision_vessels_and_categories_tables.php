<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provision_vessels', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('imo')->nullable();
            $table->unsignedSmallInteger('crew_size')->default(20);
            $table->unsignedSmallInteger('previous_crew_size')->nullable();
            $table->unsignedSmallInteger('supply_period_days')->default(30);
            $table->decimal('safety_margin_pct', 5, 2)->default(10);
            $table->decimal('budget', 14, 2)->default(0);
            $table->string('currency', 8)->default('USD');       // supplier / local currency
            $table->decimal('exchange_rate', 14, 6)->default(1); // local -> USD
            $table->date('supply_date')->nullable();
            $table->date('next_supply_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('provision_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->unique('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provision_categories');
        Schema::dropIfExists('provision_vessels');
    }
};

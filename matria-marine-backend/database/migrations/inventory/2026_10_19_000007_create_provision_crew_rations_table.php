<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crew rations — the daily meal count behind the victualling budget.
 *
 * One row per day. Only the six counts are stored; meals, ration equivalents
 * and the benchmark value are worked out on read by App\Support\Inventory\
 * RationSheet, the same way the ordering figures are, so a stored total can
 * never drift away from the counts it came from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provision_crew_rations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vessel_id')->constrained('provision_vessels')->cascadeOnDelete();
            $table->date('date');
            $table->string('port')->nullable();

            // Heads fed at each sitting, crew and visitors counted apart: the
            // vessel is accountable for its crew's rations and bills visitors
            // separately.
            $table->unsignedSmallInteger('breakfast_crew')->default(0);
            $table->unsignedSmallInteger('breakfast_visitors')->default(0);
            $table->unsignedSmallInteger('lunch_crew')->default(0);
            $table->unsignedSmallInteger('lunch_visitors')->default(0);
            $table->unsignedSmallInteger('dinner_crew')->default(0);
            $table->unsignedSmallInteger('dinner_visitors')->default(0);

            $table->string('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // A day is counted once. Saving the same date again edits it.
            $table->unique(['vessel_id', 'date']);
            $table->index(['vessel_id', 'date']);
        });

        Schema::table('provision_vessels', function (Blueprint $table) {
            // What one man-day of food is allowed to cost. The benchmark value
            // is this times the rations fed, which is the number the owner sets
            // against what the provisions actually cost.
            $table->decimal('ration_rate', 10, 2)->default(0)->after('budget');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provision_crew_rations');

        Schema::table('provision_vessels', function (Blueprint $table) {
            $table->dropColumn('ration_rate');
        });
    }
};

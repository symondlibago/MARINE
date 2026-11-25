<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the ration report needs that a crew-and-visitors count cannot give.
 *
 * The owner is billed on two different bases at once. Crew and passengers are
 * charged by the RATION — three meals feed one man for one day, and that day
 * costs the agreed rate whether he ate twice or three times. Visitors are
 * charged by the MEAL, at a lower rate, and the charterer's people may be on a
 * different rate again from the owner's.
 *
 * So one "visitors" column cannot be invoiced: it has to say whose visitor,
 * and a passenger has to be told apart from a guest at lunch. The day also has
 * to record whether the vessel was at sea or in port, and who the extra heads
 * belonged to, because that is what the owner checks the invoice against.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provision_crew_rations', function (Blueprint $table) {
            // Sea or port — the owner reads this against the vessel's log.
            $table->string('location', 8)->nullable()->after('port');

            // Passengers: billed as rations, like crew.
            $table->unsignedSmallInteger('breakfast_passenger')->default(0)->after('location');
            $table->unsignedSmallInteger('lunch_passenger')->default(0)->after('breakfast_passenger');
            $table->unsignedSmallInteger('dinner_passenger')->default(0)->after('lunch_passenger');

            // The charterer's visitors, billed per meal on their own rate.
            $table->unsignedSmallInteger('breakfast_charterer')->default(0)->after('dinner_visitors');
            $table->unsignedSmallInteger('lunch_charterer')->default(0)->after('breakfast_charterer');
            $table->unsignedSmallInteger('dinner_charterer')->default(0)->after('lunch_charterer');

            // "Supt., RINA, Technicians" — who the extra heads were. Kept apart
            // from notes, which is the remarks column at the other end.
            $table->string('additional_persons')->nullable()->after('dinner_charterer');

            // Victualling bought ashore that day, shown beside the rations so
            // the two can be read against each other.
            $table->decimal('purchases_amount', 12, 2)->nullable()->after('additional_persons');
        });

        Schema::table('provision_vessels', function (Blueprint $table) {
            // Per meal, not per ration, and lower: a guest at lunch is not a
            // man fed for a day.
            $table->decimal('visitor_meal_rate', 10, 2)->default(0)->after('ration_rate');
            // The charterer's people are often on a separate agreement.
            $table->decimal('charterer_meal_rate', 10, 2)->default(0)->after('visitor_meal_rate');
        });
    }

    public function down(): void
    {
        Schema::table('provision_crew_rations', function (Blueprint $table) {
            $table->dropColumn([
                'location',
                'breakfast_passenger', 'lunch_passenger', 'dinner_passenger',
                'breakfast_charterer', 'lunch_charterer', 'dinner_charterer',
                'additional_persons', 'purchases_amount',
            ]);
        });

        Schema::table('provision_vessels', function (Blueprint $table) {
            $table->dropColumn(['visitor_meal_rate', 'charterer_meal_rate']);
        });
    }
};

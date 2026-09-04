<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The chart of accounts — Matria's own ten codes, nothing else.
 *
 * Purely additive: a new table that no existing query touches. Nothing reads it
 * until Stage 2 puts an account code on documents, so this migration cannot
 * change a single figure the system reports today.
 *
 * `gst_code` lives here and ONLY here. Documents carry an account code; their
 * GST treatment is looked up through this column at report time, so the two can
 * never disagree the way they did in the spreadsheet this replaces.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('accounts')) {
            return;
        }

        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            // Four digits today (1000..5100). Wider so a sub-account like
            // 5110 or a longer scheme later doesn't need a migration.
            $table->string('code', 20)->unique();
            $table->string('name', 150);
            // asset | liability | equity | income | expense — decides the side
            // of the trial balance and which statement the account appears on.
            $table->string('type', 20);
            // SR | ZI | ESN | OS — see App\Support\GstCodes.
            $table->string('gst_code', 8);
            $table->string('description', 255)->nullable();
            // Inactive accounts stay for history but drop out of the pickers.
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};

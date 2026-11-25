<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A requisition now has to be approved before the office sees it.
 *
 * The cook writes the order, because he is the one who knows what the galley
 * is short of. The master approves it, because he is the one answerable for
 * what his ship spends. Only then does it reach the office to be bought.
 *
 * ON THE STATUS COLUMN
 *
 * It was created as an enum of three values. Widening an enum needs
 * doctrine/dbal, which this project does not carry, and on SQLite an enum is a
 * CHECK constraint that cannot be altered at all — so the create migration has
 * been changed to make a varchar instead, and this one widens the column on the
 * databases that were built before that. New databases get the right column
 * from the start; the two existing ones are brought to the same place here.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Only MySQL has an enum to widen. SQLite databases are built fresh
        // from the create migration, which now makes a varchar.
        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE provision_requisitions MODIFY status VARCHAR(20) NOT NULL DEFAULT 'draft'"
            );
        }

        Schema::table('provision_requisitions', function (Blueprint $table) {
            // How many days of supply this order has to cover.
            //
            // Held on the order rather than read from the vessel, because the
            // vessel's figure is what she normally runs on and this is what the
            // cook asked for THIS time — a long leg, a yard period, a crew
            // change. The budget warning is worked out against it.
            $table->unsignedSmallInteger('days_of_supply')->nullable()->after('required_by');

            // The chain of hands it passed through. Kept as events rather than
            // worked out from the status, so an order that was sent back and
            // approved on a second try still says who did what and when.
            $table->timestamp('submitted_at')->nullable()->after('issued_at');
            $table->foreignId('submitted_by')->nullable()->after('submitted_at')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('submitted_by');
            $table->foreignId('approved_by')->nullable()->after('approved_at')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('declined_at')->nullable()->after('approved_by');
            $table->string('decline_reason')->nullable()->after('declined_at');
        });
    }

    public function down(): void
    {
        Schema::table('provision_requisitions', function (Blueprint $table) {
            if (DB::getDriverName() !== 'sqlite') {
                $table->dropForeign(['submitted_by']);
                $table->dropForeign(['approved_by']);
            }

            $table->dropColumn([
                'days_of_supply', 'submitted_at', 'submitted_by',
                'approved_at', 'approved_by', 'declined_at', 'decline_reason',
            ]);
        });
    }
};

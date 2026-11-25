<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // The ship an account belongs to. Null for the office, who work
            // across the whole fleet; set for a master or a cook, who work on
            // one vessel and may never reach another's stores.
            //
            // nullOnDelete rather than a cascade: a vessel can only be deleted
            // while it has never traded, and losing a ship must not delete
            // people. An account left without a vessel is refused at the gate
            // with a message saying so, which is the safe way to fail — it
            // cannot fall through to somebody else's stock.
            $table->foreignId('vessel_id')
                ->nullable()
                ->after('role')
                ->constrained('provision_vessels')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // SQLite skips the constraint on an existing table, so there is
            // nothing to drop there and asking would fail.
            if (DB::getDriverName() !== 'sqlite') {
                $table->dropForeign(['vessel_id']);
            }

            $table->dropColumn('vessel_id');
        });
    }
};

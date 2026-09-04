<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Line descriptions outgrew VARCHAR(255) — a full part specification does not
 * fit in 255 characters.
 *
 * `ALTER TABLE ... MODIFY` is MySQL-only syntax. sqlite rejects it outright,
 * which meant this migration could not run on the test connection at all and
 * the whole suite failed at the first test to touch the database.
 *
 * It is a no-op anywhere but MySQL, and correctly so: sqlite is dynamically
 * typed and never enforced the 255 limit in the first place, so there is
 * nothing to widen. (`->change()` would need doctrine/dbal, which this project
 * does not carry.)
 */
return new class extends Migration
{
    private const TABLES = ['rfq_items', 'purchase_order_items', 'return_note_items'];

    public function up(): void
    {
        $this->setType('TEXT');
    }

    public function down(): void
    {
        $this->setType('VARCHAR(255)');
    }

    private function setType(string $mysqlType): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'description')) {
                DB::statement("ALTER TABLE `{$table}` MODIFY `description` {$mysqlType} NOT NULL");
            }
        }
    }
};

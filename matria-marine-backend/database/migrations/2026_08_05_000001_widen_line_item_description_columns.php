<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TABLES = ['rfq_items', 'purchase_order_items', 'return_note_items'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            DB::statement("ALTER TABLE `{$table}` MODIFY `description` TEXT NOT NULL");
        }
    }

    public function down(): void
    {
        // Note: descriptions longer than 255 characters would be truncated on
        // the way back down.
        foreach (self::TABLES as $table) {
            DB::statement("ALTER TABLE `{$table}` MODIFY `description` VARCHAR(255) NOT NULL");
        }
    }
};

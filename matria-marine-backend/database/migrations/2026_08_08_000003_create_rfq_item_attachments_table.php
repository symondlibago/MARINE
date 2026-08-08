<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Files attached to a single enquiry line — a photo of the motor, its spec
 * sheet, a drawing — rather than to the enquiry as a whole.
 *
 * These are the files that reach vendors: a vendor asked to quote a line gets
 * that line's files with their enquiry email. Which vendors get which lines is
 * already recorded in rfq_vendor_items, so no separate "send this" flag is
 * needed here — the file simply travels with its line.
 *
 * Cascades on delete so removing a line never orphans its files.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rfq_item_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rfq_item_id')->constrained('rfq_items')->cascadeOnDelete();
            $table->string('disk')->default('r2');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('rfq_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rfq_item_attachments');
    }
};

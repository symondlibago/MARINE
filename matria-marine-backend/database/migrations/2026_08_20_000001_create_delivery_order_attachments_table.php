<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Proof of delivery: the delivery order signed and stamped by the vessel,
 * plus anything else that comes back with it.
 *
 * INTERNAL ONLY. These files are never attached to a customer email and never
 * printed on a customer document — they exist so the office can prove the goods
 * were received. The invoice screen reads them through its delivery order, so
 * one signed copy is filed once and visible from both places.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_order_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_order_id')->constrained('delivery_orders')->cascadeOnDelete();
            $table->string('disk')->default('r2');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('kind', 20)->default('signed_do');  // signed_do | packing_list | photo | other
            $table->string('note')->nullable();                 // e.g. "received by Chief Engineer"
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['delivery_order_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_order_attachments');
    }
};

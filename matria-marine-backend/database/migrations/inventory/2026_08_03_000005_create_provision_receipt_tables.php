<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provision_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vessel_id')->constrained('provision_vessels')->cascadeOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('provision_suppliers')->nullOnDelete();
            $table->foreignId('requisition_id')->nullable(); // FK added in the requisition migration
            $table->string('reference')->nullable();         // supplier DO / invoice no.
            $table->date('received_date');
            $table->enum('status', ['draft', 'confirmed'])->default('draft');
            $table->timestamp('confirmed_at')->nullable();
            $table->string('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['vessel_id', 'status']);
            $table->index(['vessel_id', 'received_date']);
        });

        Schema::create('provision_receipt_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('receipt_id')->constrained('provision_receipts')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('provision_items')->cascadeOnDelete();
            $table->decimal('ordered_qty', 12, 3)->default(0);
            $table->decimal('received_qty', 12, 3)->default(0);
            $table->decimal('unit_price', 12, 4)->nullable();
            $table->string('notes')->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->unique(['receipt_id', 'item_id']);
            $table->index('item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provision_receipt_items');
        Schema::dropIfExists('provision_receipts');
    }
};

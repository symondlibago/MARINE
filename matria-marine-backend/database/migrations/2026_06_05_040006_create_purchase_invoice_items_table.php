<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_invoice_id')->constrained('purchase_invoices')->cascadeOnDelete();
            $table->foreignId('purchase_order_item_id')->nullable()->constrained('purchase_order_items')->nullOnDelete();
            $table->foreignId('rfq_item_id')->nullable()->constrained('rfq_items')->nullOnDelete();
            $table->string('description');
            $table->string('unit')->nullable();
            $table->decimal('qty', 12, 3)->default(0);
            $table->decimal('unit_cost', 14, 4)->default(0);
            $table->decimal('line_total', 16, 4)->default(0);
            $table->string('account_code')->nullable();                  // G/L account override for the export
            // Snapshots of the ordered values for the 3-way match (PO vs invoice).
            $table->decimal('po_qty', 12, 3)->nullable();
            $table->decimal('po_unit_cost', 14, 4)->nullable();
            $table->integer('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_invoice_items');
    }
};

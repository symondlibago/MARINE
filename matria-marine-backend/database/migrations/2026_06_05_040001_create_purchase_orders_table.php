<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('po_number')->nullable()->unique();          // e.g. PO-0001
            $table->foreignId('rfq_id')->nullable()->constrained('rfqs')->nullOnDelete();
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();
            $table->string('ship_name')->nullable();                     // snapshot from enquiry
            $table->string('delivery_port')->nullable();                 // snapshot from enquiry
            $table->char('currency', 3)->default('USD');                 // PO (vendor) currency
            $table->char('base_currency', 3)->default('USD');            // company base at time of order
            $table->decimal('exchange_rate', 16, 8)->default(1);         // currency -> base_currency
            $table->string('status')->default('draft');                  // draft|issued|received|cancelled
            $table->date('issued_date')->nullable();
            $table->date('expected_date')->nullable();
            $table->decimal('subtotal', 16, 4)->default(0);              // in PO currency
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
    }
};

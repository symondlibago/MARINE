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
            $table->string('po_number')->nullable()->unique();          // e.g. MMS-PO-2026-000001
            $table->string('invoice_number')->nullable()->unique();     // MMS-INV-… assigned when the final invoice is first generated
            $table->string('token', 64)->nullable()->unique();          // vendor acceptance magic link
            $table->foreignId('rfq_id')->nullable()->constrained('rfqs')->nullOnDelete();
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();
            $table->string('ship_name')->nullable();                     // snapshot from enquiry
            $table->string('delivery_port')->nullable();                 // snapshot from enquiry
            $table->text('delivery_address')->nullable();                // customer delivery address (from the DO)
            $table->char('currency', 3)->default('USD');                 // PO (vendor) currency
            $table->char('base_currency', 3)->default('USD');            // company base at time of order
            $table->decimal('exchange_rate', 16, 8)->default(1);         // currency -> base_currency
            $table->string('status')->default('draft');                  // draft|issued|received|cancelled
            $table->date('issued_date')->nullable();
            $table->date('expected_date')->nullable();
            $table->timestamp('opened_at')->nullable();                  // vendor first viewed
            $table->timestamp('accepted_at')->nullable();                // vendor confirmed
            $table->string('accepted_by_name')->nullable();
            $table->text('acceptance_note')->nullable();
            $table->decimal('subtotal', 16, 4)->default(0);              // awarded cost, in PO currency
            $table->decimal('receipt_amount', 16, 4)->nullable();        // actual cost from the vendor's receipt (else falls back to subtotal)
            $table->decimal('expenses', 16, 4)->default(0);              // total per-vendor expenses (sum of expense_items) deducted in accounting
            $table->json('expense_items')->nullable();                   // itemised expenses [{name, amount}]
            $table->char('expense_currency', 3)->nullable();             // currency the expense lines are in (defaults to the PO currency)
            $table->decimal('expense_rate', 16, 8)->default(1);          // expense_currency -> base_currency
            $table->timestamp('paid_at')->nullable();                    // when we paid the vendor (A/P settlement)
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

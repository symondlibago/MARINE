<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // How much of a payment is applied to which document. This is what
        // "reconcile against the outstanding invoices" means: the outstanding
        // balance of an invoice is its total minus credit notes minus the sum
        // of the allocations pointing at it.
        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->foreignId('customer_invoice_id')->nullable()->constrained('customer_invoices')->cascadeOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained('purchase_orders')->cascadeOnDelete();
            $table->decimal('amount', 16, 2);
            $table->timestamps();

            $table->index('customer_invoice_id');
            $table->index('purchase_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
    }
};

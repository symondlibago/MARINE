<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The customer-facing invoice (money IN). Can be generated from an accepted
        // offer/DO, or created directly (blank) when Matria supplies the goods itself.
        Schema::create('customer_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_number')->unique();                 // MMS-INV-2026-000001
            $table->string('token', 64)->nullable()->unique();          // optional customer view link
            $table->foreignId('rfq_id')->nullable()->constrained('rfqs')->nullOnDelete();
            $table->foreignId('offer_id')->nullable()->constrained('offers')->nullOnDelete();
            $table->foreignId('delivery_order_id')->nullable()->constrained('delivery_orders')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('customer_name')->nullable();                 // snapshot
            $table->text('customer_address')->nullable();                // snapshot
            $table->string('customer_reference')->nullable();            // customer's order no.
            $table->char('currency', 3)->default('SGD');
            $table->string('status')->default('draft');                  // draft | sent | paid
            $table->decimal('subtotal', 16, 2)->default(0);
            $table->decimal('packing_cost', 16, 2)->default(0);
            $table->decimal('transportation_cost', 16, 2)->default(0);
            $table->decimal('tax_rate', 6, 3)->default(0);               // GST % the staff enters (0 = zero-rated)
            $table->decimal('tax_amount', 16, 2)->default(0);            // computed: (subtotal + delivery) × tax_rate
            $table->decimal('grand_total', 16, 2)->default(0);
            $table->date('issue_date')->nullable();
            $table->date('due_date')->nullable();
            $table->string('payment_terms')->nullable();
            $table->string('delivery_terms')->nullable();
            $table->string('origin_type')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_invoices');
    }
};

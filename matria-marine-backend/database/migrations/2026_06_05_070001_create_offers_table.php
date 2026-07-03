<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offers', function (Blueprint $table) {
            $table->id();
            $table->string('offer_number')->unique();           // OFR-0001
            $table->string('token', 64)->unique();              // customer acceptance magic link
            $table->foreignId('rfq_id')->constrained('rfqs')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('customer_name')->nullable();        // snapshot
            $table->text('customer_address')->nullable();        // snapshot
            $table->char('currency', 3)->default('USD');
            $table->string('status')->default('draft');         // draft|sent|accepted|declined
            $table->date('valid_until')->nullable();
            $table->string('payment_terms')->nullable();
            $table->string('delivery_terms')->nullable();        // Incoterms (EXW, FOB, CIF…)
            $table->string('origin_type')->nullable();           // Genuine | OEM | Aftermarket | Used
            $table->decimal('base_total', 16, 2)->default(0);    // internal: sum of vendor base costs
            $table->decimal('subtotal', 16, 2)->default(0);      // customer subtotal (marked up)
            $table->decimal('markup_total', 16, 2)->default(0);  // profit = subtotal - base_total
            $table->decimal('packing_cost', 16, 2)->default(0);         // delivery charge — packing
            $table->decimal('transportation_cost', 16, 2)->default(0);  // delivery charge — transportation
            $table->decimal('tax_rate', 6, 3)->default(0);              // GST % the staff enters (0 = zero-rated)
            $table->decimal('tax_amount', 16, 2)->default(0);           // computed: (subtotal + delivery) × tax_rate
            $table->decimal('grand_total', 16, 2)->default(0);   // subtotal + packing + transportation + GST
            $table->text('notes')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->string('accepted_by_name')->nullable();
            $table->text('acceptance_note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offers');
    }
};

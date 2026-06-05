<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->nullable()->unique();           // internal no., e.g. PINV-0001
            $table->string('vendor_invoice_no')->nullable();             // the vendor's own invoice number
            $table->foreignId('purchase_order_id')->nullable()->constrained('purchase_orders')->nullOnDelete();
            $table->foreignId('rfq_id')->nullable()->constrained('rfqs')->nullOnDelete();
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();
            $table->char('currency', 3)->default('USD');
            $table->char('base_currency', 3)->default('USD');
            $table->decimal('exchange_rate', 16, 8)->default(1);
            $table->string('status')->default('draft');                  // draft|approved|exported|cancelled
            $table->date('invoice_date')->nullable();
            $table->date('due_date')->nullable();
            $table->date('received_date')->nullable();
            $table->decimal('subtotal', 16, 4)->default(0);              // sum of line totals (invoice currency)
            $table->decimal('other_charges', 16, 4)->default(0);         // freight / misc
            $table->decimal('total', 16, 4)->default(0);                 // subtotal + other_charges
            $table->text('notes')->nullable();
            $table->timestamp('exported_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_invoices');
    }
};

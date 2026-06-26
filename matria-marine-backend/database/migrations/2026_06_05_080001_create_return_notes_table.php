<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A return note records goods returned to a vendor (defective/wrong items),
        // crediting back what we pay them. One return note per purchase order.
        Schema::create('return_notes', function (Blueprint $table) {
            $table->id();
            $table->string('rtn_number')->unique();              // RTN-0001
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->foreignId('rfq_id')->nullable()->constrained('rfqs')->nullOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $table->string('vendor_name')->nullable();           // snapshot
            $table->char('currency', 3)->default('USD');         // PO currency
            $table->string('status')->default('draft');          // draft | issued
            $table->date('return_date')->nullable();
            $table->decimal('subtotal', 16, 4)->default(0);      // total credited back (PO currency)
            $table->text('notes')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('purchase_order_id'); // one return note per PO
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('return_notes');
    }
};

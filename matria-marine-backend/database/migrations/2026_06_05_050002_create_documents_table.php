<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->string('type')->default('invoice');     // invoice|quotation|enquiry|delivery_note
            $table->string('number')->nullable()->unique();  // e.g. MMS-INV-26-000031
            $table->string('party_kind')->nullable();        // customer|vendor (who it is addressed to)
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            // Snapshot of the recipient block, so historical docs never change.
            $table->string('party_name')->nullable();
            $table->text('party_address')->nullable();
            $table->string('party_email')->nullable();
            $table->date('date')->nullable();
            $table->char('currency', 3)->default('USD');
            $table->string('order_number')->nullable();
            $table->string('terms')->nullable();
            $table->decimal('subtotal', 16, 2)->default(0);
            $table->decimal('gst_rate', 6, 2)->default(0);   // percent (0 = zero-rated)
            $table->decimal('gst_amount', 16, 2)->default(0);
            $table->decimal('total', 16, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};

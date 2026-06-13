<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_orders', function (Blueprint $table) {
            $table->id();
            $table->string('do_number')->unique();              // DO-0001
            $table->foreignId('offer_id')->nullable()->constrained('offers')->nullOnDelete();
            $table->foreignId('rfq_id')->nullable()->constrained('rfqs')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('customer_name')->nullable();        // snapshot
            $table->text('customer_address')->nullable();        // billing snapshot
            $table->text('delivery_address')->nullable();        // where the vendor delivers (defaults to customer addr)
            $table->string('customer_reference')->nullable();
            $table->char('currency', 3)->default('USD');
            $table->string('status')->default('draft');         // draft|confirmed|delivered|cancelled
            $table->date('order_date')->nullable();
            $table->date('readiness_date')->nullable();
            $table->decimal('subtotal', 16, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_orders');
    }
};

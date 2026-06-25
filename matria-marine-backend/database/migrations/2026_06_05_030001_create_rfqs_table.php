<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rfqs', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->nullable()->unique();   // e.g. RFQ-0001
            // Customer this enquiry is for — INTERNAL ONLY, never exposed to vendors.
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('customer_reference')->nullable();    // customer's own ref / PO number
            $table->string('priority')->default('normal');       // low|normal|high|urgent
            $table->json('requirements')->nullable();            // sourcing tags shown to vendors
            $table->string('ship_name')->nullable();
            $table->string('requested_by')->nullable();          // free-text requester / contact
            $table->string('delivery_port')->nullable();
            $table->date('received_date')->nullable();
            $table->char('base_currency', 3)->default('USD');
            $table->string('status')->default('draft');          // draft|sent|quoting|awarded|closed
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rfqs');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Credit memos — the customer-side mirror of a Return Note: a document that
 * credits part of an ISSUED invoice back to the customer (damaged goods, not
 * delivered, price error), so net sales = invoice − credit memo.
 * Purely additive migration: two new tables, nothing existing is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_memos', function (Blueprint $table) {
            $table->id();
            $table->string('cm_number')->nullable()->unique();
            $table->foreignId('customer_invoice_id')->constrained('customer_invoices')->cascadeOnDelete();
            $table->foreignId('rfq_id')->nullable()->constrained('rfqs')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('customer_name')->nullable();
            $table->text('customer_address')->nullable();
            $table->string('currency', 3)->default('USD');
            $table->string('status')->default('draft'); // draft | issued
            $table->date('memo_date')->nullable();
            $table->string('reason', 1000)->nullable();
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('tax_rate', 6, 3)->default(0);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('grand_total', 14, 2)->default(0);
            $table->timestamp('issued_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('credit_memo_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credit_memo_id')->constrained('credit_memos')->cascadeOnDelete();
            $table->foreignId('customer_invoice_item_id')->nullable()->constrained('customer_invoice_items')->nullOnDelete();
            $table->text('description');
            $table->string('unit', 50)->nullable();
            $table->decimal('qty', 12, 3)->default(0);
            $table->decimal('unit_price', 14, 4)->default(0);
            $table->decimal('line_total', 14, 2)->default(0);
            $table->string('reason', 500)->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_memo_items');
        Schema::dropIfExists('credit_memos');
    }
};

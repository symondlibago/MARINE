<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provision_requisitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vessel_id')->constrained('provision_vessels')->cascadeOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('provision_suppliers')->nullOnDelete();
            $table->string('req_number')->unique();
            $table->date('req_date');
            $table->date('required_by')->nullable();
            // A varchar, not an enum. The order grew an approval chain — a cook
            // raises it, a master approves it, the office buys it — and an enum
            // cannot be widened without doctrine/dbal, which this project does
            // not carry. App\Models\Inventory\Requisition holds the values.
            $table->string('status', 20)->default('draft');
            $table->string('currency', 8)->default('USD');
            $table->decimal('total_value', 14, 2)->default(0);
            $table->decimal('budget_at_issue', 14, 2)->nullable();
            $table->string('notes')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['vessel_id', 'status']);
            $table->index(['vessel_id', 'req_date']);
        });

        Schema::create('provision_requisition_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requisition_id')->constrained('provision_requisitions')->cascadeOnDelete();
            $table->foreignId('item_id')->nullable()->constrained('provision_items')->nullOnDelete();
            // Snapshot of the item as it was when the order was raised.
            $table->string('code', 20)->nullable();
            $table->string('description');
            $table->string('unit', 16)->default('PCS');
            $table->decimal('rob_at_issue', 12, 3)->default(0);
            $table->decimal('target_qty', 12, 3)->default(0);
            $table->decimal('order_qty', 12, 3)->default(0);
            $table->decimal('unit_price', 12, 4)->nullable();
            $table->decimal('line_total', 14, 2)->default(0);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->index('requisition_id');
            $table->index('item_id');
        });

        Schema::table('provision_receipts', function (Blueprint $table) {
            $table->foreign('requisition_id')->references('id')->on('provision_requisitions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('provision_receipts', function (Blueprint $table) {
            $table->dropForeign(['requisition_id']);
        });
        Schema::dropIfExists('provision_requisition_items');
        Schema::dropIfExists('provision_requisitions');
    }
};

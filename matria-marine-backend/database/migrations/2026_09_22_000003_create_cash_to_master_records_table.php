<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_to_master_records', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->date('transaction_date');
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->string('vessel')->nullable();
            $table->char('currency', 3)->default('USD');
            $table->decimal('exchange_rate', 16, 8)->default(1);
            $table->decimal('cash_delivered', 16, 4);
            $table->decimal('transit_cash_incoming', 16, 4);
            $table->decimal('transit_cash_outgoing', 16, 4);
            $table->string('funds_account_code', 20)->default('2100');
            $table->string('fee_account_code', 20)->default('4200');
            $table->string('fx_account_code', 20)->default('5200');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['transaction_date', 'customer_id']);
        });

        $now = now();
        DB::table('accounts')->insertOrIgnore([
            ['code' => '1000', 'name' => 'Cash - SGD', 'type' => 'asset', 'gst_code' => 'OS', 'description' => 'Bank and cash held in Singapore dollars.', 'is_active' => true, 'sort' => 10, 'created_at' => $now, 'updated_at' => $now],
            ['code' => '1010', 'name' => 'Cash - USD', 'type' => 'asset', 'gst_code' => 'OS', 'description' => 'Bank and cash held in US dollars.', 'is_active' => true, 'sort' => 20, 'created_at' => $now, 'updated_at' => $now],
            ['code' => '1020', 'name' => 'Cash - EUR', 'type' => 'asset', 'gst_code' => 'OS', 'description' => 'Bank and cash held in euros.', 'is_active' => true, 'sort' => 30, 'created_at' => $now, 'updated_at' => $now],
            ['code' => '1100', 'name' => 'Trade Receivables', 'type' => 'asset', 'gst_code' => 'SR', 'description' => 'Invoiced to customers and not yet collected.', 'is_active' => true, 'sort' => 40, 'created_at' => $now, 'updated_at' => $now],
            ['code' => '2000', 'name' => 'Trade Payables', 'type' => 'liability', 'gst_code' => 'SR', 'description' => 'Owed to vendors and not yet paid.', 'is_active' => true, 'sort' => 50, 'created_at' => $now, 'updated_at' => $now],
            ['code' => '2100', 'name' => 'CTM Client Funds Held', 'type' => 'liability', 'gst_code' => 'OS', 'description' => 'Client cash held temporarily for delivery to a vessel master.', 'is_active' => true, 'sort' => 55, 'created_at' => $now, 'updated_at' => $now],
            ['code' => '3000', 'name' => "Owner's Equity", 'type' => 'equity', 'gst_code' => 'OS', 'description' => 'Capital introduced and retained earnings.', 'is_active' => true, 'sort' => 60, 'created_at' => $now, 'updated_at' => $now],
            ['code' => '4000', 'name' => 'Sales - Local', 'type' => 'income', 'gst_code' => 'SR', 'description' => 'Standard-rated sales made within Singapore.', 'is_active' => true, 'sort' => 70, 'created_at' => $now, 'updated_at' => $now],
            ['code' => '4100', 'name' => 'Sales - Export/International Services', 'type' => 'income', 'gst_code' => 'ZI', 'description' => 'Zero-rated exports and international services.', 'is_active' => true, 'sort' => 80, 'created_at' => $now, 'updated_at' => $now],
            ['code' => '4200', 'name' => 'CTM Service Fee Income', 'type' => 'income', 'gst_code' => 'ZI', 'description' => 'Fee earned for arranging Cash to Master services.', 'is_active' => true, 'sort' => 85, 'created_at' => $now, 'updated_at' => $now],
            ['code' => '5000', 'name' => 'Cost of Sales', 'type' => 'expense', 'gst_code' => 'SR', 'description' => 'Goods and services bought to fulfil a customer job.', 'is_active' => true, 'sort' => 90, 'created_at' => $now, 'updated_at' => $now],
            ['code' => '5100', 'name' => 'Operating Expenses', 'type' => 'expense', 'gst_code' => 'SR', 'description' => 'Running the business and overheads not tied to one job.', 'is_active' => true, 'sort' => 100, 'created_at' => $now, 'updated_at' => $now],
            ['code' => '5200', 'name' => 'CTM FX Loss & Transfer Costs', 'type' => 'expense', 'gst_code' => 'OS', 'description' => 'Foreign-exchange variance and transfer costs on CTM transactions.', 'is_active' => true, 'sort' => 105, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_to_master_records');
        DB::table('accounts')->whereIn('code', ['2100', '4200', '5200'])->delete();
    }
};

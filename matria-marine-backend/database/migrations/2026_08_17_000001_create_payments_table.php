<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per real bank movement: money received from a customer, or
        // money paid out to a vendor. The payment itself records WHAT hit the
        // bank; payment_allocations records WHICH invoices/POs it settles, so
        // one receipt can clear several invoices and one invoice can be
        // settled by several part-payments.
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('payment_number')->unique();                 // MMS-RCPT-2026-000001 / MMS-PMT-2026-000001
            $table->string('direction', 3);                             // in = received | out = paid
            $table->string('party_type', 10);                           // customer | vendor
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $table->string('party_name')->nullable();                    // snapshot — survives a deleted party
            $table->date('payment_date');
            $table->char('currency', 3);
            $table->decimal('amount', 16, 2);                            // gross amount that hit the bank
            $table->string('method')->nullable();                        // bank transfer | cheque | cash | other
            $table->string('reference')->nullable();                     // bank reference / cheque no.
            $table->string('bank_account')->nullable();                  // e.g. "DBS - USD"
            $table->string('account_code', 60)->nullable();              // accountant's classification
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['party_type', 'customer_id']);
            $table->index(['party_type', 'vendor_id']);
            $table->index('payment_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};

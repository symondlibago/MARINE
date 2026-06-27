<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Audit trail / proof of every document email sent from the portal
        // (RFQ to vendors, purchase orders, quotations, return notes).
        Schema::create('sent_logs', function (Blueprint $table) {
            $table->id();
            $table->string('type');                 // RFQ | Purchase Order | Quotation | Return Note
            $table->string('reference')->nullable(); // RFQ-0001 / PO-0002 / OFR-0001 / RTN-0001
            $table->string('recipient_name')->nullable();
            $table->string('recipient_email')->nullable();
            $table->string('subject')->nullable();
            $table->string('status')->default('sent'); // sent | failed
            $table->text('error')->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('sent_by_name')->nullable(); // snapshot
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sent_logs');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Who moved a document number series, when, and from where to where.
        // Numbering is the sort of thing an auditor asks about, so a change to
        // it should never be invisible.
        Schema::create('document_counter_audits', function (Blueprint $table) {
            $table->id();
            $table->string('key');                                  // QTN | PO | DO | ProINV | INV | CM | RCPT | PMT
            $table->unsignedBigInteger('from_seq');
            $table->unsignedBigInteger('to_seq');
            $table->string('reason', 500)->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['key', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_counter_audits');
    }
};

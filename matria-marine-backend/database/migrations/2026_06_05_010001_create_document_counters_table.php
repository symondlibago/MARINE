<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One running counter per document type (QTN, PO, DO, ProINV, INV).
        // The sequence is continuous — it does NOT reset each year; only the
        // year stamped into the formatted number changes.
        Schema::create('document_counters', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();          // QTN | PO | DO | ProINV | INV
            $table->unsignedBigInteger('seq')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_counters');
    }
};

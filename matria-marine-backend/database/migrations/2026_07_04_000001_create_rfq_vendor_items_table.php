<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rfq_vendor_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rfq_vendor_id')->constrained('rfq_vendors')->cascadeOnDelete();
            $table->foreignId('rfq_item_id')->constrained('rfq_items')->cascadeOnDelete();
            $table->unique(['rfq_vendor_id', 'rfq_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rfq_vendor_items');
    }
};

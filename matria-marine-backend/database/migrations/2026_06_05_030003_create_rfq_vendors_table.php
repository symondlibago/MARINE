<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rfq_vendors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rfq_id')->constrained('rfqs')->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();
            $table->string('token', 64)->unique();           // vendor's unique magic-link token
            $table->unsignedInteger('seq')->nullable();       // per-enquiry vendor index → RFQ number suffix (-01, -02…)
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->string('status')->default('requested');  // requested|opened|submitted|selected|rejected
            $table->timestamps();

            $table->unique(['rfq_id', 'vendor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rfq_vendors');
    }
};

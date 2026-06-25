<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_id')->constrained('offers')->cascadeOnDelete();
            $table->foreignId('rfq_item_id')->nullable()->constrained('rfq_items')->nullOnDelete();
            $table->foreignId('award_id')->nullable()->constrained('awards')->nullOnDelete();
            $table->boolean('is_heading')->default(false);
            $table->text('description')->nullable();
            $table->string('code')->nullable();                 // supplier / part code
            $table->string('customs_code')->nullable();         // HS / customs code
            $table->string('unit')->nullable();
            $table->decimal('qty', 12, 3)->default(0);
            $table->decimal('base_price', 14, 4)->default(0);   // vendor cost per unit (internal, base currency)
            $table->decimal('markup_pct', 8, 2)->default(0);    // e.g. 10.00
            $table->decimal('unit_price', 14, 2)->default(0);   // customer price per unit = base × (1 + markup%)
            $table->decimal('discount_pct', 8, 2)->default(0);
            $table->decimal('discount_amount', 14, 2)->default(0); // per unit
            $table->decimal('markup_amount', 16, 2)->default(0);   // line profit
            $table->decimal('line_total', 16, 2)->default(0);   // (unit_price − discount) × qty
            $table->string('lead_time')->nullable();            // e.g. "2 days"
            $table->string('delivery_location')->nullable();
            $table->text('remarks')->nullable();
            $table->integer('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offer_items');
    }
};

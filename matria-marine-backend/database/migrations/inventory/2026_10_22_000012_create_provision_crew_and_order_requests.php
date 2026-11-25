<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two small things the crew asked for.
 *
 * THE CREW LIST. The ration sheet counts meals by category — crew, passengers,
 * visitors, charterer — and bills the owner from the totals. What it could not
 * say was WHO. A ration report with twenty men on it and no names is hard to
 * check and harder to dispute, and the master is the one who gets asked. So a
 * vessel now keeps a roster, and the day's crew count comes from it instead of
 * being typed twice.
 *
 * THE ORDER REQUEST. The requisition is calculated: it lists what is below
 * target. That is the right default and it is not the whole story — a cook
 * looking at an item marked GOOD still has reasons to want some ("the charterer
 * is aboard next week", "this is the last of it"), and the arithmetic cannot
 * know them. Before this, his only options were to leave it off or to distort
 * the item's target so the calculator raised it for him, which corrupts the
 * figure every future order is based on.
 *
 * So what he asks for is kept as a request, apart from the calculation, and
 * merged into the next order marked as his. The calculation stays honest and
 * the cook gets what he needs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provision_crew', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vessel_id')->constrained('provision_vessels')->cascadeOnDelete();
            $table->string('name');
            $table->string('rank')->nullable();
            // crew | passenger | charterer — which ration category this person
            // is billed under, so a roster of twenty does not quietly include
            // the charterer's two surveyors at the crew rate.
            $table->string('category', 16)->default('crew');
            $table->date('joined_on')->nullable();
            $table->date('left_on')->nullable();
            // Signed off rather than deleted: last month's ration report still
            // has to name the men it was billed for.
            $table->boolean('is_active')->default(true);
            $table->string('note')->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->index(['vessel_id', 'is_active']);
            $table->index(['vessel_id', 'category']);
        });

        Schema::create('provision_order_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vessel_id')->constrained('provision_vessels')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('provision_items')->cascadeOnDelete();
            $table->decimal('quantity', 12, 3);
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // One standing request per item. Asking for 5 kg and then for 3 kg
            // means he changed his mind, not that he wants 8.
            $table->unique(['vessel_id', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provision_order_requests');
        Schema::dropIfExists('provision_crew');
    }
};

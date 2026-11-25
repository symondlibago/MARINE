<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stock lots, and what is taken out of them.
 *
 * Until now an item had one number — how much is on board — and one price,
 * whatever the last delivery charged. That is enough to order with and not
 * enough to cost with: 40 kg bought at $10 and 40 more at $15 are not 80 kg at
 * $15, and the galley that draws 20 kg spends $200, not $300.
 *
 * So what arrives is kept as a lot: a quantity, at the price it was bought
 * for, on the day it arrived, with the day it goes off. A withdrawal eats the
 * oldest lots first and records which ones it ate, so it can be undone, and so
 * next month's cost does not quietly rewrite last month's.
 *
 * The same object answers the expiry question. A batch of milk is a lot with a
 * date on it; the board of what is about to go off is a query over these rows
 * rather than a second set of records to keep in step.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provision_stock_lots', function (Blueprint $table) {
            $table->id();
            // Carried even though item_id implies it: every query in the module
            // is scoped to one ship, and reaching through items to find out
            // which would put a join on the hot path.
            $table->foreignId('vessel_id')->constrained('provision_vessels')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('provision_items')->cascadeOnDelete();
            // Where it came from. Null for stock that was already on board when
            // lots were introduced, and for a stocktake that found more than
            // the books said.
            $table->foreignId('receipt_id')->nullable()->constrained('provision_receipts')->nullOnDelete();
            $table->date('received_on');
            $table->date('expires_on')->nullable();
            $table->decimal('unit_cost', 12, 4);
            $table->decimal('quantity', 12, 3);   // as it arrived
            $table->decimal('remaining', 12, 3);  // what is left of it
            // receipt | opening | adjustment — so a figure nobody recognises
            // can be traced back to the thing that created it.
            $table->string('source', 16)->default('receipt');
            $table->string('note')->nullable();
            $table->timestamps();

            // The FIFO scan: this ship, this item, oldest first, skipping the
            // empties. Ordering by id after the date keeps two lots received on
            // the same day in the order they were booked in.
            $table->index(['vessel_id', 'item_id', 'received_on', 'id'], 'lots_fifo_idx');
            // The expiry board, which reads across every item at once.
            $table->index(['vessel_id', 'expires_on']);
        });

        Schema::create('provision_withdrawals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vessel_id')->constrained('provision_vessels')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('provision_items')->cascadeOnDelete();
            // Which stocktake window it falls in, so a period's consumption can
            // be read either way: from the counts, or from what was logged.
            $table->foreignId('period_id')->nullable()->constrained('provision_supply_periods')->nullOnDelete();
            $table->date('date');
            $table->decimal('quantity', 12, 3);
            // What FIFO charged, at the moment it was taken.
            //
            // The one derived figure in this module that is stored rather than
            // recomputed, and deliberately: it is a consequence of which lots
            // were on board at the time. Work it out again next month, after
            // two more deliveries, and you would get a different answer and
            // last month's accounts would move.
            $table->decimal('cost', 14, 4)->default(0);
            // galley | waste | transfer — a kilo eaten and a kilo thrown away
            // both leave the store, and only one of them fed anybody.
            $table->string('reason', 16)->default('galley');
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['vessel_id', 'date']);
            $table->index(['vessel_id', 'item_id', 'date']);
            $table->index(['period_id', 'item_id']);
        });

        Schema::create('provision_withdrawal_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('withdrawal_id')->constrained('provision_withdrawals')->cascadeOnDelete();
            $table->foreignId('stock_lot_id')->constrained('provision_stock_lots')->cascadeOnDelete();
            $table->decimal('quantity', 12, 3);
            // Copied from the lot so the line still reads correctly if the
            // lot's own cost is ever corrected.
            $table->decimal('unit_cost', 12, 4);
            $table->timestamps();

            $table->index('withdrawal_id');
        });

        // A delivery is where an expiry date is known, so it is captured there
        // and travels onto the lot when the receipt is confirmed.
        Schema::table('provision_receipt_items', function (Blueprint $table) {
            $table->date('expires_on')->nullable()->after('unit_price');
        });
    }

    public function down(): void
    {
        Schema::table('provision_receipt_items', function (Blueprint $table) {
            $table->dropColumn('expires_on');
        });

        Schema::dropIfExists('provision_withdrawal_lots');
        Schema::dropIfExists('provision_withdrawals');
        Schema::dropIfExists('provision_stock_lots');
    }
};

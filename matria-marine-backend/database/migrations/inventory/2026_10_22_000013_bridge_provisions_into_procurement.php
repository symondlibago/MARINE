<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a document came from.
 *
 * The two operations are separate and must stay that way on screen. The owner
 * was clear about it: standing on the provisions side he wants to see the
 * provisions and nothing else; standing in the procurement portal he wants to
 * see everything, provisions included.
 *
 * That is one set of books with two views, not two sets of books — and the
 * difference matters. Two sets would mean two purchase-order numbering
 * sequences, two GST returns to reconcile, and a vendor who appears twice with
 * half his balance in each. One set with a filter means the accountant's totals
 * are still the company's totals.
 *
 * So every document gains one column saying which operation raised it, and
 * every screen that lists or totals documents can be narrowed by it. The
 * column is stamped once at creation and never changes: a purchase order
 * raised to provision a ship was raised for that reason whatever happens to it
 * afterwards.
 *
 * Children inherit it from their parent — an invoice off an offer off an
 * enquiry that began as a vessel's requisition is a provisions invoice, and
 * nobody has to remember to say so. App\Models\Concerns\StampsOrigin does it.
 */
return new class extends Migration
{
    /**
     * Every document that can be raised to provision a ship, and therefore
     * every document the provisions view has to be able to filter.
     */
    private const DOCUMENTS = [
        'rfqs',
        'offers',
        'purchase_orders',
        'delivery_orders',
        'customer_invoices',
        'credit_memos',
        'return_notes',
    ];

    public function up(): void
    {
        foreach (self::DOCUMENTS as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'origin')) {
                continue;
            }

            Schema::table($table, function (Blueprint $table) {
                // procurement | inventory. Defaulting to procurement is what
                // makes this migration safe on a live database: every document
                // that already exists was raised by the procurement side, and
                // saying so is simply true.
                $table->string('origin', 16)->default('procurement')->after('id');
                $table->index('origin');
            });
        }

        // The enquiry a vessel's requisition became.
        //
        // This is the join between the two operations, and it only exists in
        // this direction: the provisions side knows which enquiry it raised,
        // and the procurement side carries on exactly as it did before without
        // knowing or caring that a ship was behind it.
        if (Schema::hasTable('rfqs') && ! Schema::hasColumn('rfqs', 'requisition_id')) {
            Schema::table('rfqs', function (Blueprint $table) {
                $table->foreignId('requisition_id')->nullable()->after('customer_id')
                    ->constrained('provision_requisitions')->nullOnDelete();
            });
        }

        // A vessel buys like a customer buys, so she is given one.
        //
        // The owner's words: "the vessels is like a customer". Rather than
        // teach every procurement screen about vessels, each vessel is linked
        // to an ordinary customer record — so she already appears in every
        // picker, statement and ageing report, numbered like any other.
        if (Schema::hasTable('customers') && ! Schema::hasColumn('provision_vessels', 'customer_id')) {
            Schema::table('provision_vessels', function (Blueprint $table) {
                $table->foreignId('customer_id')->nullable()->after('imo')
                    ->constrained('customers')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        $sqlite = \Illuminate\Support\Facades\DB::getDriverName() === 'sqlite';

        if (Schema::hasColumn('provision_vessels', 'customer_id')) {
            Schema::table('provision_vessels', function (Blueprint $table) use ($sqlite) {
                if (! $sqlite) {
                    $table->dropForeign(['customer_id']);
                }
                $table->dropColumn('customer_id');
            });
        }

        if (Schema::hasColumn('rfqs', 'requisition_id')) {
            Schema::table('rfqs', function (Blueprint $table) use ($sqlite) {
                if (! $sqlite) {
                    $table->dropForeign(['requisition_id']);
                }
                $table->dropColumn('requisition_id');
            });
        }

        foreach (self::DOCUMENTS as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'origin')) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn('origin'));
            }
        }
    }
};

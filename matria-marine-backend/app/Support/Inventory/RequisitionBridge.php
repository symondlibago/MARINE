<?php

namespace App\Support\Inventory;

use App\Models\Customer;
use App\Models\Inventory\Requisition;
use App\Models\Inventory\Vessel;
use App\Models\Rfq;
use App\Models\User;
use App\Support\DocumentOrigin;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Where the provisions side hands over to procurement.
 *
 * A requisition approved on board is the ship saying "we need this and we
 * stand behind it". Buying it is the office's job, and the office already has
 * a system for buying things: ask vendors, compare what comes back, award it,
 * raise a purchase order. There is no reason to build a second one.
 *
 * So an approved requisition becomes an ordinary enquiry — the same table, the
 * same screens, the same numbering — stamped as having come from the
 * provisions side. From that moment the procurement portal treats it like any
 * other job, and the provisions view can still pick out its own.
 *
 * The vessel becomes a customer, because that is what she is in this
 * transaction. The owner said it plainly: "the vessels is like a customer".
 * Giving her an ordinary customer record means she appears in every picker,
 * statement and ageing report without a single procurement screen having to
 * learn what a vessel is.
 */
class RequisitionBridge
{
    /**
     * The enquiry for this requisition, raising it the first time it is asked
     * for and returning the same one after that.
     *
     * Idempotent on purpose. "Send to procurement" is a button somebody will
     * press twice, and the second press must not produce a second enquiry with
     * the same ship's order on it.
     */
    public static function enquiryFor(Requisition $requisition, ?User $by = null): Rfq
    {
        if ($requisition->rfq) {
            return $requisition->rfq;
        }

        return DB::transaction(function () use ($requisition, $by) {
            $requisition->load(['items', 'vessel']);
            $vessel = $requisition->vessel;
            $customer = self::customerFor($vessel);

            $rfq = Rfq::create([
                'origin' => DocumentOrigin::INVENTORY,
                'requisition_id' => $requisition->id,
                'reference' => $requisition->req_number,
                'customer_id' => $customer->id,
                'customer_reference' => $requisition->req_number,
                'priority' => 'normal',
                'ship_name' => $vessel?->name,
                'requested_by' => $by?->name,
                'received_date' => Carbon::today()->toDateString(),
                'base_currency' => $requisition->currency ?: 'USD',
                'status' => 'draft',
                'notes' => self::note($requisition),
                'created_by' => $by?->id,
            ]);

            $sort = 0;
            foreach ($requisition->items as $line) {
                $rfq->items()->create([
                    'description' => $line->description,
                    // The vessel's own catalogue code, in the field vendors are
                    // quoted against. It is not an IMPA number and does not
                    // pretend to be — it is what the ship calls the item, which
                    // is what makes a quote checkable against the order.
                    'impa_no' => $line->code,
                    'qty' => $line->order_qty,
                    'unit' => $line->unit,
                    'sort' => $sort++,
                ]);
            }

            // The ship's part is done. Marking it ordered is what moves it off
            // the office's "to buy" list, so the same order is not sent out
            // twice by two people.
            $requisition->update([
                'status' => Requisition::ISSUED,
                'issued_at' => now(),
            ]);

            return $rfq;
        });
    }

    /**
     * The customer record standing for this vessel, made once and reused.
     *
     * Matched on the link first and then on the name, so a vessel whose
     * customer was created by hand before this existed is adopted rather than
     * duplicated — a fleet with two "OEL VARUN" customers is a statement
     * nobody can reconcile.
     */
    public static function customerFor(?Vessel $vessel): Customer
    {
        if (! $vessel) {
            // Nothing to name it after. Better one tidy fallback than a
            // nameless customer record per orphaned order.
            return Customer::firstOrCreate(
                ['name' => 'Fleet provisions'],
                ['currency' => 'USD', 'is_active' => true]
            );
        }

        if ($vessel->customer_id && $existing = Customer::find($vessel->customer_id)) {
            return $existing;
        }

        $customer = Customer::where('name', $vessel->name)->first()
            ?? Customer::create([
                'name' => $vessel->name,
                'currency' => $vessel->currency ?: 'USD',
                'is_active' => true,
                'notes' => trim('Vessel of the fleet. '.($vessel->imo ? "IMO {$vessel->imo}." : '')),
            ]);

        $vessel->update(['customer_id' => $customer->id]);

        return $customer;
    }

    /** What the office needs to know about the order, in one line. */
    private static function note(Requisition $requisition): string
    {
        $parts = ["Vessel provisions · {$requisition->req_number}"];

        if ($days = $requisition->days()) {
            $parts[] = "{$days} days of supply";
        }

        if ($requisition->required_by) {
            $parts[] = 'required by '.$requisition->required_by->format('j M Y');
        }

        if ($requisition->approved_at) {
            $parts[] = 'approved on board '.$requisition->approved_at->format('j M Y');
        }

        return implode(' · ', $parts);
    }
}

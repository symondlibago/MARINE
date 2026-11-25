<?php

namespace App\Support\Inventory;

use App\Models\Inventory\Item;
use App\Models\Offer;
use App\Models\OfferItem;
use App\Support\DocumentOrigin;
use Illuminate\Support\Facades\DB;

/**
 * Put the prices the office quoted back onto the vessel's catalogue.
 *
 * The provision catalogue came out of a workbook, and most of it has no price:
 * on the live ship 172 of 218 stocked items had none. Every figure the module
 * produces leans on those prices — what an order is worth, whether it fits the
 * budget, what the galley spent — so blanks are not a cosmetic problem.
 *
 * The office already knows the prices. It quoted them: the vessel's
 * requisition became an enquiry, vendors priced it, and an offer went back to
 * the ship with a number against every line. Asking somebody to type those
 * numbers into the catalogue a second time is how they stay untyped.
 *
 * So the offer's own lines are matched back to the vessel's items and the
 * prices copied across. The match is by the ship's catalogue code, which the
 * bridge wrote onto the enquiry line precisely so this would be possible —
 * never by description, because "Beef Tenderloin" and "Beef, tenderloin
 * (Norway)" are the same thing to a cook and different strings to a computer.
 */
class OfferPrices
{
    /**
     * Copy an offer's prices onto the vessel it was quoted to.
     *
     * @return array{applied: int, skipped: int, unmatched: array<string>}
     */
    public static function applyTo(Offer $offer): array
    {
        $vessel = self::vesselFor($offer);

        if (! $vessel) {
            return ['applied' => 0, 'skipped' => 0, 'unmatched' => []];
        }

        $lines = OfferItem::where('offer_id', $offer->id)
            ->where(fn ($q) => $q->where('is_heading', false)->orWhereNull('is_heading'))
            ->get(['id', 'rfq_item_id', 'description', 'unit_price']);

        // The ship's own code, as the bridge wrote it onto the enquiry line.
        $codes = DB::table('rfq_items')
            ->whereIn('id', $lines->pluck('rfq_item_id')->filter())
            ->pluck('impa_no', 'id');

        $items = Item::where('vessel_id', $vessel->id)
            ->whereIn('code', $codes->filter()->values())
            ->get(['id', 'code', 'unit_price'])
            ->keyBy('code');

        $applied = 0;
        $skipped = 0;
        $unmatched = [];

        DB::transaction(function () use ($lines, $codes, $items, &$applied, &$skipped, &$unmatched) {
            foreach ($lines as $line) {
                $price = $line->unit_price === null ? null : (float) $line->unit_price;

                // A line quoted at nothing is a line nobody priced. Writing a
                // zero would be worse than leaving the blank, because a zero
                // reads as free rather than as unknown.
                if ($price === null || $price <= 0) {
                    $skipped++;

                    continue;
                }

                $code = $line->rfq_item_id ? ($codes[$line->rfq_item_id] ?? null) : null;
                $item = $code ? $items->get($code) : null;

                if (! $item) {
                    $unmatched[] = $line->description;
                    continue;
                }

                $item->unit_price = round($price, 4);
                $item->save();
                $applied++;
            }
        });

        return [
            'applied' => $applied,
            'skipped' => $skipped,
            // Capped: a list of forty descriptions is not a message anybody
            // reads, and the count already says how many there were.
            'unmatched' => array_slice(array_values(array_unique($unmatched)), 0, 5),
        ];
    }

    /**
     * What the office quoted for one order, per unit, by the ship's own code.
     *
     * The receiving sheet is pre-filled from the order, and the order carries
     * the price the catalogue happened to hold when it was written — which for
     * most lines is nothing, because the whole reason the order went to vendors
     * was to find out what they cost. Booking the delivery in at those blanks
     * puts the food on the shelf at zero, and the galley then eats it for free
     * for the rest of its life.
     *
     * By the time a delivery arrives the real figures exist: they are on the
     * quotation the office sent the ship. Matched by the ship's catalogue code,
     * the same way the catalogue itself is filled — never by description.
     *
     * The accepted quotation wins over a later draft, because that is the one
     * the ship agreed to pay.
     *
     * `offer` is the name to put in front of somebody only when a price was
     * actually matched — naming a quotation beside an empty set of figures
     * tells a cook nothing. `number`, `status` and `total` describe the
     * quotation whether or not any of its lines matched the ship's catalogue,
     * because the total is what she will be invoiced either way.
     *
     * @return array{offer: ?string, prices: array<string, float>, number: ?string, status: ?string, total: ?float, currency: ?string}
     */
    public static function quotedFor(\App\Models\Inventory\Requisition $requisition): array
    {
        $none = [
            'offer' => null, 'prices' => [], 'quantities' => [],
            'number' => null, 'status' => null, 'total' => null, 'currency' => null,
        ];

        $rfqId = $requisition->rfq?->id;

        if (! $rfqId) {
            return $none;
        }

        $offer = Offer::where('rfq_id', $rfqId)
            ->orderByRaw("case when status = 'accepted' then 0 else 1 end")
            ->orderByDesc('id')
            ->first();

        if (! $offer) {
            return $none;
        }

        $lines = OfferItem::where('offer_id', $offer->id)
            ->where(fn ($q) => $q->where('is_heading', false)->orWhereNull('is_heading'))
            ->get(['rfq_item_id', 'unit_price', 'qty']);

        $codes = DB::table('rfq_items')
            ->whereIn('id', $lines->pluck('rfq_item_id')->filter())
            ->pluck('impa_no', 'id');

        $prices = [];
        $quantities = [];

        foreach ($lines as $line) {
            $code = $line->rfq_item_id ? ($codes[$line->rfq_item_id] ?? null) : null;

            if (! $code) {
                continue;
            }

            // What was actually bought, which is not always what was asked for:
            // the office trims a quotation when an order comes back over the
            // vessel's budget, and the ship then counts a delivery against the
            // trimmed figure rather than against her original request.
            //
            // Kept even for a line quoted at nothing, because a quantity and a
            // price are separate facts — "fifty, price to follow" is a real
            // state for a quotation to be in.
            $quantities[(string) $code] = round((float) $line->qty, 3);

            $price = $line->unit_price === null ? null : (float) $line->unit_price;

            // Quoted at nothing is nobody's price. Same rule as the catalogue:
            // a zero reads as free rather than as unknown.
            if ($price === null || $price <= 0) {
                continue;
            }

            $prices[(string) $code] = round($price, 4);
        }

        return [
            'offer' => $prices ? $offer->offer_number : null,
            'prices' => $prices,
            'quantities' => $quantities,
            'number' => $offer->offer_number,
            'status' => $offer->status,
            // What the ship is actually charged: the lines plus delivery and
            // tax, after the markup. Not the sum of the unit prices above, and
            // the difference is the whole reason the master needs this figure
            // rather than adding the column up himself.
            'total' => $offer->grand_total === null ? null : (float) $offer->grand_total,
            'currency' => $offer->currency,
        ];
    }

    /**
     * The vessel an offer was quoted to, if it was quoted to one.
     *
     * Only a provisions offer can be: the chandling trade's offers go to real
     * customers with no ship's catalogue behind them.
     */
    public static function vesselFor(Offer $offer): ?\App\Models\Inventory\Vessel
    {
        if ($offer->origin !== DocumentOrigin::INVENTORY) {
            return null;
        }

        // Through the requisition first — that is the real link. The customer
        // is the fallback for an offer raised by hand against a vessel's
        // customer record.
        $viaRequisition = $offer->rfq_id
            ? DB::table('rfqs')
                ->join('provision_requisitions', 'provision_requisitions.id', '=', 'rfqs.requisition_id')
                ->where('rfqs.id', $offer->rfq_id)
                ->value('provision_requisitions.vessel_id')
            : null;

        if ($viaRequisition) {
            return \App\Models\Inventory\Vessel::find($viaRequisition);
        }

        return $offer->customer_id
            ? \App\Models\Inventory\Vessel::where('customer_id', $offer->customer_id)->first()
            : null;
    }
}

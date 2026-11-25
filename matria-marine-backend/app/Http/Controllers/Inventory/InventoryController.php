<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Inventory\Item;
use App\Models\Inventory\Vessel;
use App\Support\Inventory\MoneyVeil;
use App\Support\Inventory\ProvisionCalculator;
use App\Support\Inventory\VesselAccess;
use Illuminate\Http\Request;

/**
 * Shared plumbing for the provision module: resolving the vessel in scope and
 * shaping an item row so every screen sees identical field names.
 */
abstract class InventoryController extends Controller
{
    /**
     * The vessel this request works on.
     *
     * Named explicitly, always. The module began with one vessel and resolved
     * it by falling back to "the only one"; with a fleet that fallback would
     * quietly serve the wrong ship's stock, so a request that does not say
     * which vessel it means is an error rather than a guess.
     *
     * A vessel kept only for its history cannot be worked on either: its
     * figures are a record of a ship that has left the fleet.
     */
    protected function vessel(Request $request): Vessel
    {
        $id = $request->integer('vessel_id');

        if (! $id) {
            abort(422, 'No vessel given. Every inventory request names the vessel it is for.');
        }

        $vessel = Vessel::find($id);

        if (! $vessel) {
            abort(404, 'That vessel is not on the fleet.');
        }

        if (! $vessel->is_active) {
            abort(422, "{$vessel->name} is archived. Restore it before working on its stock.");
        }

        $this->confine($request, $vessel);

        return $vessel;
    }

    /**
     * A vessel account may only ever work on its own ship.
     *
     * The gate already refuses a request that names another vessel, so in
     * normal running this never fires. It is here because this is the one
     * choke point every inventory screen passes through: if a route is ever
     * added that slips past the gate, or the vessel arrives bound from the
     * path rather than the query, the wrong ship's stores are still out of
     * reach. The cost of being wrong once is a cook counting against another
     * vessel's figures, which is worth two checks.
     */
    protected function confine(Request $request, Vessel $vessel): void
    {
        $user = $request->user();

        if (VesselAccess::isOnBoard($user) && (int) $user->vessel_id !== (int) $vessel->id) {
            abort(403, "Your account belongs to another vessel.");
        }
    }

    /** Whether the office is asking, rather than someone on board. */
    protected function fromOffice(Request $request): bool
    {
        return VesselAccess::isOffice($request->user());
    }

    /**
     * Whether this account is shown what things cost.
     *
     * The office and the master are; the cook is not. The request is optional
     * because the one place this matters most — presentItem below — is called
     * from eight sites that do not all hold one, and a choke point that every
     * item row already passes through is worth more than eight chances to
     * forget.
     */
    protected function seesMoney(?Request $request = null): bool
    {
        return VesselAccess::seesMoney(($request ?? request())->user());
    }

    /** The dashboard/inventory aggregate, with money removed if it is not theirs. */
    protected function presentSummary(array $summary): array
    {
        return $this->seesMoney()
            ? $summary
            : MoneyVeil::hide($summary, MoneyVeil::SUMMARY);
    }

    /**
     * The vessel as the screens need her.
     *
     * Her row carries the monthly budget, which several screens send down whole
     * just to get at her name and crew size. Taking the figure off here is the
     * difference between a cook who is not shown the budget and a cook who is
     * not shown it on the card but can read it in the same response.
     */
    protected function presentVessel(Vessel $vessel): array
    {
        return $this->seesMoney()
            ? $vessel->toArray()
            : MoneyVeil::hide($vessel->toArray(), MoneyVeil::VESSEL);
    }

    /**
     * One item as the API returns it: stored inputs plus the calculated fields.
     * React only ever displays these — it never recomputes them.
     */
    protected function presentItem(Item $item, ProvisionCalculator $calc, ?string $categoryName = null): array
    {
        $row = [
            'id' => $item->id,
            'code' => $item->code,
            'category_id' => $item->category_id,
            // Never lazy-loads: a caller scoring the whole catalogue passes the
            // name in from a lookup map instead of triggering 900 queries.
            'category' => $categoryName ?? ($item->relationLoaded('category') ? $item->category?->name : null),
            'name' => $item->name,
            'variant' => $item->variant,
            'unit' => $item->unit,
            // unit_price is deliberately NOT set here. The union below keeps
            // the LEFT side's value for a duplicate key, and the calculator is
            // the one that decides what an item costs — the batch on the shelf
            // when there is one, the office's standing price when there is not.
            // It returns the stored figure as standing_price for the edit form.
            'shelf_life_days' => $item->shelf_life_days,
            'current_stock' => (float) $item->current_stock,
            'manual_target' => $item->manual_target === null ? null : (float) $item->manual_target,
            'notes' => $item->notes,
            'is_active' => (bool) $item->is_active,
        ] + $calc->calculate($item);

        // Deliberately NOT veiled here, though this is the one place every item
        // row is built and veiling it would have been a single line.
        //
        // These rows are not only displayed. RequisitionController::buildLines
        // scores the catalogue through this method and then SAVES what comes
        // back — unit price and line total included. Blanking the money on the
        // way out blanked it on the way into the database too, and a cook's
        // order came to nothing at all: no total, so no budget warning, so the
        // one thing he was supposed to still be told went quiet.
        //
        // So the veil goes on at the response, in presentItems below, and never
        // in the middle of a calculation.
        return $row;
    }

    /**
     * Item rows on their way to a screen, with money removed if it is not
     * theirs to see.
     *
     * Called where rows become a response, not where they are computed. See the
     * note in presentItem for why that distinction is the whole point.
     *
     * @param  array<int, array>  $rows
     * @return array<int, array>
     */
    protected function presentItems(array $rows): array
    {
        return $this->seesMoney() ? $rows : MoneyVeil::hide($rows, MoneyVeil::ITEM);
    }

    protected function ok(array $data = [], ?string $message = null, int $status = 200)
    {
        return response()->json(array_filter([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], fn ($v) => $v !== null), $status);
    }
}

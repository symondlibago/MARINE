<?php

namespace App\Http\Controllers\Inventory;

use App\Models\Inventory\Item;
use App\Models\Inventory\Requisition;
use App\Models\Inventory\SupplyPeriod;
use App\Models\Inventory\Vessel;
use App\Support\Inventory\ProvisionCalculator;
use App\Support\Inventory\ProvisionCatalogue;
use App\Support\Inventory\VesselAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The fleet, and the reference values behind each vessel's calculations.
 *
 * One vessel is a ship; the list of them is the office's view of the whole
 * fleet, which is why the index carries each vessel's figures rather than just
 * its name. Those figures come from the same calculator the vessel's own
 * dashboard uses, so a card and the screen it opens can never disagree.
 */
class VesselController extends InventoryController
{
    /** The fleet, with the figures each card shows. */
    public function index(Request $request)
    {
        $vessels = Vessel::query()
            ->when(! $request->boolean('archived'), fn ($q) => $q->where('is_active', true))
            ->orderBy('name')
            ->get();

        if ($vessels->isEmpty()) {
            return $this->ok(['vessels' => []]);
        }

        $ids = $vessels->pluck('id');

        // Every vessel's catalogue in one query rather than one per vessel.
        $itemsByVessel = Item::whereIn('vessel_id', $ids)
            ->where('is_active', true)
            ->get()
            ->groupBy('vessel_id');

        $openPeriods = SupplyPeriod::whereIn('vessel_id', $ids)
            ->where('status', 'open')
            ->get()
            ->keyBy('vessel_id');

        // Who can sign in for each ship. A vessel nobody can log into is a
        // vessel whose cook is still counting on paper, so the card says so
        // rather than leaving the office to click in and find out.
        $crewByVessel = \App\Models\User::whereIn('vessel_id', $ids)
            ->whereIn('role', VesselAccess::ON_BOARD)
            ->get(['id', 'vessel_id', 'role', 'is_active'])
            ->groupBy('vessel_id');

        // The newest requisition per vessel, without a query each.
        $latestRequisitions = Requisition::whereIn('vessel_id', $ids)
            ->whereIn('id', function ($q) use ($ids) {
                $q->selectRaw('MAX(id)')
                    ->from('provision_requisitions')
                    ->whereIn('vessel_id', $ids)
                    ->groupBy('vessel_id');
            })
            ->get(['id', 'vessel_id', 'req_number', 'req_date', 'status', 'total_value'])
            ->keyBy('vessel_id');

        $rows = $vessels->map(function (Vessel $vessel) use ($itemsByVessel, $openPeriods, $latestRequisitions, $crewByVessel) {
            $items = $itemsByVessel->get($vessel->id, collect());
            $calc = ProvisionCalculator::for($vessel);

            $scored = $items->map(fn (Item $item) => $calc->calculate($item))->all();
            $crew = $crewByVessel->get($vessel->id, collect());

            return $vessel->toArray() + [
                'summary' => $calc->summarize($scored),
                'open_period' => $openPeriods->get($vessel->id)?->only(['id', 'label', 'start_date', 'days']),
                'last_requisition' => $latestRequisitions->get($vessel->id),
                'crew' => [
                    'total' => $crew->count(),
                    'active' => $crew->where('is_active', true)->count(),
                    'master' => $crew->where('role', VesselAccess::MASTER)->where('is_active', true)->isNotEmpty(),
                    'cook' => $crew->where('role', VesselAccess::COOK)->where('is_active', true)->isNotEmpty(),
                ],
            ];
        });

        return $this->ok(['vessels' => $rows->values()]);
    }

    /**
     * One vessel's settings, and what this account may do with her.
     *
     * The access block travels with the vessel because every screen in the
     * shell already waits on this one request. It is drawn from the same
     * definition the middleware refuses by, so a button the server would
     * reject is never offered in the first place.
     */
    public function show(Request $request)
    {
        $vessel = $this->vessel($request);
        $user = $request->user();

        return $this->ok([
            'vessel' => $this->presentVessel($vessel),
            'access' => [
                'scope' => VesselAccess::scope($user),
                'rank' => VesselAccess::RANKS[$user->role] ?? null,
                'office' => VesselAccess::isOffice($user),
                'master' => VesselAccess::isOffice($user) || VesselAccess::isMaster($user),
                // Whether to draw a price at all. The same accounts as `master`
                // today and a different question — see VesselAccess::seesMoney.
                // The API has already taken the figures out of everything below
                // this; the flag is so a screen does not draw an empty column
                // and a dash where a price would have been.
                'money' => VesselAccess::seesMoney($user),
            ],
        ]);
    }

    /**
     * Add a ship, with the master provision list already on her.
     *
     * A vessel with an empty catalogue is a vessel nobody can count, so unless
     * the office says otherwise the 879 items go on at the same moment. Each
     * starts at zero stock: what is actually aboard is whatever her crew count
     * first, not whatever another ship happened to have.
     */
    public function store(Request $request)
    {
        $data = $this->validated($request);
        $stock = $request->boolean('stock_catalogue', true);

        $vessel = DB::transaction(function () use ($data, $stock) {
            $vessel = Vessel::create($data + ['is_active' => true]);

            if ($stock) {
                ProvisionCatalogue::stock($vessel);
            }

            return $vessel;
        });

        $added = $vessel->items()->count();

        return $this->ok(
            ['vessel' => $vessel, 'items_added' => $added],
            $added > 0
                ? "{$vessel->name} added to the fleet with {$added} items to count against."
                : "{$vessel->name} added to the fleet.",
            201
        );
    }

    public function update(Request $request, Vessel $vessel)
    {
        // Bound from the path, so it never went through vessel(): a master
        // editing /vessels/7 while he sails on 3 has to be refused here.
        $this->confine($request, $vessel);

        $vessel->update($this->validated($request, $vessel));

        return $this->ok(['vessel' => $vessel->fresh()], 'Settings saved.');
    }

    /**
     * Take a vessel off the fleet.
     *
     * One that has traded is archived rather than deleted: its requisitions,
     * deliveries and stocktakes are the fleet's record, and a cascade would
     * take a year of history with it. One added by mistake is simply removed.
     */
    public function destroy(Vessel $vessel)
    {
        // Her crew's logins outlive neither route. Removing people as a side
        // effect of removing a ship is not a decision to take quietly, and
        // archiving a vessel whose master can still sign in is worse, so both
        // routes ask for the accounts to be closed first.
        if ($vessel->accounts()->exists()) {
            return response()->json([
                'success' => false,
                'message' => "{$vessel->name} still has crew accounts. Remove those first — they are people's logins, not the ship's data.",
            ], 422);
        }

        if (! $vessel->hasHistory()) {
            $name = $vessel->name;
            DB::transaction(function () use ($vessel) {
                $vessel->items()->delete();
                $vessel->delete();
            });

            return $this->ok([], "{$name} removed.");
        }

        if (! $vessel->is_active) {
            return response()->json([
                'success' => false,
                'message' => "{$vessel->name} is already archived.",
            ], 422);
        }

        $vessel->update(['is_active' => false]);

        return $this->ok(
            ['vessel' => $vessel->fresh()],
            "{$vessel->name} archived. Its history is kept and can be restored."
        );
    }

    /**
     * Catch a ship up with the master provision list.
     *
     * Adds whatever she is missing and touches nothing she already has — no
     * price, no count, no note. For a vessel added before the list was put on
     * automatically, and for one that should pick up items added to the master
     * list since she joined.
     */
    public function catalogue(Vessel $vessel)
    {
        if (! ProvisionCatalogue::exists()) {
            return response()->json([
                'success' => false,
                'message' => 'The master provision list is not on this server.',
            ], 422);
        }

        $result = ProvisionCatalogue::stock($vessel);

        return $this->ok(
            ['vessel' => $vessel->fresh(), 'result' => $result],
            $result['added'] > 0
                ? "{$result['added']} items added to {$vessel->name}. Her own prices and counts are untouched."
                : "{$vessel->name} already has every item on the master list."
        );
    }

    /** Back onto the fleet, with everything it had. */
    public function restore(Vessel $vessel)
    {
        $vessel->update(['is_active' => true]);

        return $this->ok(['vessel' => $vessel->fresh()], "{$vessel->name} is back on the fleet.");
    }

    /**
     * Settings the office alone sets: what the ship is, and what she costs.
     *
     * The master decides how his vessel is provisioned — how many to feed, how
     * long the next delivery has to last, how much buffer he wants on top. He
     * does not set his own budget, and he does not set the rates the owner is
     * invoiced at: those are the commercial side of the same screen, and they
     * are agreed ashore.
     */
    private const OFFICE_ONLY = [
        'name', 'imo', 'budget', 'currency', 'exchange_rate',
        'ration_rate', 'visitor_meal_rate', 'charterer_meal_rate',
    ];

    /**
     * A vessel's reference values.
     *
     * For the office only the name is required: a ship can be added the moment
     * she joins the fleet and have her crew size and rates filled in later,
     * rather than blocking the office until every figure is to hand.
     */
    private function validated(Request $request, ?Vessel $vessel = null): array
    {
        $office = $this->fromOffice($request);

        // The master's own screen does not draw these fields, so a payload
        // carrying them is not an accident worth absorbing silently — being
        // told beats saving a budget that then reads back unchanged.
        if (! $office && array_intersect(self::OFFICE_ONLY, array_keys($request->all()))) {
            abort(403, "The budget, the billing rates and the ship's particulars are set by the office. You can change how she is provisioned.");
        }

        // What the master may change: the ship as she is sailing.
        $rules = [
            'crew_size' => ['sometimes', 'integer', 'min:1', 'max:500'],
            'previous_crew_size' => ['nullable', 'integer', 'min:0', 'max:500'],
            'supply_period_days' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'safety_margin_pct' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'supply_date' => ['nullable', 'date'],
            'next_supply_date' => ['nullable', 'date'],
        ];

        if ($office) {
            $rules += [
                'name' => ['required', 'string', 'max:255'],
                'imo' => ['nullable', 'string', 'max:32'],
                'budget' => ['sometimes', 'numeric', 'min:0', 'max:99999999'],
                // What one man-day of food is allowed to cost, against which
                // the crew ration sheet sets what was actually fed. Visitors
                // are billed by the meal instead, at a lower rate — and the
                // charterer's people are often on an agreement of their own.
                'ration_rate' => ['nullable', 'numeric', 'min:0', 'max:10000'],
                'visitor_meal_rate' => ['nullable', 'numeric', 'min:0', 'max:10000'],
                'charterer_meal_rate' => ['nullable', 'numeric', 'min:0', 'max:10000'],
                'currency' => ['sometimes', 'string', 'max:8'],
                'exchange_rate' => ['sometimes', 'numeric', 'min:0.000001', 'max:1000000'],
            ];

            // Two ships on the fleet with one IMO number is a data-entry slip,
            // and an expensive one: the number is how the owner identifies the
            // vessel.
            $rules['imo'][] = \Illuminate\Validation\Rule::unique('provision_vessels', 'imo')
                ->ignore($vessel?->id)
                ->whereNotNull('imo');
        }

        return $request->validate($rules, [
            'imo.unique' => 'Another vessel on the fleet already has that IMO number.',
        ]);
    }
}

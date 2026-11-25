<?php

namespace App\Http\Controllers\Inventory;

use App\Models\Inventory\Item;
use App\Models\Inventory\Receipt;
use App\Models\Inventory\Requisition;
use App\Models\Inventory\SupplyPeriod;
use App\Support\Inventory\ProvisionCalculator;
use Illuminate\Http\Request;

/**
 * Read-only summary for the crew: what is low, what is going off, what is on
 * its way, and — for the accounts shown money — the budget and what the next
 * order comes to. No editable fields, no visible formulas.
 */
class DashboardController extends InventoryController
{
    public function index(Request $request)
    {
        $vessel = $this->vessel($request);
        $calc = ProvisionCalculator::for($vessel);

        $items = Item::where('vessel_id', $vessel->id)->where('is_active', true)->get();

        $rows = [];
        $urgent = [];
        foreach ($items as $item) {
            $row = $this->presentItem($item, $calc);
            $rows[] = $row;
            if ($row['status'] === ProvisionCalculator::ORDER_NOW) {
                $urgent[] = $row;
            }
        }

        // Worst first: the items furthest below target need attention soonest.
        usort($urgent, function ($a, $b) {
            $ra = $a['target_qty'] > 0 ? $a['current_stock'] / $a['target_qty'] : 1;
            $rb = $b['target_qty'] > 0 ? $b['current_stock'] / $b['target_qty'] : 1;

            return $ra <=> $rb;
        });

        return $this->ok([
            'vessel' => $this->presentVessel($vessel),
            'summary' => $this->presentSummary($calc->summarize($rows)),
            // Veiled on the way out, not on the way in: the summary above was
            // totalled from the same rows with their real prices on.
            'urgent' => $this->presentItems(array_slice($urgent, 0, 12)),
            'open_period' => SupplyPeriod::open($vessel->id),
            'last_closed_period' => $calc->lastClosedPeriod(),
            // The cook gets the order and where it has got to, without what it
            // came to. Selected rather than veiled: there is no point reading a
            // column out of the database only to blank it on the way past.
            'recent_requisitions' => Requisition::where('vessel_id', $vessel->id)
                ->latest('req_date')->latest('id')->limit(5)
                ->get($this->seesMoney($request)
                    ? ['id', 'req_number', 'req_date', 'status', 'total_value', 'currency']
                    : ['id', 'req_number', 'req_date', 'status', 'currency'])
                ->map(fn (Requisition $r) => $r->toArray() + ['status_label' => $r->statusLabel()])
                ->values(),
            'recent_receipts' => Receipt::with('supplier:id,name')
                ->where('vessel_id', $vessel->id)
                ->latest('received_date')->latest('id')->limit(5)
                ->get(['id', 'supplier_id', 'reference', 'received_date', 'status']),
            // Orders bought ashore and not booked in yet. Same rule as the
            // Receiving screen's own list — issued, with no confirmed delivery
            // against it — so the card and the list cannot disagree.
            'expected_deliveries' => Requisition::where('vessel_id', $vessel->id)
                ->where('status', Requisition::ISSUED)
                ->whereDoesntHave('receipts', fn ($q) => $q->where('status', 'confirmed'))
                ->count(),
        ]);
    }
}

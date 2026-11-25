<?php

namespace App\Http\Controllers\Inventory;

use App\Models\Inventory\Category;
use App\Models\Inventory\Item;
use App\Models\Inventory\Receipt;
use App\Models\Inventory\Requisition;
use App\Models\Inventory\SupplyPeriod;
use App\Support\Inventory\MoneyVeil;
use App\Support\Inventory\ProvisionCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Office reporting: what was ordered month by month against the budget, plus
 * where the money goes by category.
 */
class ReportController extends InventoryController
{
    public function monthly(Request $request)
    {
        $vessel = $this->vessel($request);

        $from = $request->filled('from')
            ? Carbon::parse($request->query('from'))->startOfDay()
            : Carbon::today()->subMonths(11)->startOfMonth();
        $to = $request->filled('to')
            ? Carbon::parse($request->query('to'))->endOfDay()
            : Carbon::today()->endOfMonth();

        $requisitions = Requisition::where('vessel_id', $vessel->id)
            ->whereBetween('req_date', [$from->toDateString(), $to->toDateString()])
            ->get(['id', 'req_number', 'req_date', 'status', 'total_value']);

        $months = [];
        $cursor = $from->copy()->startOfMonth();
        while ($cursor <= $to) {
            $months[$cursor->format('Y-m')] = [
                'month' => $cursor->format('Y-m'),
                'label' => $cursor->format('M Y'),
                'requisitions' => 0,
                'items' => 0,
                'order_value' => 0.0,
                'budget' => (float) $vessel->budget,
            ];
            $cursor->addMonth();
        }

        $itemCounts = DB::table('provision_requisition_items')
            ->whereIn('requisition_id', $requisitions->pluck('id'))
            // "lines" is a reserved word in MariaDB — alias it to something safe.
            ->select('requisition_id', DB::raw('COUNT(*) as line_count'))
            ->groupBy('requisition_id')
            ->pluck('line_count', 'requisition_id');

        foreach ($requisitions as $req) {
            $key = Carbon::parse($req->req_date)->format('Y-m');

            if (! isset($months[$key])) {
                continue;
            }

            $months[$key]['requisitions']++;
            $months[$key]['items'] += (int) ($itemCounts[$req->id] ?? 0);
            $months[$key]['order_value'] = round($months[$key]['order_value'] + (float) $req->total_value, 2);
        }

        $months = array_values(array_map(function ($m) {
            $m['balance'] = round($m['budget'] - $m['order_value'], 2);
            $m['over_budget'] = $m['budget'] > 0 && $m['order_value'] > $m['budget'];

            return $m;
        }, $months));

        $report = [
            'vessel' => $this->presentVessel($vessel),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'months' => $months,
            'totals' => [
                'requisitions' => array_sum(array_column($months, 'requisitions')),
                'items' => array_sum(array_column($months, 'items')),
                'order_value' => round(array_sum(array_column($months, 'order_value')), 2),
            ],
            'receipts' => Receipt::where('vessel_id', $vessel->id)
                ->where('status', 'confirmed')
                ->whereBetween('received_date', [$from->toDateString(), $to->toDateString()])
                ->count(),
        ];

        // How many orders a month, and how many lines on them, is the ship's
        // own activity and stays. What they came to does not — and over_budget
        // survives with it, so a cook reading the year can still see which
        // months ran hot without being told by how much.
        return $this->ok($this->seesMoney($request)
            ? $report
            : MoneyVeil::hide($report, ['order_value', 'budget', 'balance']));
    }

    /** Where the next order's money goes, grouped by provision category. */
    public function byCategory(Request $request)
    {
        $vessel = $this->vessel($request);
        $calc = ProvisionCalculator::for($vessel);
        $names = Category::pluck('name', 'id');

        $items = Item::where('vessel_id', $vessel->id)->where('is_active', true)->get();

        $rows = [];
        foreach ($items as $item) {
            $c = $calc->calculate($item);
            $key = $item->category_id;

            $rows[$key] ??= [
                'category_id' => $key,
                'category' => $names[$key] ?? '—',
                'items' => 0,
                'to_order' => 0,
                'order_value' => 0.0,
                'order_now' => 0,
                'low' => 0,
            ];

            $rows[$key]['items']++;
            $rows[$key]['order_value'] = round($rows[$key]['order_value'] + $c['order_value'], 2);

            if ($c['order_qty'] > 0) {
                $rows[$key]['to_order']++;
            }
            if ($c['status'] === ProvisionCalculator::ORDER_NOW) {
                $rows[$key]['order_now']++;
            }
            if ($c['status'] === ProvisionCalculator::LOW) {
                $rows[$key]['low']++;
            }
        }

        $rows = array_values($rows);
        // Sorted by value before the veil, not after: the cook sees the same
        // order of categories as the office — biggest spend at the top — he is
        // just not told the number it was sorted on.
        usort($rows, fn ($a, $b) => $b['order_value'] <=> $a['order_value']);

        if (! $this->seesMoney($request)) {
            $rows = MoneyVeil::hide($rows, ['order_value']);
        }

        return $this->ok([
            'categories' => $rows,
            'periods' => SupplyPeriod::where('vessel_id', $vessel->id)
                ->where('status', 'closed')
                ->orderByDesc('end_date')->limit(12)
                ->get(['id', 'label', 'start_date', 'end_date', 'days', 'crew_size']),
        ]);
    }
}

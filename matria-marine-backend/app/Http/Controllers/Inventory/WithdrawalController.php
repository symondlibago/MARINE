<?php

namespace App\Http\Controllers\Inventory;

use App\Models\Inventory\Category;
use App\Models\Inventory\Item;
use App\Models\Inventory\StockLot;
use App\Models\Inventory\Withdrawal;
use App\Support\Inventory\MoneyVeil;
use App\Support\Inventory\ProvisionCalculator;
use App\Support\Inventory\StockLedger;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What the cook took out of the store, and what is still in it by batch.
 *
 * The store can be kept two ways and both are allowed, because both happen:
 * some cooks write down every draw as they make it, others only count the
 * shelves at the end of the week. Neither is compulsory. A logged withdrawal
 * costs itself at FIFO the moment it is made; a count books whatever gap it
 * finds. Either way the month adds up, and a ship that does both gets the
 * detail without being asked to do the work twice.
 */
class WithdrawalController extends InventoryController
{
    /** The log, newest first, with the month's totals by reason. */
    public function index(Request $request)
    {
        $vessel = $this->vessel($request);

        [$from, $to] = $this->window($request);

        $rows = Withdrawal::with(['item:id,code,name,variant,unit', 'author:id,name'])
            ->where('vessel_id', $vessel->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->when($request->integer('item_id'), fn ($q, $id) => $q->where('item_id', $id))
            ->when($request->filled('reason'), fn ($q) => $q->where('reason', $request->query('reason')))
            ->orderByDesc('date')->orderByDesc('id')
            ->get();

        // Totals over the window rather than over the page, so the figures do
        // not change when a filter narrows the list.
        $totals = Withdrawal::where('vessel_id', $vessel->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('reason, SUM(cost) as cost, COUNT(*) as entries')
            ->groupBy('reason')
            ->get()
            ->mapWithKeys(fn ($r) => [$r->reason => [
                'cost' => round((float) $r->cost, 2),
                'entries' => (int) $r->entries,
            ]]);

        $money = $this->seesMoney($request);

        $payload = [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'withdrawals' => $rows->map(fn (Withdrawal $w) => $this->present($w))->values()->all(),
            'totals' => [
                'by_reason' => $totals->all(),
                'cost' => round((float) $totals->sum('cost'), 2),
                'entries' => (int) $totals->sum('entries'),
            ],
        ];

        // The veil is applied before the reason list is added, not after. Those
        // options carry `value` as an identifier — "galley", "waste" — and the
        // same key is money on a batch two screens over, so the one shape that
        // must not be touched is kept out of reach of the rule that touches it.
        if (! $money) {
            $payload = MoneyVeil::hide($payload, MoneyVeil::WITHDRAWAL);
        }

        $payload['reasons'] = collect(Withdrawal::REASONS)
            ->map(fn ($label, $value) => ['value' => $value, 'label' => $label])
            ->values()
            ->all();

        return $this->ok($payload);
    }

    /**
     * Record a day's draw — several items in one request.
     *
     * A cook does not go to the store once per ingredient, so he should not
     * have to save once per ingredient either.
     */
    public function store(Request $request)
    {
        $vessel = $this->vessel($request);

        $data = $request->validate([
            'date' => ['nullable', 'date'],
            'reason' => ['nullable', 'string', 'in:'.implode(',', array_keys(Withdrawal::REASONS))],
            'note' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.001', 'max:9999999'],
            'lines.*.reason' => ['nullable', 'string', 'in:'.implode(',', array_keys(Withdrawal::REASONS))],
            'lines.*.lot_id' => ['nullable', 'integer'],
            'lines.*.note' => ['nullable', 'string', 'max:255'],
        ]);

        $date = Carbon::parse($data['date'] ?? Carbon::today());

        $items = Item::where('vessel_id', $vessel->id)
            ->whereIn('id', array_column($data['lines'], 'item_id'))
            ->get()
            ->keyBy('id');

        // Checked before anything is written, so a bad line does not leave half
        // the day's draw recorded.
        foreach ($data['lines'] as $line) {
            $item = $items->get($line['item_id']);

            if (! $item) {
                return $this->refuse('One of those items is not on this vessel.');
            }

            if (round((float) $line['quantity'], 3) > round((float) $item->current_stock, 3)) {
                return $this->refuse(
                    "There is only {$this->trim($item->current_stock)} {$item->unit} of {$item->label()} on board — "
                    ."you cannot take {$this->trim($line['quantity'])}. Count it first if the shelf says otherwise."
                );
            }
        }

        $saved = DB::transaction(function () use ($data, $items, $date, $request) {
            $saved = [];

            foreach ($data['lines'] as $line) {
                $withdrawal = StockLedger::withdraw($items->get($line['item_id']), (float) $line['quantity'], [
                    'date' => $date->toDateString(),
                    'reason' => $line['reason'] ?? $data['reason'] ?? Withdrawal::GALLEY,
                    'note' => $line['note'] ?? $data['note'] ?? null,
                    'lot_id' => $line['lot_id'] ?? null,
                    'user_id' => $request->user()?->id,
                ]);

                if ($withdrawal) {
                    $saved[] = $withdrawal;
                }
            }

            return $saved;
        });

        $cost = round(array_sum(array_map(fn ($w) => (float) $w->cost, $saved)), 2);
        $count = count($saved);
        $money = $this->seesMoney($request);

        $rows = ['withdrawals' => array_map(
            fn ($w) => $this->present($w->load('item:id,code,name,variant,unit')),
            $saved
        )];

        // The confirmation says what it cost, to the people whose business that
        // is. To the cook it says the thing he actually did, which is take food
        // off the shelf — a message is no place to leak what the screen behind
        // it has just been cleared of.
        $message = $money
            ? ($count === 1
                ? "Recorded — {$this->money($cost)} out of the store."
                : "{$count} items recorded — {$this->money($cost)} out of the store.")
            : ($count === 1
                ? 'Recorded and taken off the shelf.'
                : "{$count} items recorded and taken off the shelf.");

        return $this->ok(
            $money ? $rows : MoneyVeil::hide($rows, MoneyVeil::WITHDRAWAL),
            $message,
            201
        );
    }

    /** Undo one, putting the quantity back on the batches it came from. */
    public function destroy(Request $request, Withdrawal $withdrawal)
    {
        // Bound from the path, so the vessel in scope has to be checked against
        // it — otherwise one ship's id in the URL undoes another ship's work.
        if ((int) $withdrawal->vessel_id !== $this->vessel($request)->id) {
            abort(404, 'That withdrawal is not this vessel\'s.');
        }

        if ($withdrawal->period && $withdrawal->period->status === 'closed') {
            return $this->refuse(
                "That withdrawal belongs to {$withdrawal->period->label}, which is closed and counted. "
                .'Record a correction against the open period instead.'
            );
        }

        StockLedger::undo($withdrawal);

        return $this->ok([], 'Withdrawal undone — the stock is back on its batches.');
    }

    /**
     * What is on board, batch by batch: what it cost and when it goes off.
     *
     * The same rows answer both questions, which is why there is no separate
     * expiry record to keep in step. Items with nothing left are left out.
     */
    public function lots(Request $request)
    {
        $vessel = $this->vessel($request);
        $today = Carbon::today();
        // The crew can widen or narrow this board's window; left alone it is
        // the one every other screen warns on, so they agree by default.
        $soon = (int) ($request->integer('within') ?: ProvisionCalculator::EXPIRY_WINDOW_DAYS);

        $names = Category::pluck('name', 'id');

        // shelf_life_days comes along because the expiry is worked out from it
        // when nobody wrote a date on the packet — see StockLot::expiry().
        $lots = StockLot::with('item:id,code,name,variant,unit,category_id,shelf_life_days')
            ->where('vessel_id', $vessel->id)
            ->where('remaining', '>', 0)
            ->when($request->integer('item_id'), fn ($q, $id) => $q->where('item_id', $id))
            ->orderBy('received_on')
            ->orderBy('id')
            ->get();

        $rows = $lots->map(function (StockLot $lot) use ($names, $today) {
            $shelfLife = $lot->item?->shelf_life_days;
            $expiry = $lot->expiry($shelfLife);

            return [
                'id' => $lot->id,
                'item_id' => $lot->item_id,
                'code' => $lot->item?->code,
                'name' => $lot->item?->label(),
                'unit' => $lot->item?->unit,
                'category' => $names[$lot->item?->category_id] ?? null,
                'received_on' => $lot->received_on?->toDateString(),
                'expires_on' => $expiry?->toDateString(),
                // So the board can say so. A date read off a packet is a fact;
                // one counted from a shelf life is this vessel's best guess,
                // and a cook throwing food away deserves to know which he is
                // looking at.
                'expiry_estimated' => $expiry !== null && $lot->expiryIsEstimated(),
                'days_left' => $lot->daysLeft($today, $shelfLife),
                'remaining' => (float) $lot->remaining,
                'quantity' => (float) $lot->quantity,
                'unit_cost' => (float) $lot->unit_cost,
                'value' => $lot->value(),
                'source' => $lot->source,
                'note' => $lot->note,
            ];
        })
            // Sorted here, not in SQL: half these dates do not exist in the
            // database any more. Soonest to go off at the top, undated last.
            ->sortBy(fn ($r) => $r['expires_on'] ?? '9999-12-31')
            ->values();

        $board = [
            'lots' => $rows->values()->all(),
            'within' => $soon,
            'summary' => [
                'batches' => $rows->count(),
                // What the store is worth at what each part of it actually
                // cost — not at the standing price, which is what the old
                // single-price figure gave.
                'value' => round($rows->sum('value'), 2),
                'expired' => $rows->filter(fn ($r) => $r['days_left'] !== null && $r['days_left'] < 0)->count(),
                'expired_value' => round($rows->filter(fn ($r) => $r['days_left'] !== null && $r['days_left'] < 0)->sum('value'), 2),
                'expiring' => $rows->filter(fn ($r) => $r['days_left'] !== null && $r['days_left'] >= 0 && $r['days_left'] <= $soon)->count(),
                'expiring_value' => round($rows->filter(fn ($r) => $r['days_left'] !== null && $r['days_left'] >= 0 && $r['days_left'] <= $soon)->sum('value'), 2),
                'undated' => $rows->filter(fn ($r) => $r['days_left'] === null)->count(),
            ],
        ];

        // The counts stay whatever happens: "4 batches going off" is the whole
        // point of this board and is not a figure about money. Only what they
        // are worth comes off.
        return $this->ok($this->seesMoney($request)
            ? $board
            : MoneyVeil::hide($board, MoneyVeil::WITHDRAWAL));
    }

    /* ----------------------------------------------------------------------- */

    /** Defaults to the current month, which is what the screen opens on. */
    private function window(Request $request): array
    {
        $month = (string) $request->query('month', '');

        if (preg_match('/^\d{4}-\d{2}$/', $month)) {
            $start = Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfDay();

            return [$start, $start->copy()->endOfMonth()];
        }

        return [
            $request->filled('from') ? Carbon::parse($request->query('from')) : Carbon::today()->startOfMonth(),
            $request->filled('to') ? Carbon::parse($request->query('to')) : Carbon::today()->endOfMonth(),
        ];
    }

    private function present(Withdrawal $withdrawal): array
    {
        return [
            'id' => $withdrawal->id,
            'date' => $withdrawal->date?->toDateString(),
            'item_id' => $withdrawal->item_id,
            'code' => $withdrawal->item?->code,
            'name' => $withdrawal->item?->label(),
            'unit' => $withdrawal->item?->unit,
            'quantity' => (float) $withdrawal->quantity,
            'cost' => round((float) $withdrawal->cost, 2),
            'reason' => $withdrawal->reason,
            'reason_label' => Withdrawal::REASONS[$withdrawal->reason] ?? $withdrawal->reason,
            'note' => $withdrawal->note,
            'by' => $withdrawal->author?->name,
            'period_id' => $withdrawal->period_id,
        ];
    }

    /** 12.500 reads as 12.5, 12.000 as 12 — the way the crew write it. */
    private function trim($quantity): string
    {
        $value = round((float) $quantity, 3);

        return $value === floor($value) ? (string) (int) $value : rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }

    private function money(float $amount): string
    {
        return '$'.number_format($amount, 2);
    }

    private function refuse(string $message)
    {
        return response()->json(['success' => false, 'message' => $message], 422);
    }
}

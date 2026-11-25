<?php

namespace App\Console\Commands;

use App\Models\Inventory\Item;
use App\Models\Inventory\StockLot;
use App\Models\Inventory\Vessel;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Give the stock already on board a cost to be drawn against.
 *
 * Run once, after the stock-lot tables are migrated onto a live ship.
 *
 * Before lots, an item had one quantity and one price. Those quantities are
 * real — somebody counted them — but nothing records what any of it cost, so
 * the first withdrawal would have nothing to charge against. The ledger copes
 * with that on its own (it opens a lot for the shortfall as it goes), which
 * keeps the cook working but leaves the batch board empty on day one and the
 * costing arriving one withdrawal at a time.
 *
 * This does it up front instead: one opening lot per item that has stock, at
 * the item's standing price, dated today and marked as never booked in. The
 * board is then honest from the first screen the crew open.
 *
 * Safe to run twice: an item that already has a lot is left alone.
 */
class OpenProvisionStockLots extends Command
{
    protected $signature = 'provisions:open-lots
                            {--vessel= : Only this vessel id}
                            {--dry-run : Show what would be opened, write nothing}';

    protected $description = 'Open a stock lot for provision stock that was on board before lots existed';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $vesselId = $this->option('vessel');

        $vessels = Vessel::query()
            ->when($vesselId, fn ($q) => $q->whereKey($vesselId))
            ->orderBy('id')
            ->get();

        if ($vessels->isEmpty()) {
            $this->error('No vessels found.');

            return self::FAILURE;
        }

        $today = Carbon::today()->toDateString();
        $grandTotal = 0;
        $grandValue = 0.0;

        foreach ($vessels as $vessel) {
            // Items with stock and no lot of any kind. "Any kind" matters: an
            // item part-way through its first real delivery must not be given
            // a second, invented batch on top.
            $items = Item::where('vessel_id', $vessel->id)
                ->where('current_stock', '>', 0)
                ->whereNotExists(fn ($q) => $q->selectRaw(1)
                    ->from('provision_stock_lots')
                    ->whereColumn('provision_stock_lots.item_id', 'provision_items.id'))
                ->get(['id', 'vessel_id', 'name', 'unit', 'current_stock', 'unit_price']);

            if ($items->isEmpty()) {
                $this->line("  {$vessel->name}: nothing to open.");

                continue;
            }

            $value = $items->sum(fn (Item $i) => (float) $i->current_stock * (float) ($i->unit_price ?? 0));
            $noPrice = $items->filter(fn (Item $i) => $i->unit_price === null || (float) $i->unit_price <= 0)->count();

            $this->line(sprintf(
                '  %s: %d items, $%s%s',
                $vessel->name,
                $items->count(),
                number_format($value, 2),
                $noPrice ? " ({$noPrice} with no price — opened at zero)" : ''
            ));

            $grandTotal += $items->count();
            $grandValue += $value;

            if ($dry) {
                continue;
            }

            $rows = $items->map(fn (Item $item) => [
                'vessel_id' => $item->vessel_id,
                'item_id' => $item->id,
                'receipt_id' => null,
                'received_on' => $today,
                // Deliberately left open. A shelf life would date these from
                // today, and today is not when they arrived — a guessed expiry
                // is worse than none, because the crew would act on it.
                'expires_on' => null,
                'unit_cost' => round((float) ($item->unit_price ?? 0), 4),
                'quantity' => (float) $item->current_stock,
                'remaining' => (float) $item->current_stock,
                'source' => StockLot::FROM_OPENING,
                'note' => 'On board before batches were recorded',
                'created_at' => now(),
                'updated_at' => now(),
            ])->all();

            foreach (array_chunk($rows, 250) as $chunk) {
                DB::table('provision_stock_lots')->insert($chunk);
            }
        }

        $this->newLine();
        $this->info(sprintf(
            '%s %d opening %s worth $%s.',
            $dry ? 'Would open' : 'Opened',
            $grandTotal,
            $grandTotal === 1 ? 'batch' : 'batches',
            number_format($grandValue, 2)
        ));

        if ($dry) {
            $this->comment('Nothing was written. Run again without --dry-run.');
        }

        return self::SUCCESS;
    }
}

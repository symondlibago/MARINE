<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\DocumentCounter;
use App\Models\Vendor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Give a number to every customer and vendor that has not got one.
 *
 * The migration back-fills whatever exists when it runs, and new parties are
 * numbered by a model hook. Neither covers a BULK IMPORT: rows inserted straight
 * into the database — a restore, a spreadsheet import, a seed — bypass Eloquent
 * entirely, so no hook fires and those rows arrive unnumbered.
 *
 * This is the repair for that, and it is safe to run as often as you like:
 * it only ever touches rows where the number is NULL, continues from the
 * highest number already issued, and never renumbers anybody.
 *
 *     php artisan parties:number            # do it
 *     php artisan parties:number --dry-run  # just report what is missing
 */
class NumberParties extends Command
{
    protected $signature = 'parties:number
                            {--dry-run : Report what would be numbered, change nothing}';

    protected $description = 'Assign customer (10001+) and vendor (20001+) numbers to any party missing one';

    /** table => [column, first number, counter key, label] */
    private const SERIES = [
        Customer::class => ['customer_no', 10001, 'CUSTNO', 'customers'],
        Vendor::class => ['vendor_no', 20001, 'VENDNO', 'vendors'],
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        foreach (self::SERIES as $model => [$column, $start, $key, $label]) {
            $table = (new $model)->getTable();

            if (! Schema::hasColumn($table, $column)) {
                $this->error("  {$label}: column {$column} does not exist — run migrations first.");

                continue;
            }

            $missing = $model::whereNull($column)->count();
            $total = $model::count();

            if ($missing === 0) {
                $this->info(sprintf('  %-10s all %d numbered — nothing to do.', $label, $total));

                continue;
            }

            if ($dryRun) {
                $this->warn(sprintf('  %-10s %d of %d have no number (would start at %d).',
                    $label, $missing, $total, max((int) $model::max($column), $start - 1) + 1));

                continue;
            }

            $first = $this->assign($table, $column, $start);
            $last = (int) $model::max($column);

            DocumentCounter::updateOrCreate(['key' => $key], ['seq' => max($last, $start - 1)]);

            $this->info(sprintf('  %-10s numbered %d row(s): %d..%d  (counter now %d)',
                $label, $missing, $first, $last, $last));
        }

        return self::SUCCESS;
    }

    /**
     * Number the unnumbered rows in creation order, returning the first number
     * issued.
     *
     * On MySQL this is one ordered UPDATE walking a counter variable, so 7,500
     * rows cost a single statement rather than 7,500 round trips — which is the
     * difference between a second and several minutes against a hosted
     * database. Other drivers get the portable loop.
     */
    private function assign(string $table, string $column, int $start): int
    {
        $resume = max((int) DB::table($table)->max($column), $start - 1);

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('SET @party_no := ?', [$resume]);
            DB::update("UPDATE `{$table}` SET `{$column}` = (@party_no := @party_no + 1) WHERE `{$column}` IS NULL ORDER BY `id`");

            return $resume + 1;
        }

        $next = $resume + 1;
        DB::table($table)->select('id')->whereNull($column)->orderBy('id')
            ->chunkById(500, function ($rows) use ($table, $column, &$next) {
                foreach ($rows as $row) {
                    DB::table($table)->where('id', $row->id)->update([$column => $next++]);
                }
            });

        return $resume + 1;
    }
}

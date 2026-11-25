<?php

namespace App\Support\Inventory;

use App\Models\Inventory\Category;
use App\Models\Inventory\Vessel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The master provision list, and putting it on a ship.
 *
 * 879 items, from the owner's own workbook. The seeder used it to set up the
 * first vessel; this is the same job, for any vessel, so a ship added on a
 * Tuesday afternoon has a catalogue to count against before anyone leaves the
 * office.
 *
 * ON KEEPING A ROW PER VESSEL
 *
 * The obvious worry is the row count: one catalogue per ship, five ships,
 * fifty ships. The numbers are worth stating rather than guessing at. 879
 * items across 50 vessels is about 44,000 rows — a table MySQL would call
 * small, and every query in this module is already filtered by vessel_id with
 * an index to match, so a ship's catalogue is read in the same time whether
 * the fleet is one vessel or fifty.
 *
 * The alternative — one shared definition table plus a per-vessel stock table —
 * would save perhaps 40,000 rows of nothing and cost a join on the hot path of
 * ten screens. It buys one real thing: a correction to an item's name or unit
 * reaching the whole fleet at once. That is worth having, and it is worth
 * having as a deliberate "apply the master list to the fleet" action rather
 * than as a schema that makes every read more expensive. Which is this class.
 */
class ProvisionCatalogue
{
    private const CHUNK = 250;

    private const CATEGORY_ALIASES = [
        'Egg, Diary Products' => 'Egg, Dairy Products',
    ];

    /**
     * The workbook's write-in block, which is not a category of food.
     *
     * Its rows are blank lines for the cook to fill in by hand. Seeded, they
     * arrived as 43 entries all called "Additional Item", with no consumption
     * and no price, so they could never be ordered and simply filled a screen.
     * Anything the catalogue is genuinely missing is added through "Add item",
     * under a real category and with a real name.
     */
    private const SKIP_CATEGORIES = [
        'Additional items, not on the list',
        'Additional items, not on the list (use descrip. 2)',
    ];

    public static function path(): string
    {
        return database_path('seeders/data/provision_items.json');
    }

    public static function exists(): bool
    {
        return is_file(self::path());
    }

    /** How many items the master list holds, without building any of them. */
    public static function size(): int
    {
        return count(self::rows());
    }

    /**
     * Put the master list on a vessel.
     *
     * SAFE TO RUN ON LIVE DATA. This only ever INSERTs. An item already on her
     * is counted and skipped — never updated, never deleted — so a price the
     * office typed, a count the crew made or a note somebody left cannot be
     * undone by running this again. That is what makes it usable as "catch
     * this ship up with the master list" and not only as a one-off, and it is
     * the property to preserve if this method is ever changed: no UPDATE and
     * no DELETE belongs in here.
     *
     * @return array{added: int, present: int, skipped: int}
     */
    public static function stock(Vessel $vessel): array
    {
        $rows = self::rows();

        if (! $rows) {
            return ['added' => 0, 'present' => 0, 'skipped' => 0];
        }

        $categories = Category::pluck('id', 'name');
        $existing = DB::table('provision_items')
            ->where('vessel_id', $vessel->id)
            ->pluck('code')
            ->flip();

        $now = Carbon::now();
        $batch = [];
        $added = 0;
        $present = 0;
        $skipped = 0;
        $sort = 0;
        $seen = [];

        foreach ($rows as $row) {
            $code = trim((string) ($row['code'] ?? ''));
            $name = trim((string) ($row['item_name'] ?? ''));

            if ($code === '' || $name === '') {
                continue;
            }

            if (in_array(trim((string) ($row['category'] ?? '')), self::SKIP_CATEGORIES, true)) {
                $skipped++;

                continue;
            }

            // The workbook reuses one code across its spare "Additional items"
            // lines. Keep every line, but give the repeats a suffixed code so
            // the catalogue key stays unique and stable — and stays the SAME
            // code on every vessel, which is what lets two ships' figures be
            // compared item for item.
            if (isset($seen[$code])) {
                $seen[$code]++;
                $code = $code.'-'.$seen[$code];
            } else {
                $seen[$code] = 1;
            }

            if ($existing->has($code)) {
                $present++;

                continue;
            }

            $categoryName = trim((string) ($row['category'] ?? 'Miscellaneous')) ?: 'Miscellaneous';
            $categoryName = self::CATEGORY_ALIASES[$categoryName] ?? $categoryName;

            // A category the brief did not list still gets a home rather than
            // silently dropping its items.
            if (! isset($categories[$categoryName])) {
                $category = Category::create(['name' => $categoryName, 'sort' => 99]);
                $categories[$categoryName] = $category->id;
            }

            // A NAME AND NOTHING ELSE
            //
            // What comes off the workbook is what identifies an item — its
            // code, its name, its unit, the category it files under. Every
            // figure beside it is left empty, deliberately:
            //
            //   unit_price            the workbook's prices are from a quarter
            //                         nobody is buying in any more. A stale
            //                         price is worse than none, because an
            //                         order totals against it and a budget is
            //                         judged against that total. The office
            //                         fills these in from the first real
            //                         quotation, or the batch on the shelf
            //                         sets them — see the FIFO rule.
            //   current_stock         nothing is on board until somebody
            //                         counts it. The ROB column is a snapshot
            //                         of one day in the past, and seeding it
            //                         would have the system ordering against
            //                         stock no one has seen.
            //   baseline_consumption  last year's usage on another crew's
            //                         watch. Consumption is measured from the
            //                         first closed supply period, which is the
            //                         rule the whole module is built on:
            //                         measured, never guessed.
            //
            // shelf_life_days is the ONE figure that does come through, and
            // deliberately. "Bok Choy, Fresh — 10 days" is not somebody's old
            // data, it is a fact about bok choy. It is what lets a batch the
            // vendor never dated still carry an estimated expiry, marked EST,
            // so the cook is warned before it goes off. Stripping it would
            // leave the Expires column blank until somebody typed a date on
            // every delivery line by hand.
            //
            // The consequence, stated plainly because it looks like a fault on
            // day one: until the first supply period is closed, nothing is
            // raised automatically and the first requisition is typed by hand.
            // That is correct. The second one onwards is calculated from what
            // the ship actually ate.
            $batch[] = [
                'vessel_id' => $vessel->id,
                'category_id' => $categories[$categoryName],
                'code' => $code,
                'name' => $name,
                'variant' => ($v = trim((string) ($row['variant'] ?? ''))) !== '' ? $v : null,
                'unit' => ($u = trim((string) ($row['unit'] ?? ''))) !== '' ? $u : 'PCS',
                'unit_price' => null,
                'shelf_life_days' => isset($row['shelf_life_days']) && $row['shelf_life_days'] !== null
                    ? (int) $row['shelf_life_days']
                    : null,
                'current_stock' => 0,
                'baseline_consumption' => null,
                'baseline_period_days' => null,
                'manual_target' => null,
                'notes' => null,
                'is_active' => true,
                'sort' => $sort++,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($batch) >= self::CHUNK) {
                DB::table('provision_items')->insert($batch);
                $added += count($batch);
                $batch = [];
            }
        }

        if ($batch) {
            DB::table('provision_items')->insert($batch);
            $added += count($batch);
        }

        return ['added' => $added, 'present' => $present, 'skipped' => $skipped];
    }

    /** The master list, read once per request. */
    private static function rows(): array
    {
        static $rows = null;

        if ($rows !== null) {
            return $rows;
        }

        if (! self::exists()) {
            return $rows = [];
        }

        $decoded = json_decode(file_get_contents(self::path()), true);

        return $rows = is_array($decoded) ? $decoded : [];
    }

}

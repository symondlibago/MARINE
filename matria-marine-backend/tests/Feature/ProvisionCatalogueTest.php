<?php

namespace Tests\Feature;

use App\Models\Inventory\Category;
use App\Models\Inventory\Item;
use Database\Seeders\Inventory\InventorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What a fresh vessel starts with.
 *
 * The catalogue is seeded from the vessel's own workbook, and two things in
 * that workbook are not catalogue data: the stock column, which is a snapshot
 * of one day in the past, and the "Additional items" block, which is a run of
 * blank rows for writing in by hand. Seeding either put the system in a state
 * no one had counted or named.
 */
class ProvisionCatalogueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(InventorySeeder::class);
    }

    public function test_the_catalogue_is_seeded(): void
    {
        $this->assertGreaterThan(800, Item::count(), 'the workbook holds over 900 items');
        $this->assertGreaterThan(20, Category::count());
    }

    /** Nothing is on board until somebody counts it. */
    public function test_every_item_starts_with_no_stock(): void
    {
        $this->assertSame(
            0,
            Item::where('current_stock', '>', 0)->count(),
            'the workbook ROB column is being seeded as stock'
        );
    }

    /**
     * The write-in block does not come through.
     *
     * Those rows arrived as entries all named "Additional Item", most of them
     * with nothing else on them — unorderable, and a screenful to scroll past.
     */
    public function test_the_write_in_block_is_not_seeded(): void
    {
        $this->assertSame(
            0,
            Category::where('name', 'like', 'Additional items%')->count(),
            'the write-in block was seeded as a category'
        );

        $this->assertSame(
            0,
            Item::where('name', 'like', 'Additional Item%')->count(),
            'blank write-in rows were seeded as items'
        );
    }

    /**
     * A seeded item is a name and nothing else.
     *
     * No price, no stock, no usage history. The workbook's figures are from a
     * quarter nobody is buying in any more and a crew who have gone home, and
     * a stale price is worse than none — an order totals against it and a
     * budget is judged against that total.
     */
    public function test_a_seeded_item_carries_no_figures(): void
    {
        $this->assertSame(0, Item::whereNotNull('unit_price')->count(), 'the workbook prices are being seeded');
        $this->assertSame(0, Item::whereNotNull('baseline_consumption')->count(), 'last year\'s usage is being seeded');
        $this->assertSame(0, Item::whereNotNull('manual_target')->count());

        // What DOES come through: the things that identify the item.
        $item = Item::whereNotNull('code')->first();
        $this->assertNotEmpty($item->name);
        $this->assertNotEmpty($item->unit);
        $this->assertNotNull($item->category_id);
    }

    /**
     * Shelf life is the one figure that is kept, and it has to stay kept.
     *
     * It is not stale data like the prices and the usage history beside it —
     * "Bok Choy, Fresh, 10 days" is a fact about bok choy. It is what lets a
     * batch the vendor never dated still carry an estimated expiry, marked
     * EST, so the cook is warned before it goes off. Strip it with the rest
     * and the Expires column is blank until somebody types a date on every
     * delivery line by hand.
     */
    public function test_shelf_life_survives_the_fresh_seed(): void
    {
        $this->assertGreaterThan(
            50,
            Item::whereNotNull('shelf_life_days')->count(),
            'shelf life was stripped along with the stale figures — nothing can estimate an expiry now'
        );

        $bokChoy = Item::where('name', 'like', 'Bok Choy%')->first();

        $this->assertNotNull($bokChoy, 'the master list should hold Bok Choy');
        $this->assertSame(10, $bokChoy->shelf_life_days);
        $this->assertNull($bokChoy->unit_price, 'and still no price on it');
    }

    /**
     * Running it again adds nothing and changes nothing.
     *
     * This is the property that makes it safe to point at the live database:
     * it only ever inserts what is missing. A price the office typed or a
     * count the crew made must survive a second run untouched — otherwise
     * "catch this ship up with the master list" quietly undoes a week's work.
     */
    public function test_seeding_again_changes_nothing(): void
    {
        $before = Item::count();

        // Stand in for the work a live database would already hold.
        $item = Item::first();
        $item->update(['unit_price' => 12.50, 'current_stock' => 40, 'notes' => 'counted by the cook']);

        $this->seed(InventorySeeder::class);

        $this->assertSame($before, Item::count(), 'a second run duplicated the catalogue');

        $after = $item->fresh();
        $this->assertEquals(12.50, $after->unit_price, 'a price the office typed was wiped by re-seeding');
        $this->assertEquals(40, $after->current_stock, 'a count the crew made was wiped by re-seeding');
        $this->assertSame('counted by the cook', $after->notes);
    }
}

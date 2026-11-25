<?php

namespace Tests\Feature;

use App\Models\Inventory\Category;
use App\Models\Inventory\Item;
use App\Models\Inventory\Requisition;
use App\Models\Inventory\Vessel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\NamesItsVessel;
use Tests\TestCase;

/**
 * The requisition: what the calculator asks for, and what the office orders.
 *
 * Those are not the same thing. The arithmetic knows what fell below target;
 * it does not know the reefer is full, that a supplier does not stock the item,
 * or that nobody has priced it yet. So the screen sends the lines it is showing
 * and the server honours them â€” while keeping the calculated figure recoverable
 * on every saved line, so an order placed against the advice can be told apart
 * from one that followed it.
 */
class RequisitionTest extends TestCase
{
    use NamesItsVessel, RefreshDatabase;

    private Vessel $vessel;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('admin', 'web');
        $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $user->assignRole('admin');
        $this->grantAllPages($user);
        Sanctum::actingAs($user);

        $this->vessel = Vessel::current();
        $this->vessel->update(['crew_size' => 20, 'supply_period_days' => 30, 'safety_margin_pct' => 0]);
    }

    /** An item used at a known rate, so the calculator has something to ask for. */
    private function item(string $name, float $stock, ?float $price, float $perMonth = 30): Item
    {
        static $code = 1001000;
        $category = Category::firstOrCreate(['name' => 'Vegetables fresh'], ['sort' => 1]);

        return Item::create([
            'vessel_id' => $this->vessel->id,
            'category_id' => $category->id,
            'code' => (string) ++$code,
            'name' => $name,
            'unit' => 'Kg',
            'unit_price' => $price,
            'current_stock' => $stock,
            'baseline_consumption' => $perMonth,
            'baseline_period_days' => 30,
            'is_active' => true,
        ]);
    }

    public function test_the_preview_asks_for_what_is_below_target(): void
    {
        $this->item('Asparagus, Fresh', 4, 2.00);     // target 30, order 26
        $this->item('Carrots, Fresh', 100, 2.00);     // already above target

        $data = $this->getJson($this->url('/requisition/preview'))->assertOk()->json('data');

        $this->assertCount(1, $data['lines']);
        $this->assertSame('Asparagus, Fresh', $data['lines'][0]['name']);
        $this->assertSame(26.0, (float) $data['lines'][0]['order_qty']);
    }

    /** Only the ticked lines are ordered. */
    public function test_an_unticked_line_is_left_off_the_order(): void
    {
        $keep = $this->item('Asparagus, Fresh', 4, 2.00);
        $this->item('Beet Root, Fresh', 4, 1.90);

        $this->postJson($this->url('/requisitions'), [
            'lines' => [['item_id' => $keep->id, 'order_qty' => 26]],
        ])->assertCreated();

        $req = Requisition::with('items')->firstOrFail();

        $this->assertCount(1, $req->items);
        $this->assertSame($keep->id, $req->items[0]->item_id);
    }

    /** A typed quantity is the one ordered, and the one priced. */
    public function test_the_typed_quantity_is_what_is_ordered(): void
    {
        $item = $this->item('Asparagus, Fresh', 4, 2.00);   // calculator wants 26

        $this->postJson($this->url('/requisitions'), [
            'lines' => [['item_id' => $item->id, 'order_qty' => 10]],
        ])->assertCreated();

        $line = Requisition::with('items')->firstOrFail()->items[0];

        $this->assertSame(10.0, (float) $line->order_qty);
        $this->assertSame(20.0, (float) $line->line_total, 'the value should follow the quantity ordered');
    }

    /**
     * What the calculator asked for stays readable on the saved line.
     *
     * Target and ROB are both recorded, so the advised quantity is always
     * recoverable: an order of 10 against an advised 26 can be seen for what
     * it is months later.
     */
    public function test_a_saved_line_still_shows_what_was_advised(): void
    {
        $item = $this->item('Asparagus, Fresh', 4, 2.00);

        $this->postJson($this->url('/requisitions'), [
            'lines' => [['item_id' => $item->id, 'order_qty' => 10]],
        ])->assertCreated();

        $line = Requisition::with('items')->firstOrFail()->items[0];

        $advised = max(0, ceil((float) $line->target_qty - (float) $line->rob_at_issue));

        $this->assertSame(4.0, (float) $line->rob_at_issue);
        $this->assertSame(30.0, (float) $line->target_qty);
        $this->assertSame(26.0, $advised);
        $this->assertSame(10.0, (float) $line->order_qty);
    }

    /** Sending no lines at all keeps the old behaviour: order everything. */
    public function test_without_a_selection_the_whole_calculated_list_is_saved(): void
    {
        $this->item('Asparagus, Fresh', 4, 2.00);
        $this->item('Beet Root, Fresh', 4, 1.90);

        $this->postJson($this->url('/requisitions'), [])->assertCreated();

        $this->assertCount(2, Requisition::with('items')->firstOrFail()->items);
    }

    /** Ticking nothing is a mistake worth saying out loud. */
    public function test_an_empty_selection_is_refused(): void
    {
        $this->item('Asparagus, Fresh', 4, 2.00);

        $this->postJson($this->url('/requisitions'), ['lines' => []])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Nothing selected to order.');

        $this->assertSame(0, Requisition::count());
    }

    /** An item somebody wants anyway can go on, even if it is above target. */
    public function test_an_item_the_calculator_did_not_raise_can_still_be_ordered(): void
    {
        $this->item('Asparagus, Fresh', 4, 2.00);
        $extra = $this->item('Carrots, Fresh', 100, 3.00);   // above target

        $this->postJson($this->url('/requisitions'), [
            'lines' => [['item_id' => $extra->id, 'order_qty' => 5]],
        ])->assertCreated();

        $line = Requisition::with('items')->firstOrFail()->items[0];

        $this->assertSame($extra->id, $line->item_id);
        $this->assertSame(5.0, (float) $line->order_qty);
        $this->assertSame(15.0, (float) $line->line_total);
    }

    /** One line per item, whatever the screen sends. */
    public function test_the_same_item_twice_is_one_line(): void
    {
        $item = $this->item('Asparagus, Fresh', 4, 2.00);

        $this->postJson($this->url('/requisitions'), [
            'lines' => [
                ['item_id' => $item->id, 'order_qty' => 10],
                ['item_id' => $item->id, 'order_qty' => 7],
            ],
        ])->assertCreated();

        $items = Requisition::with('items')->firstOrFail()->items;

        $this->assertCount(1, $items);
        $this->assertSame(10.0, (float) $items[0]->order_qty, 'the first quantity wins');
    }

    /** Another vessel's catalogue is not orderable from this one. */
    public function test_an_item_from_elsewhere_is_ignored(): void
    {
        $mine = $this->item('Asparagus, Fresh', 4, 2.00);

        $other = Vessel::create(['name' => 'MV Other', 'crew_size' => 10, 'supply_period_days' => 30]);
        $theirs = Item::create([
            'vessel_id' => $other->id,
            'category_id' => Category::firstOrFail()->id,
            'code' => '9999999', 'name' => 'Not ours', 'unit' => 'Kg',
            'current_stock' => 0, 'is_active' => true,
        ]);

        $this->postJson($this->url('/requisitions'), [
            'lines' => [
                ['item_id' => $mine->id, 'order_qty' => 2],
                ['item_id' => $theirs->id, 'order_qty' => 99],
            ],
        ])->assertCreated();

        $items = Requisition::with('items')->firstOrFail()->items;

        $this->assertCount(1, $items);
        $this->assertSame($mine->id, $items[0]->item_id);
    }

    public function test_quantities_are_validated(): void
    {
        $item = $this->item('Asparagus, Fresh', 4, 2.00);

        $this->postJson($this->url('/requisitions'), [
            'lines' => [['item_id' => $item->id, 'order_qty' => -5]],
        ])->assertStatus(422)->assertJsonValidationErrors('lines.0.order_qty');

        $this->postJson($this->url('/requisitions'), [
            'lines' => [['order_qty' => 5]],
        ])->assertStatus(422)->assertJsonValidationErrors('lines.0.item_id');
    }

    /** The total is the sum of the lines actually saved. */
    public function test_the_order_total_follows_the_selection(): void
    {
        $a = $this->item('Asparagus, Fresh', 4, 2.00);
        $b = $this->item('Beet Root, Fresh', 4, 10.00);

        $this->postJson($this->url('/requisitions'), [
            'lines' => [
                ['item_id' => $a->id, 'order_qty' => 3],    // 6.00
                ['item_id' => $b->id, 'order_qty' => 2],    // 20.00
            ],
        ])->assertCreated();

        $this->assertSame(26.0, (float) Requisition::firstOrFail()->total_value);
    }
}

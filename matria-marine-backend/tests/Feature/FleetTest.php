<?php

namespace Tests\Feature;

use App\Models\Inventory\Category;
use App\Models\Inventory\Item;
use App\Models\Inventory\Vessel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A fleet, rather than a vessel.
 *
 * The module was built for one ship and resolved it by taking the only one
 * there was. With five ships that fallback would serve the wrong one's stock
 * without saying so — a cook counting against another vessel's figures — so
 * every request now names the vessel it means, and a request that does not is
 * refused rather than guessed at.
 */
class FleetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('admin', 'web');
        $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $user->assignRole('admin');
        $this->grantAllPages($user);
        Sanctum::actingAs($user);
    }

    private function vessel(string $name, array $attributes = []): Vessel
    {
        return Vessel::create($attributes + [
            'name' => $name,
            'crew_size' => 20,
            'supply_period_days' => 30,
            'safety_margin_pct' => 0,
            'currency' => 'USD',
            'exchange_rate' => 1,
            'is_active' => true,
        ]);
    }

    private function item(Vessel $vessel, string $name, float $stock, float $perMonth = 30): Item
    {
        static $code = 2001000;

        return Item::create([
            'vessel_id' => $vessel->id,
            'category_id' => Category::firstOrCreate(['name' => 'Vegetables fresh'], ['sort' => 1])->id,
            'code' => (string) ++$code,
            'name' => $name,
            'unit' => 'Kg',
            'unit_price' => 2.00,
            'current_stock' => $stock,
            'baseline_consumption' => $perMonth,
            'baseline_period_days' => 30,
            'is_active' => true,
        ]);
    }

    /* --------------------------- naming a vessel --------------------------- */

    /**
     * A request without a vessel is refused.
     *
     * This is the whole point of the change: silently picking one is how a
     * second ship's stock gets counted against the first's.
     */
    public function test_a_request_that_names_no_vessel_is_refused(): void
    {
        $this->vessel('OEL VARUN');

        $this->getJson('/api/inventory/items')->assertStatus(422);
        $this->getJson('/api/inventory/dashboard')->assertStatus(422);
        $this->getJson('/api/inventory/requisition/preview')->assertStatus(422);
    }

    public function test_a_vessel_that_does_not_exist_is_a_404(): void
    {
        $this->getJson('/api/inventory/dashboard?vessel_id=9999')->assertNotFound();
    }

    /** Two ships, two sets of stock, no leakage between them. */
    public function test_each_vessel_sees_only_its_own_stock(): void
    {
        $varun = $this->vessel('OEL VARUN');
        $other = $this->vessel('MV SOUTHERN CROSS');

        $this->item($varun, 'Asparagus, Fresh', 4);
        $this->item($other, 'Carrots, Fresh', 4);
        $this->item($other, 'Beet Root, Fresh', 4);

        // The catalogue comes back grouped by category; what matters here is
        // how many items each vessel can see at all.
        $names = fn (Vessel $v) => collect(
            $this->getJson("/api/inventory/items?vessel_id={$v->id}")->assertOk()->json('data.groups')
        )->pluck('items')->flatten(1)->pluck('name')->sort()->values()->all();

        $this->assertSame(['Asparagus, Fresh'], $names($varun));
        $this->assertSame(['Beet Root, Fresh', 'Carrots, Fresh'], $names($other));
    }

    /** An archived vessel is a record, not a working ship. */
    public function test_an_archived_vessel_cannot_be_worked_on(): void
    {
        $vessel = $this->vessel('MV LAID UP', ['is_active' => false]);

        $this->getJson("/api/inventory/dashboard?vessel_id={$vessel->id}")
            ->assertStatus(422)
            ->assertSee('archived', false);
    }

    /* ------------------------------ the fleet ------------------------------ */

    public function test_the_fleet_lists_every_active_vessel_with_its_figures(): void
    {
        $varun = $this->vessel('OEL VARUN');
        $cross = $this->vessel('MV SOUTHERN CROSS');

        $this->item($varun, 'Asparagus, Fresh', 4);     // target 30, so 26 to order
        $this->item($cross, 'Carrots, Fresh', 100);     // above target

        $vessels = collect($this->getJson('/api/inventory/vessels')->assertOk()->json('data.vessels'))
            ->keyBy('name');

        $this->assertCount(2, $vessels);
        $this->assertSame(1, $vessels['OEL VARUN']['summary']['items_to_order']);
        $this->assertSame(0, $vessels['MV SOUTHERN CROSS']['summary']['items_to_order']);
    }

    /**
     * The card and the vessel's own dashboard agree.
     *
     * They come from one calculator on purpose: two summaries of the same
     * stock that disagree is worse than having only one of them.
     */
    public function test_a_vessels_card_matches_its_dashboard(): void
    {
        $vessel = $this->vessel('OEL VARUN');
        $this->item($vessel, 'Asparagus, Fresh', 4);
        $this->item($vessel, 'Beet Root, Fresh', 2);

        $card = collect($this->getJson('/api/inventory/vessels')->json('data.vessels'))
            ->firstWhere('name', 'OEL VARUN')['summary'];

        $dashboard = $this->getJson("/api/inventory/dashboard?vessel_id={$vessel->id}")->json('data.summary');

        $this->assertSame($dashboard['items_to_order'], $card['items_to_order']);
        $this->assertSame($dashboard['order_value'], $card['order_value']);
        $this->assertSame($dashboard['order_now'], $card['order_now']);
    }

    /** Archived vessels are off the list unless asked for. */
    public function test_archived_vessels_are_hidden_by_default(): void
    {
        $this->vessel('OEL VARUN');
        $this->vessel('MV LAID UP', ['is_active' => false]);

        $this->assertCount(1, $this->getJson('/api/inventory/vessels')->json('data.vessels'));
        $this->assertCount(2, $this->getJson('/api/inventory/vessels?archived=1')->json('data.vessels'));
    }

    /* ------------------------- adding and removing ------------------------- */

    public function test_a_vessel_can_be_added_with_just_a_name(): void
    {
        $this->postJson('/api/inventory/vessels', ['name' => 'MV NEW ARRIVAL', 'stock_catalogue' => false])
            ->assertCreated()
            ->assertJsonPath('data.vessel.name', 'MV NEW ARRIVAL');

        $this->assertTrue(Vessel::where('name', 'MV NEW ARRIVAL')->exists());
    }

    /**
     * A new ship arrives with something to count against.
     *
     * An empty catalogue is a vessel nobody can work on, which is what the
     * first version of the fleet page shipped: the card read all zeros and the
     * cook had nothing to open.
     */
    public function test_a_new_vessel_arrives_with_the_master_provision_list(): void
    {
        if (! \App\Support\Inventory\ProvisionCatalogue::exists()) {
            $this->markTestSkipped('The master provision list is not on this machine.');
        }

        $response = $this->postJson('/api/inventory/vessels', ['name' => 'MV NEW ARRIVAL'])
            ->assertCreated();

        $vessel = Vessel::where('name', 'MV NEW ARRIVAL')->first();
        $items = Item::where('vessel_id', $vessel->id);

        $this->assertGreaterThan(500, $items->count(), 'the whole list, not a sample');
        $this->assertSame($items->count(), $response->json('data.items_added'));
        // Nothing is on board until her crew count it.
        $this->assertSame(0, (int) $items->sum('current_stock'));
    }

    /** The same list, for a ship that joined before it was put on by default. */
    public function test_a_vessel_can_be_caught_up_with_the_master_list(): void
    {
        if (! \App\Support\Inventory\ProvisionCatalogue::exists()) {
            $this->markTestSkipped('The master provision list is not on this machine.');
        }

        $vessel = $this->vessel('MV EMPTY');
        $this->assertSame(0, Item::where('vessel_id', $vessel->id)->count());

        $this->postJson("/api/inventory/vessels/{$vessel->id}/catalogue")->assertOk();

        $count = Item::where('vessel_id', $vessel->id)->count();
        $this->assertGreaterThan(500, $count);

        // Run again: nothing is duplicated and nothing already set is touched.
        Item::where('vessel_id', $vessel->id)->limit(1)->update(['unit_price' => 99.99, 'current_stock' => 7]);

        $this->postJson("/api/inventory/vessels/{$vessel->id}/catalogue")
            ->assertOk()
            ->assertSee('already has every item', false);

        $this->assertSame($count, Item::where('vessel_id', $vessel->id)->count());
        $this->assertSame(1, Item::where('vessel_id', $vessel->id)->where('unit_price', 99.99)->count());
    }

    /** The IMO number is how the owner identifies the ship. */
    public function test_two_vessels_cannot_share_an_imo_number(): void
    {
        $this->vessel('OEL VARUN', ['imo' => '9414759']);

        $this->postJson('/api/inventory/vessels', ['name' => 'MV COPY', 'imo' => '9414759'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('imo');
    }

    /** Vessels without one are not all duplicates of each other. */
    public function test_several_vessels_may_have_no_imo_number(): void
    {
        $this->postJson('/api/inventory/vessels', ['name' => 'MV ONE'])->assertCreated();
        $this->postJson('/api/inventory/vessels', ['name' => 'MV TWO'])->assertCreated();

        $this->assertSame(2, Vessel::whereNull('imo')->count());
    }

    /** A vessel added by mistake goes away entirely. */
    public function test_a_vessel_with_no_history_is_deleted(): void
    {
        $vessel = $this->vessel('MV MISTAKE');
        $this->item($vessel, 'Asparagus, Fresh', 4);

        $this->deleteJson("/api/inventory/vessels/{$vessel->id}")->assertOk();

        $this->assertNull(Vessel::find($vessel->id));
        $this->assertSame(0, Item::where('vessel_id', $vessel->id)->count());
    }

    /**
     * A vessel that has traded is archived instead.
     *
     * Deleting it would cascade through a year of requisitions, deliveries and
     * stocktakes — the fleet's record of what it actually spent.
     */
    public function test_a_vessel_with_history_is_archived_not_deleted(): void
    {
        $vessel = $this->vessel('OEL VARUN');
        $this->item($vessel, 'Asparagus, Fresh', 4);
        $this->postJson("/api/inventory/periods?vessel_id={$vessel->id}", [])->assertCreated();

        $this->deleteJson("/api/inventory/vessels/{$vessel->id}")
            ->assertOk()
            ->assertSee('archived', false);

        $this->assertNotNull(Vessel::find($vessel->id));
        $this->assertFalse((bool) Vessel::find($vessel->id)->is_active);
    }

    public function test_an_archived_vessel_can_be_restored(): void
    {
        $vessel = $this->vessel('MV LAID UP', ['is_active' => false]);

        $this->postJson("/api/inventory/vessels/{$vessel->id}/restore")->assertOk();

        $this->assertTrue((bool) Vessel::find($vessel->id)->is_active);
        $this->getJson("/api/inventory/dashboard?vessel_id={$vessel->id}")->assertOk();
    }

    public function test_settings_are_saved_per_vessel(): void
    {
        $a = $this->vessel('OEL VARUN');
        $b = $this->vessel('MV SOUTHERN CROSS');

        $this->putJson("/api/inventory/vessels/{$a->id}", [
            'name' => 'OEL VARUN', 'crew_size' => 24, 'ration_rate' => 12.00,
        ])->assertOk();

        $this->assertSame(24, $a->fresh()->crew_size);
        $this->assertSame(20, $b->fresh()->crew_size, "one vessel's settings should not reach another");
    }
}

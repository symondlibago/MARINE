<?php

namespace Tests\Feature;

use App\Models\Inventory\Category;
use App\Models\Inventory\Item;
use App\Models\Inventory\Receipt;
use App\Models\Inventory\Supplier;
use App\Models\Inventory\Vessel;
use App\Models\User;
use App\Support\Inventory\VesselAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Accounts that sail, and accounts that sit ashore.
 *
 * Until now one staff login opened everything. With a fleet there are people
 * on each ship: the cook, who does the counting, the withdrawing, the
 * receiving and the asking, and the master, who approves it and says how his
 * vessel is provisioned. Neither of them buys anything, neither of them sees
 * another ship, and neither of them sets his own budget.
 *
 * Two things are being proved here. The ordinary one: each rank can do its own
 * job. The one that matters: a crew account cannot reach past its own ship, by
 * any route — naming another vessel, naming none, or putting another vessel's
 * id in the path.
 */
class VesselAccountTest extends TestCase
{
    use RefreshDatabase;

    private Vessel $varun;
    private Vessel $cross;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['super_admin', 'admin', VesselAccess::MASTER, VesselAccess::COOK] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->varun = $this->vessel('OEL VARUN');
        $this->cross = $this->vessel('MV SOUTHERN CROSS');
    }

    /* ------------------------------- fixtures ------------------------------ */

    private function vessel(string $name, array $attributes = []): Vessel
    {
        return Vessel::create($attributes + [
            'name' => $name,
            'crew_size' => 20,
            'supply_period_days' => 30,
            'safety_margin_pct' => 0,
            'budget' => 5000,
            'ration_rate' => 9.30,
            'currency' => 'USD',
            'exchange_rate' => 1,
            'is_active' => true,
        ]);
    }

    private function item(Vessel $vessel, string $name, float $stock = 4): Item
    {
        static $code = 3001000;

        return Item::create([
            'vessel_id' => $vessel->id,
            'category_id' => Category::firstOrCreate(['name' => 'Vegetables fresh'], ['sort' => 1])->id,
            'code' => (string) ++$code,
            'name' => $name,
            'unit' => 'Kg',
            'unit_price' => 2.00,
            'current_stock' => $stock,
            'baseline_consumption' => 30,
            'baseline_period_days' => 30,
            'is_active' => true,
        ]);
    }

    private function account(string $role, ?Vessel $vessel): User
    {
        static $n = 0;
        $n++;

        $user = User::factory()->create([
            'name' => VesselAccess::RANKS[$role] ?? ucfirst($role),
            'username' => "acct{$n}",
            'role' => $role,
            'vessel_id' => $vessel?->id,
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function office(): User
    {
        $user = $this->account('admin', null);
        $this->grantAllPages($user);

        return $user;
    }

    private function signedIn(User $user): User
    {
        Sanctum::actingAs($user);

        return $user;
    }

    /* --------------------------- the cook's work --------------------------- */

    /**
     * A cook does not have to say which ship he is on.
     *
     * The module refuses a request that names no vessel, because with a fleet
     * guessing means counting against the wrong ship. For a crew account there
     * is nothing to guess: the ship is a property of the account, so the gate
     * fills it in.
     */
    public function test_a_cook_works_on_his_own_ship_without_naming_it(): void
    {
        $this->item($this->varun, 'Asparagus, Fresh');
        $this->signedIn($this->account(VesselAccess::COOK, $this->varun));

        $groups = $this->getJson('/api/inventory/items')->assertOk()->json('data.groups');

        $names = collect($groups)->pluck('items')->flatten(1)->pluck('name')->all();
        $this->assertSame(['Asparagus, Fresh'], $names);
    }

    public function test_a_cook_records_counts_receiving_and_requisitions(): void
    {
        $item = $this->item($this->varun, 'Beet Root, Fresh');
        $this->signedIn($this->account(VesselAccess::COOK, $this->varun));

        $this->patchJson("/api/inventory/items/{$item->id}/stock", ['current_stock' => 12])->assertOk();
        $this->assertSame(12.0, (float) $item->fresh()->current_stock);

        $this->postJson('/api/inventory/periods', [])->assertCreated();

        $this->postJson('/api/inventory/requisitions', [
            'lines' => [['item_id' => $item->id, 'order_qty' => 5]],
        ])->assertCreated();
    }

    /* -------------------------- past her own rail -------------------------- */

    /** Naming another ship is refused, not quietly served from his own. */
    public function test_a_cook_cannot_name_another_vessel(): void
    {
        $this->item($this->cross, 'Carrots, Fresh');
        $this->signedIn($this->account(VesselAccess::COOK, $this->varun));

        $this->getJson("/api/inventory/items?vessel_id={$this->cross->id}")
            ->assertForbidden()
            ->assertSee('OEL VARUN', false);
    }

    /**
     * The same refusal through the path rather than the query.
     *
     * Settings are bound from the URL, so they never pass the gate's vessel_id
     * check — this is the hole that check leaves, and the controller closes it.
     */
    public function test_a_master_cannot_save_another_vessels_settings(): void
    {
        $this->signedIn($this->account(VesselAccess::MASTER, $this->varun));

        $this->putJson("/api/inventory/vessels/{$this->cross->id}", ['crew_size' => 99])
            ->assertForbidden();

        $this->assertSame(20, $this->cross->fresh()->crew_size);
    }

    /** An account nobody has posted to a ship cannot work on any of them. */
    public function test_an_account_with_no_vessel_is_told_so(): void
    {
        $this->signedIn($this->account(VesselAccess::COOK, null));

        $this->getJson('/api/inventory/items')
            ->assertForbidden()
            ->assertSee('not assigned to a vessel', false);
    }

    /* ------------------------- the office's own work ------------------------ */

    public function test_the_crew_cannot_see_the_fleet_or_change_the_price_book(): void
    {
        $this->signedIn($this->account(VesselAccess::MASTER, $this->varun));

        $this->getJson('/api/inventory/vessels')->assertForbidden();
        $this->postJson('/api/inventory/vessels', ['name' => 'MV MINE'])->assertForbidden();
        $this->deleteJson("/api/inventory/vessels/{$this->varun->id}")->assertForbidden();
        $this->getJson('/api/inventory/suppliers/comparison')->assertForbidden();
        $this->postJson('/api/inventory/suppliers', ['name' => 'My Chandler'])->assertForbidden();

        $this->assertFalse(Vessel::where('name', 'MV MINE')->exists());
    }

    /**
     * ...including reading it. Who the office buys from is the whole vendor
     * list, and a ship has no use for it: she records what arrived and the
     * reference on the delivery note, and the office records from whom.
     */
    public function test_the_crew_cannot_read_the_supplier_list_at_all(): void
    {
        $this->signedIn($this->account(VesselAccess::COOK, $this->varun));

        $this->getJson('/api/inventory/suppliers')->assertForbidden();
    }

    /**
     * And a crew account saving a delivery cannot smuggle one in, nor wipe the
     * name the office already put on it.
     */
    public function test_a_crew_delivery_leaves_the_office_s_supplier_alone(): void
    {
        $supplier = Supplier::create(['vessel_id' => $this->varun->id, 'name' => 'Jurong Chandlers']);
        $item = $this->item($this->varun, 'Onions, Fresh');

        $this->signedIn($this->account(VesselAccess::COOK, $this->varun));

        $created = $this->postJson('/api/inventory/receipts', [
            'vessel_id' => $this->varun->id,
            'reference' => 'DO-1',
            'supplier_id' => $supplier->id,
            'items' => [['item_id' => $item->id, 'received_qty' => 4]],
        ])->assertCreated();

        $receipt = Receipt::findOrFail($created->json('data.receipt.id'));
        $this->assertNull($receipt->supplier_id, 'A cook named a supplier and the API took it.');

        // The office fills it in, the cook then corrects a quantity.
        $receipt->update(['supplier_id' => $supplier->id]);

        $this->putJson("/api/inventory/receipts/{$receipt->id}", [
            'vessel_id' => $this->varun->id,
            'reference' => 'DO-1',
            'items' => [['item_id' => $item->id, 'received_qty' => 6]],
        ])->assertOk();

        $this->assertSame(
            $supplier->id,
            $receipt->fresh()->supplier_id,
            'The cook editing the quantities blanked the chandler.'
        );
    }

    /** Procurement is a different operation, and not theirs. */
    public function test_a_crew_account_cannot_reach_procurement(): void
    {
        $this->signedIn($this->account(VesselAccess::COOK, $this->varun));

        $this->getJson('/api/portal/rfqs')->assertForbidden();
        $this->getJson('/api/user')->assertOk()->assertJsonPath('data.pages', []);
    }

    /* --------------------------- master vs cook ---------------------------- */

    public function test_only_the_master_changes_how_she_is_provisioned(): void
    {
        $this->signedIn($this->account(VesselAccess::COOK, $this->varun));
        $this->putJson("/api/inventory/vessels/{$this->varun->id}", ['crew_size' => 24])
            ->assertForbidden()
            ->assertSee('Only the master', false);

        $this->signedIn($this->account(VesselAccess::MASTER, $this->varun));
        $this->putJson("/api/inventory/vessels/{$this->varun->id}", [
            'crew_size' => 24,
            'supply_period_days' => 45,
            'safety_margin_pct' => 15,
        ])->assertOk();

        $saved = $this->varun->fresh();
        $this->assertSame(24, $saved->crew_size);
        $this->assertSame(45, $saved->supply_period_days);
    }

    /**
     * A master does not set his own budget, or what the owner is billed.
     *
     * Both sit on the same settings screen as the crew size, and both are
     * agreed ashore. His own screen does not draw them.
     */
    public function test_a_master_cannot_set_the_budget_or_the_billing_rates(): void
    {
        $this->signedIn($this->account(VesselAccess::MASTER, $this->varun));

        foreach ([['budget' => 99999], ['ration_rate' => 50], ['name' => 'MV RENAMED']] as $payload) {
            $this->putJson("/api/inventory/vessels/{$this->varun->id}", $payload)
                ->assertForbidden()
                ->assertSee('set by the office', false);
        }

        $saved = $this->varun->fresh();
        $this->assertSame('5000.00', $saved->budget);
        $this->assertSame('9.30', $saved->ration_rate);
        $this->assertSame('OEL VARUN', $saved->name);
    }

    /** The office still sets all of it. */
    public function test_the_office_sets_the_budget_and_the_rates(): void
    {
        $this->signedIn($this->office());

        $this->putJson("/api/inventory/vessels/{$this->varun->id}", [
            'name' => 'OEL VARUN',
            'budget' => 7500,
            'ration_rate' => 10.50,
        ])->assertOk();

        $this->assertSame('7500.00', $this->varun->fresh()->budget);
    }

    /** What the screens draw comes from the same place the gate refuses by. */
    public function test_the_settings_payload_says_what_the_account_may_do(): void
    {
        $this->signedIn($this->account(VesselAccess::COOK, $this->varun));
        $this->getJson('/api/inventory/settings')->assertOk()
            ->assertJsonPath('data.access.scope', 'vessel')
            ->assertJsonPath('data.access.rank', 'Cook')
            ->assertJsonPath('data.access.master', false)
            ->assertJsonPath('data.access.office', false);

        $this->signedIn($this->account(VesselAccess::MASTER, $this->varun));
        $this->getJson('/api/inventory/settings')->assertOk()
            ->assertJsonPath('data.access.master', true)
            ->assertJsonPath('data.access.office', false);

        $this->signedIn($this->office());
        $this->getJson("/api/inventory/settings?vessel_id={$this->varun->id}")->assertOk()
            ->assertJsonPath('data.access.scope', 'office')
            ->assertJsonPath('data.access.office', true);
    }

    /* ---------------------------- the accounts ----------------------------- */

    public function test_the_office_adds_a_master_and_a_cook_to_a_vessel(): void
    {
        $this->signedIn($this->office());

        foreach ([VesselAccess::MASTER => 'varun-master', VesselAccess::COOK => 'varun-cook'] as $role => $username) {
            $this->postJson("/api/inventory/vessels/{$this->varun->id}/accounts", [
                'name' => 'Crew '.$username,
                'email' => 'varun@matriamarine.com',
                'username' => $username,
                'password' => 'provisions8',
                'role' => $role,
            ])->assertCreated();
        }

        $accounts = $this->getJson("/api/inventory/vessels/{$this->varun->id}/accounts")
            ->assertOk()->json('data.accounts');

        // The master is listed first: it is his ship.
        $this->assertSame(['Master', 'Cook'], array_column($accounts, 'rank'));
        $this->assertSame($this->varun->id, User::where('username', 'varun-cook')->first()->vessel_id);
    }

    /**
     * A new account can sign in, and lands on its own ship.
     *
     * The whole point of the rank: the client has to know where to send them,
     * and procurement is not it.
     */
    public function test_a_new_crew_account_signs_in_and_is_pointed_at_its_ship(): void
    {
        $this->signedIn($this->office());
        $this->postJson("/api/inventory/vessels/{$this->varun->id}/accounts", [
            'name' => 'Ship Cook',
            'email' => 'varun@matriamarine.com',
            'username' => 'varun-cook',
            'password' => 'provisions8',
            'role' => VesselAccess::COOK,
        ])->assertCreated();

        $this->postJson('/api/login', ['login' => 'varun-cook', 'password' => 'provisions8'])
            ->assertOk()
            ->assertJsonPath('user.scope', 'vessel')
            ->assertJsonPath('user.rank', 'Cook')
            ->assertJsonPath('user.vessel.name', 'OEL VARUN');
    }

    public function test_the_crew_cannot_manage_accounts(): void
    {
        $crew = $this->account(VesselAccess::MASTER, $this->varun);
        $this->signedIn($crew);

        $this->getJson("/api/inventory/vessels/{$this->varun->id}/accounts")->assertForbidden();
        $this->postJson("/api/inventory/vessels/{$this->varun->id}/accounts", [
            'name' => 'Second Master', 'email' => 'x@y.com', 'username' => 'spare',
            'password' => 'provisions8', 'role' => VesselAccess::MASTER,
        ])->assertForbidden();

        $this->assertFalse(User::where('username', 'spare')->exists());
    }

    /**
     * The endpoint reaches this ship's crew and nothing else.
     *
     * Without that check an admin could edit or delete any account in the
     * system — a super admin's included — by putting its id in a vessel's URL.
     */
    public function test_the_accounts_endpoint_cannot_touch_anybody_else(): void
    {
        $superAdmin = $this->account('super_admin', null);
        $otherShipsCook = $this->account(VesselAccess::COOK, $this->cross);

        $this->signedIn($this->office());

        $this->deleteJson("/api/inventory/vessels/{$this->varun->id}/accounts/{$superAdmin->id}")
            ->assertNotFound();
        $this->deleteJson("/api/inventory/vessels/{$this->varun->id}/accounts/{$otherShipsCook->id}")
            ->assertNotFound();

        $this->assertNotNull(User::find($superAdmin->id));
        $this->assertNotNull(User::find($otherShipsCook->id));
    }

    /** A blank password box on an edit means "leave it alone". */
    public function test_editing_an_account_without_a_password_keeps_the_old_one(): void
    {
        $cook = $this->account(VesselAccess::COOK, $this->varun);
        $cook->forceFill(['password' => Hash::make('provisions8')])->save();

        $this->signedIn($this->office());
        $this->putJson("/api/inventory/vessels/{$this->varun->id}/accounts/{$cook->id}", [
            'name' => 'Renamed Cook',
            'password' => '',
        ])->assertOk();

        $this->assertSame('Renamed Cook', $cook->fresh()->name);
        $this->assertTrue(Hash::check('provisions8', $cook->fresh()->password));
    }

    /** Switching an account off takes its issued token with it. */
    public function test_deactivating_an_account_signs_it_out(): void
    {
        $cook = $this->account(VesselAccess::COOK, $this->varun);
        $cook->createToken('auth_token');
        $this->assertSame(1, $cook->tokens()->count());

        $this->signedIn($this->office());
        $this->putJson("/api/inventory/vessels/{$this->varun->id}/accounts/{$cook->id}", [
            'is_active' => false,
        ])->assertOk();

        $this->assertSame(0, $cook->tokens()->count());
    }

    /** People are not a side effect of removing a ship. */
    public function test_a_vessel_with_crew_accounts_cannot_be_removed(): void
    {
        $this->account(VesselAccess::COOK, $this->varun);
        $this->signedIn($this->office());

        $this->deleteJson("/api/inventory/vessels/{$this->varun->id}")
            ->assertStatus(422)
            ->assertSee('crew accounts', false);

        $this->assertNotNull(Vessel::find($this->varun->id));
        $this->assertTrue((bool) Vessel::find($this->varun->id)->is_active);
    }

    /* ------------------------------ the money ------------------------------ */

    /**
     * The owner's rule, in his own words: "the master can see budget, cook is
     * just cook, can't see the budget but can withdraw items etc."
     *
     * So the line is not office against ship. It is who answers for the money.
     * Every screen the cook can open is swept here rather than the one or two
     * that happened to be noticed — a price he is not shown on the dashboard
     * and is shown on the batch board is not a rule, it is an oversight with a
     * rule written next to it.
     */
    public function test_the_cook_is_shown_no_money_on_any_screen(): void
    {
        $item = $this->item($this->varun, 'Asparagus, Fresh', 20);
        \App\Support\Inventory\StockLedger::receive($item, 10, 3.50);

        $this->signedIn($this->account(VesselAccess::COOK, $this->varun));

        $row = collect($this->getJson('/api/inventory/items')->assertOk()->json('data.groups'))
            ->pluck('items')->flatten(1)->firstWhere('id', $item->id);

        foreach (['unit_price', 'standing_price', 'stock_cost', 'stock_value', 'order_value'] as $field) {
            $this->assertNull($row[$field], "items.{$field} reached the cook");
        }

        // The counts beside them survive, because they are what he acts on.
        $this->assertSame(1, $row['stock_batches'], 'he still sees what is on the shelf');

        $dash = $this->getJson('/api/inventory/dashboard')->assertOk()->json('data');
        $this->assertNull($dash['summary']['budget']);
        $this->assertNull($dash['summary']['order_value']);
        $this->assertNull($dash['summary']['budget_balance']);
        $this->assertNull($dash['vessel']['budget'], 'not in the vessel row either');

        $preview = $this->getJson('/api/inventory/requisition/preview')->assertOk()->json('data');
        $this->assertNull($preview['total_value']);
        $this->assertNull($preview['budget']['allowance']);

        $this->postJson('/api/inventory/withdrawals', [
            'lines' => [['item_id' => $item->id, 'quantity' => 2]],
        ])->assertCreated();

        $log = $this->getJson('/api/inventory/withdrawals')->assertOk()->json('data');
        $this->assertNull($log['totals']['cost']);
        $this->assertNull($log['withdrawals'][0]['cost']);
        $this->assertSame(1, $log['totals']['entries'], 'the count is his');

        // The reason options share the key `value` with a batch's worth. One is
        // an identifier and must survive; the other is money and must not.
        $this->assertSame('galley', $log['reasons'][0]['value']);

        $board = $this->getJson('/api/inventory/stock-lots')->assertOk()->json('data');
        $this->assertNull($board['summary']['value']);
        $this->assertNull($board['lots'][0]['unit_cost']);
        $this->assertNull($board['lots'][0]['value']);
        $this->assertSame(1, $board['summary']['batches'], 'the board still counts batches');

        $monthly = $this->getJson('/api/inventory/reports/monthly')->assertOk()->json('data');
        $this->assertNull($monthly['totals']['order_value']);
        $this->assertNull($monthly['months'][0]['budget']);
    }

    /** The master commands the vessel and answers for what she spends. */
    public function test_the_master_is_shown_the_budget(): void
    {
        $item = $this->item($this->varun, 'Asparagus, Fresh', 20);
        \App\Support\Inventory\StockLedger::receive($item, 10, 3.50);

        $this->signedIn($this->account(VesselAccess::MASTER, $this->varun));

        $dash = $this->getJson('/api/inventory/dashboard')->assertOk()->json('data');
        $this->assertSame(5000.0, round((float) $dash['summary']['budget'], 2));

        $board = $this->getJson('/api/inventory/stock-lots')->assertOk()->json('data');
        $this->assertSame(3.50, round((float) $board['lots'][0]['unit_cost'], 2));

        $row = collect($this->getJson('/api/inventory/items')->assertOk()->json('data.groups'))
            ->pluck('items')->flatten(1)->firstWhere('id', $item->id);
        $this->assertSame(3.50, round((float) $row['unit_price'], 2));
    }

    /**
     * A requisition's LINE prices are off the ship's copy for both ranks.
     *
     * A different rule from the one above and a document's rather than a
     * reader's: an order is a request for quantities, and the prices on it are
     * whatever the catalogue held the day it was written. The master is shown
     * what the order comes to, because he signs it; he is not shown a
     * breakdown nobody will be charged.
     */
    public function test_a_requisitions_line_prices_are_off_the_ships_copy(): void
    {
        $item = $this->item($this->varun, 'Asparagus, Fresh', 0);

        $this->signedIn($this->account(VesselAccess::COOK, $this->varun));
        $this->postJson('/api/inventory/requisitions', [
            'lines' => [['item_id' => $item->id, 'order_qty' => 10]],
        ])->assertCreated();

        $id = \App\Models\Inventory\Requisition::latest('id')->first()->id;

        $this->signedIn($this->account(VesselAccess::MASTER, $this->varun));
        $req = $this->getJson("/api/inventory/requisitions/{$id}")->assertOk()->json('data.requisition');

        $this->assertNull($req['items'][0]['unit_price'], 'no line price for the master either');
        $this->assertNull($req['items'][0]['line_total']);
        $this->assertSame(20.0, round((float) $req['total_value'], 2), 'but he sees what he is signing');

        $this->signedIn($this->office());
        $theirs = $this->getJson("/api/inventory/requisitions/{$id}?vessel_id={$this->varun->id}")
            ->assertOk()->json('data.requisition');

        $this->assertSame(2.0, round((float) $theirs['items'][0]['unit_price'], 2), 'the office buys it, so the office prices it');
    }

    /**
     * The cook is still told when his order is too big.
     *
     * This is the whole reason the veil is a veil and not a wall: he writes the
     * order, so he is the one who can take something off it. He is told the
     * finding and not the figures — and the browser cannot work the finding out
     * for itself, because it has no prices, so the server prices what he ticked.
     */
    public function test_the_cook_is_still_warned_when_an_order_is_too_big(): void
    {
        $item = $this->item($this->varun, 'Asparagus, Fresh', 0);
        $this->signedIn($this->account(VesselAccess::COOK, $this->varun));

        $verdict = $this->postJson('/api/inventory/requisition/budget', [
            'lines' => [['item_id' => $item->id, 'order_qty' => 9999]],
        ])->assertOk()->json('data.budget');

        $this->assertSame('over', $verdict['level'], 'he is told');
        $this->assertNull($verdict['allowance'], 'and not told by how much');
        $this->assertNull($verdict['order_value']);
        $this->assertStringContainsString('over the vessel', (string) $verdict['message']);
        $this->assertStringNotContainsString('$', (string) $verdict['message']);
    }

    /** An account that is not shown a price does not set one either. */
    public function test_a_cook_cannot_set_an_items_price(): void
    {
        $item = $this->item($this->varun, 'Asparagus, Fresh');
        $this->signedIn($this->account(VesselAccess::COOK, $this->varun));

        $this->putJson("/api/inventory/items/{$item->id}", [
            'code' => $item->code,
            'category_id' => $item->category_id,
            'name' => 'Asparagus, Fresh',
            'unit' => 'Bunch',          // this is his to change
            'unit_price' => 99.00,      // this is not
        ])->assertOk();

        $item->refresh();

        $this->assertSame('Bunch', $item->unit, 'the edit went through');
        $this->assertSame(2.0, round((float) $item->unit_price, 2), 'the price did not');
    }
}

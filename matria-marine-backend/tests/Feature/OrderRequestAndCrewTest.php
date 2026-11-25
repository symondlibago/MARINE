<?php

namespace Tests\Feature;

use App\Models\Inventory\Category;
use App\Models\Inventory\CrewMember;
use App\Models\Inventory\Item;
use App\Models\Inventory\OrderRequest;
use App\Models\Inventory\Requisition;
use App\Models\Inventory\Vessel;
use App\Models\User;
use App\Support\Inventory\VesselAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\NamesItsVessel;
use Tests\TestCase;

/**
 * Two things the crew asked for.
 *
 * "SO WE CANT CHOOSE WHICH WE JUST WANT TO ORDER?" — a cook looking at an item
 * marked GOOD has reasons to want some anyway, and the calculation cannot know
 * them. What he must not have to do is distort the item's target until the
 * calculator raises it for him, because that corrupts the figure every future
 * order rests on.
 *
 * And the ration sheet: twenty meals a day is a figure, twenty named men is a
 * record. The master is the one who gets asked to stand behind it.
 */
class OrderRequestAndCrewTest extends TestCase
{
    use NamesItsVessel, RefreshDatabase;

    private Vessel $vessel;
    private User $cook;
    private User $master;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', VesselAccess::MASTER, VesselAccess::COOK] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->vessel = Vessel::create([
            'name' => 'OEL VARUN',
            'crew_size' => 20,
            'previous_crew_size' => 20,
            'supply_period_days' => 30,
            'safety_margin_pct' => 0,
            'budget' => 5000,
            'currency' => 'USD',
            'exchange_rate' => 1,
            'is_active' => true,
        ]);

        $this->cook = User::factory()->create([
            'name' => 'Ship Cook',
            'username' => 'varun-cook',
            'role' => VesselAccess::COOK,
            'vessel_id' => $this->vessel->id,
            'is_active' => true,
        ]);
        $this->cook->assignRole(VesselAccess::COOK);

        $this->master = User::factory()->create([
            'name' => 'Ship Master',
            'username' => 'varun-master',
            'role' => VesselAccess::MASTER,
            'vessel_id' => $this->vessel->id,
            'is_active' => true,
        ]);
        $this->master->assignRole(VesselAccess::MASTER);

        Sanctum::actingAs($this->cook);
    }

    /**
     * An item with plenty on board: the calculator leaves it alone, which is
     * exactly the case the Order button exists for.
     */
    private function wellStocked(string $name = 'Beef Tenderloin'): Item
    {
        static $code = 7001000;

        return Item::create([
            'vessel_id' => $this->vessel->id,
            'category_id' => Category::firstOrCreate(['name' => 'Meat frozen'], ['sort' => 1])->id,
            'code' => (string) ++$code,
            'name' => $name,
            'unit' => 'Kg',
            'unit_price' => 10.00,
            'current_stock' => 500,
            'baseline_consumption' => 30,
            'baseline_period_days' => 30,
            'is_active' => true,
        ]);
    }

    private function assertFigure(float $expected, $actual, string $message = ''): void
    {
        $this->assertSame($expected, round((float) $actual, 3), $message);
    }

    /* --------------------------- order it anyway ---------------------------- */

    /** The item is GOOD and the cook wants some regardless. */
    public function test_an_item_above_target_can_still_be_asked_for(): void
    {
        $beef = $this->wellStocked();

        // Not on the calculated order: there is plenty.
        $before = $this->getJson($this->url('/requisition/preview'))->assertOk()->json('data.lines');
        $this->assertSame([], array_column($before, 'id'));

        $this->postJson($this->url('/order-requests'), [
            'item_id' => $beef->id,
            'quantity' => 12,
            'note' => 'Charterer aboard next week',
        ])->assertOk()->assertSee('added to the next order', false);

        $lines = $this->getJson($this->url('/requisition/preview'))->assertOk()->json('data.lines');

        $this->assertCount(1, $lines);
        $this->assertFigure(12.0, $lines[0]['order_qty']);
        $this->assertTrue($lines[0]['requested'], 'marked as asked for, not calculated');
        $this->assertSame('Charterer aboard next week', $lines[0]['request_note']);
        $this->assertFigure(0.0, $lines[0]['advised_qty'], 'the calculation still says none needed');

        // The cook asked for it and is not shown what it comes to. The line is
        // priced all the same — the master reads the same preview and sees it —
        // which is the difference between a figure withheld and one never
        // worked out. 12 Kg at the $10 this item carries.
        $this->assertNull($lines[0]['order_value'], 'the cook is not shown money');

        Sanctum::actingAs($this->master);

        $asMaster = $this->getJson($this->url('/requisition/preview'))
            ->assertOk()->json('data.lines');

        $this->assertFigure(120.0, $asMaster[0]['order_value']);
    }

    /**
     * The item's target is untouched by the ask.
     *
     * This is the whole reason the request lives apart from the calculation:
     * the alternative people reach for is editing the target until the sum
     * comes out right, and that figure drives every future order.
     */
    public function test_asking_for_something_does_not_change_the_calculation(): void
    {
        $beef = $this->wellStocked();
        $targetBefore = $beef->fresh()->manual_target;

        $this->postJson($this->url('/order-requests'), ['item_id' => $beef->id, 'quantity' => 12])
            ->assertOk();

        $fresh = $beef->fresh();
        $this->assertSame($targetBefore, $fresh->manual_target);
        $this->assertFigure(500.0, $fresh->current_stock, 'and the stock is not touched either');
    }

    /** Asking twice is changing your mind, not adding to the order. */
    public function test_asking_again_replaces_the_quantity(): void
    {
        $beef = $this->wellStocked();

        $this->postJson($this->url('/order-requests'), ['item_id' => $beef->id, 'quantity' => 5])->assertOk();
        $this->postJson($this->url('/order-requests'), ['item_id' => $beef->id, 'quantity' => 3])->assertOk();

        $this->assertSame(1, OrderRequest::count());
        $this->assertFigure(3.0, OrderRequest::first()->quantity);
    }

    /** What he asked for wins over what the sum says. */
    public function test_a_request_overrides_the_calculated_quantity(): void
    {
        $short = Item::create([
            'vessel_id' => $this->vessel->id,
            'category_id' => Category::firstOrCreate(['name' => 'Meat frozen'], ['sort' => 1])->id,
            'code' => '7009001',
            'name' => 'Chicken, Whole',
            'unit' => 'Kg',
            'unit_price' => 4.00,
            'current_stock' => 0,
            'baseline_consumption' => 30,
            'baseline_period_days' => 30,
            'is_active' => true,
        ]);

        $advised = $this->getJson($this->url('/requisition/preview'))->json('data.lines.0.order_qty');
        $this->assertFigure(30.0, $advised);

        $this->postJson($this->url('/order-requests'), ['item_id' => $short->id, 'quantity' => 50])->assertOk();

        $line = $this->getJson($this->url('/requisition/preview'))->json('data.lines.0');
        $this->assertFigure(50.0, $line['order_qty'], 'he is looking at the shelf');
        $this->assertFigure(30.0, $line['advised_qty'], 'and what the sum said is kept beside it');
    }

    /** Once it is on an order the standing request is spent. */
    public function test_a_request_is_cleared_once_it_is_ordered(): void
    {
        $beef = $this->wellStocked();
        $this->postJson($this->url('/order-requests'), ['item_id' => $beef->id, 'quantity' => 12])->assertOk();

        $this->postJson($this->url('/requisitions'), [
            'lines' => [['item_id' => $beef->id, 'order_qty' => 12]],
        ])->assertCreated();

        $this->assertSame(0, OrderRequest::count(), 'otherwise it turns up on the next order too');

        $req = Requisition::latest('id')->first();
        $this->assertFigure(12.0, $req->items->first()->order_qty);
    }

    public function test_a_request_can_be_taken_back_off(): void
    {
        $beef = $this->wellStocked();
        $this->postJson($this->url('/order-requests'), ['item_id' => $beef->id, 'quantity' => 12])->assertOk();

        $id = OrderRequest::first()->id;
        $this->deleteJson($this->url("/order-requests/{$id}"))->assertOk();

        $this->assertSame(0, OrderRequest::count());
        $this->assertCount(0, $this->getJson($this->url('/requisition/preview'))->json('data.lines'));
    }

    /**
     * The order screen is told which ask put a line there, so it can offer to
     * take it back.
     *
     * The ask is standing: unticking a line drops it from the order being
     * written now and the item is on the list again next time. That is right
     * when somebody meant it and maddening when they pressed Order by mistake,
     * and there was no way to undo it — the endpoint existed and nothing on any
     * screen called it, because the line never carried the id to call it with.
     */
    public function test_a_requested_line_carries_the_ask_it_can_be_cancelled_by(): void
    {
        $beef = $this->wellStocked();

        $this->postJson($this->url('/order-requests'), [
            'item_id' => $beef->id, 'quantity' => 12, 'note' => 'Charterer aboard',
        ])->assertOk();

        $line = $this->getJson($this->url('/requisition/preview'))->assertOk()->json('data.lines')[0];

        $this->assertTrue($line['requested']);
        $this->assertSame('Charterer aboard', $line['request_note']);
        $this->assertSame(OrderRequest::first()->id, $line['request_id']);

        // And that id is the one the screen deletes by.
        $this->deleteJson($this->url("/order-requests/{$line['request_id']}"))->assertOk();
        $this->assertCount(0, $this->getJson($this->url('/requisition/preview'))->json('data.lines'));
    }

    /** A line the calculator raised has no ask behind it, so none to cancel. */
    public function test_a_calculated_line_carries_no_ask(): void
    {
        $item = $this->wellStocked();
        $item->update(['current_stock' => 0]);

        $line = $this->getJson($this->url('/requisition/preview'))->assertOk()->json('data.lines')[0];

        $this->assertFalse($line['requested']);
        $this->assertArrayNotHasKey('request_id', $line);
    }

    public function test_an_item_from_another_vessel_cannot_be_asked_for(): void
    {
        $other = Vessel::create([
            'name' => 'MV SOUTHERN CROSS', 'crew_size' => 18, 'supply_period_days' => 30,
            'safety_margin_pct' => 0, 'currency' => 'USD', 'exchange_rate' => 1, 'is_active' => true,
        ]);

        $theirs = Item::create([
            'vessel_id' => $other->id,
            'category_id' => Category::firstOrCreate(['name' => 'Meat frozen'], ['sort' => 1])->id,
            'code' => '7009999', 'name' => 'Not Ours', 'unit' => 'Kg', 'current_stock' => 100,
        ]);

        $this->postJson($this->url('/order-requests'), ['item_id' => $theirs->id, 'quantity' => 1])
            ->assertStatus(422);

        $this->assertSame(0, OrderRequest::count());
    }

    /* ------------------------------ the roster ------------------------------ */

    public function test_the_roster_names_who_is_aboard(): void
    {
        $this->postJson($this->url('/crew'), [
            'name' => 'Juan Dela Cruz',
            'rank' => 'Chief Cook',
        ])->assertCreated()->assertJsonPath('data.member.label', 'Chief Cook — Juan Dela Cruz');

        $this->postJson($this->url('/crew'), [
            'name' => 'A. Surveyor',
            'category' => CrewMember::CHARTERER,
        ])->assertCreated();

        $data = $this->getJson($this->url('/crew'))->assertOk()->json('data');

        $this->assertCount(2, $data['crew']);
        $this->assertSame(2, $data['aboard']['total']);
        $this->assertSame(1, $data['aboard']['crew']);
        $this->assertSame(1, $data['aboard']['charterer'], 'not billed at the crew rate');
        $this->assertSame(20, $data['crew_size'], "what the vessel's settings say she carries");
    }

    /**
     * A crew change is a dozen people on one day.
     *
     * One name at a time is how a roster ends up half-entered and then ignored.
     */
    public function test_a_whole_crew_change_can_be_pasted_in(): void
    {
        $this->postJson($this->url('/crew/bulk'), [
            'names' => "Master, Pedro Santos\nChief Cook, Juan Dela Cruz\nMessman\nAble Seaman, R. Lim\n\n",
        ])->assertOk()->assertSee('4 signed on', false);

        $crew = $this->getJson($this->url('/crew'))->json('data.crew');

        $this->assertCount(4, $crew);

        $byName = collect($crew)->keyBy('name');
        $this->assertSame('Master', $byName['Pedro Santos']['rank']);
        $this->assertSame('Chief Cook', $byName['Juan Dela Cruz']['rank']);
        // A line with no comma is a bare name, not a rank with nobody in it.
        $this->assertNull($byName['Messman']['rank']);
    }

    public function test_pasting_the_same_list_twice_does_not_double_the_crew(): void
    {
        $names = "Master, Pedro Santos\nChief Cook, Juan Dela Cruz";

        $this->postJson($this->url('/crew/bulk'), ['names' => $names])->assertOk();
        $this->postJson($this->url('/crew/bulk'), ['names' => $names])
            ->assertOk()
            ->assertSee('already aboard', false);

        $this->assertSame(2, CrewMember::count());
    }

    /**
     * Signing off keeps the name.
     *
     * Last month's ration report has to name the men it was billed for, so the
     * ordinary case is signing off rather than deleting.
     */
    public function test_signing_off_keeps_the_name_and_records_the_day(): void
    {
        $this->postJson($this->url('/crew'), ['name' => 'Juan Dela Cruz'])->assertCreated();
        $member = CrewMember::first();

        $this->putJson($this->url("/crew/{$member->id}"), ['is_active' => false])->assertOk();

        $off = $member->fresh();
        $this->assertFalse($off->is_active);
        $this->assertSame(now()->toDateString(), $off->left_on->toDateString());

        // Off the default list, still on the full one.
        $this->assertCount(0, $this->getJson($this->url('/crew'))->json('data.crew'));
        $this->assertCount(1, $this->getJson($this->url('/crew?all=1'))->json('data.crew'));
    }

    /** A name typed in by mistake goes away entirely. */
    public function test_a_mistyped_name_can_be_removed(): void
    {
        $this->postJson($this->url('/crew'), ['name' => 'Typo McTypo'])->assertCreated();

        $this->deleteJson($this->url('/crew/'.CrewMember::first()->id))->assertOk();

        $this->assertSame(0, CrewMember::count());
    }

    /**
     * The roster drives the sheet, not the settings.
     *
     * "Fill empty days" writes the crew figure onto every sitting, so it had
     * better be who is actually aboard rather than the number somebody typed
     * into Settings when the ship joined the fleet.
     */
    public function test_the_ration_sheet_counts_who_is_actually_aboard(): void
    {
        // Settings say 20.
        $this->assertSame(20, $this->getJson($this->url('/crew-rations'))->assertOk()->json('data.crew_size'));

        $this->postJson($this->url('/crew/bulk'), [
            'names' => "Master, Pedro Santos\nChief Cook, Juan Dela Cruz\nMessman, R. Lim",
        ])->assertOk();

        // And the charterer's surveyor is not fed as crew.
        $this->postJson($this->url('/crew'), [
            'name' => 'A. Surveyor', 'category' => CrewMember::CHARTERER,
        ])->assertCreated();

        $data = $this->getJson($this->url('/crew-rations'))->assertOk()->json('data');

        $this->assertSame(3, $data['crew_size'], 'the three named crew, not the twenty in Settings');
        $this->assertSame(3, $data['crew_named']);
    }

    /** An empty roster leaves the settings figure alone. */
    public function test_an_empty_roster_falls_back_to_the_settings(): void
    {
        $data = $this->getJson($this->url('/crew-rations'))->assertOk()->json('data');

        $this->assertSame(20, $data['crew_size']);
        $this->assertSame(0, $data['crew_named']);
    }

    /** The names are printed on the report the owner is billed from. */
    public function test_the_ration_report_names_the_men_it_bills_for(): void
    {
        $this->vessel->update(['ration_rate' => 9.30]);

        $this->postJson($this->url('/crew/bulk'), [
            'names' => "Chief Cook, Juan Dela Cruz\nMessman, R. Lim",
        ])->assertOk();

        $month = now()->format('Y-m');
        $this->postJson($this->url('/crew-rations'), [
            'days' => [[
                'date' => $month.'-01',
                'breakfast_crew' => 2, 'lunch_crew' => 2, 'dinner_crew' => 2,
            ]],
        ])->assertOk();

        // The cook keeps the sheet; he is not handed the invoice struck from
        // it. There is no veiled version of this document — it is a charge line
        // per category at the agreed rate, and that is the whole of it.
        $this->get($this->url("/crew-rations/invoice/pdf?from={$month}-01&to={$month}-28"))
            ->assertForbidden();

        Sanctum::actingAs($this->master);

        $pdf = $this->get($this->url("/crew-rations/invoice/pdf?from={$month}-01&to={$month}-28"))
            ->assertOk()
            ->getContent();

        foreach (['ON BOARD', 'Juan Dela Cruz', 'Chief Cook', 'R. Lim'] as $needle) {
            $this->assertTrue(
                $this->pdfContains($pdf, $needle),
                "the report should name {$needle}"
            );
        }
    }

    /**
     * The ration sheet keeps every head and loses every rate.
     *
     * The cook fills this screen in, so blanking the counts would make it
     * useless to him. What goes is the rate per ration and the money struck
     * from it — and the purchases box stays, because that is his own spending
     * ashore being reported rather than a commercial rate being read.
     */
    public function test_the_cook_counts_the_heads_but_reads_no_rates(): void
    {
        $this->vessel->update([
            'ration_rate' => 9.30,
            'visitor_meal_rate' => 4.00,
            'charterer_meal_rate' => 5.00,
        ]);

        $month = now()->format('Y-m');
        $this->postJson($this->url('/crew-rations'), [
            'days' => [[
                'date' => $month.'-01',
                'breakfast_crew' => 3, 'lunch_crew' => 3, 'dinner_crew' => 3,
                'purchases_amount' => 85,
            ]],
        ])->assertOk();

        $cook = $this->getJson($this->url('/crew-rations?month='.$month))->assertOk()->json('data');
        $day = collect($cook['days'])->firstWhere('date', $month.'-01');

        // The counts, and what they mean in rations, all survive.
        // Loose comparisons throughout: a round() of 3.0 is encoded as `3` and
        // comes back an int, which says nothing about the rule being tested.
        $this->assertSame(9, $day['total_meals']);
        $this->assertEquals(3, $day['total_rations']);
        $this->assertEquals(3, $cook['totals']['crew_rations']);
        $this->assertEquals(85, $day['purchases_amount'], 'his own spending ashore is his to report');

        // The rates and every amount do not.
        $this->assertNull($cook['rates']['ration']);
        $this->assertNull($cook['rates']['visitor']);
        $this->assertNull($cook['rates']['charterer']);
        $this->assertNull($day['amount']);
        $this->assertNull($cook['totals']['amount']);
        foreach ($cook['totals']['charges'] as $line) {
            $this->assertNull($line['rate'], "the {$line['category']} rate is not his");
            $this->assertNull($line['amount']);
            $this->assertNotNull($line['quantity'], 'but how many were fed is');
        }

        // The master reads the same sheet with the money on it.
        Sanctum::actingAs($this->master);

        $master = $this->getJson($this->url('/crew-rations?month='.$month))->assertOk()->json('data');

        $this->assertEquals(9.3, $master['rates']['ration']);
        $this->assertEquals(27.9, collect($master['days'])->firstWhere('date', $month.'-01')['amount']);
        $this->assertEquals(27.9, $master['totals']['amount']);
    }

    /**
     * Text in a PDF is drawn as UTF-16BE inside flate-compressed streams, so
     * a plain string search finds nothing. Decode the streams first.
     */
    private function pdfContains(string $pdf, string $needle): bool
    {
        $wide = mb_convert_encoding($needle, 'UTF-16BE', 'UTF-8');

        if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $matches)) {
            foreach ($matches[1] as $stream) {
                $plain = @gzuncompress($stream);

                if ($plain !== false && (str_contains($plain, $wide) || str_contains($plain, $needle))) {
                    return true;
                }
            }
        }

        return str_contains($pdf, $needle);
    }

    public function test_another_vessels_crew_are_out_of_reach(): void
    {
        $other = Vessel::create([
            'name' => 'MV SOUTHERN CROSS', 'crew_size' => 18, 'supply_period_days' => 30,
            'safety_margin_pct' => 0, 'currency' => 'USD', 'exchange_rate' => 1, 'is_active' => true,
        ]);

        $theirs = CrewMember::create(['vessel_id' => $other->id, 'name' => 'Their Cook']);

        $this->putJson($this->url("/crew/{$theirs->id}"), ['name' => 'Mine Now'])->assertNotFound();
        $this->deleteJson($this->url("/crew/{$theirs->id}"))->assertNotFound();

        $this->assertSame('Their Cook', $theirs->fresh()->name);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Inventory\Category;
use App\Models\Inventory\Item;
use App\Models\Inventory\StockLot;
use App\Models\Inventory\SupplyPeriod;
use App\Models\Inventory\Vessel;
use App\Models\Inventory\Withdrawal;
use App\Models\User;
use App\Support\Inventory\ProvisionCalculator;
use App\Support\Inventory\StockLedger;
use App\Support\Inventory\VesselAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\NamesItsVessel;
use Tests\TestCase;

/**
 * The cook's own screens: recording a draw, and the batch board.
 *
 * The arithmetic is proved in StockLedgerTest. What matters here is the way
 * the crew meet it: a day's draw saved in one go, a refusal that says how much
 * is actually on the shelf, an undo that is safe, and a board that puts what
 * is about to go off at the top.
 */
class WithdrawalTest extends TestCase
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
            'supply_period_days' => 30,
            'safety_margin_pct' => 0,
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
     * Sign in as the master instead.
     *
     * The cook is not shown money — see VesselAccess::seesMoney — so a test
     * about what a price or a batch is WORTH has to be read by an account that
     * is shown it, or it is testing the veil rather than the arithmetic. The
     * cook stays the default here because he is who does this work.
     */
    private function asMaster(): void
    {
        Sanctum::actingAs($this->master);
    }

    private function item(string $name, array $attributes = []): Item
    {
        static $code = 5001000;

        return Item::create($attributes + [
            'vessel_id' => $this->vessel->id,
            'category_id' => Category::firstOrCreate(['name' => 'Meat frozen'], ['sort' => 1])->id,
            'code' => (string) ++$code,
            'name' => $name,
            'unit' => 'Kg',
            'current_stock' => 0,
            'is_active' => true,
        ]);
    }

    private function assertFigure(float $expected, $actual, string $message = ''): void
    {
        $this->assertSame($expected, round((float) $actual, 4), $message);
    }

    /** One item as the inventory screen receives it, calculated fields and all. */
    private function itemRow(Item $item): array
    {
        $groups = $this->getJson($this->url('/items'))->assertOk()->json('data.groups');

        return collect($groups)->flatMap(fn ($g) => $g['items'])->firstWhere('id', $item->id);
    }

    /* ------------------------------ recording ------------------------------ */

    /** A day's draw is one save, not one save per ingredient. */
    public function test_a_days_draw_is_recorded_in_one_request(): void
    {
        $beef = $this->item('Beef Tenderloin');
        $rice = $this->item('Rice, White', ['unit' => 'Kg']);

        StockLedger::receive($beef, 20, 12.00);
        StockLedger::receive($rice, 50, 2.00);

        $this->postJson($this->url('/withdrawals'), [
            'date' => '2026-10-20',
            'lines' => [
                ['item_id' => $beef->id, 'quantity' => 3],
                ['item_id' => $rice->id, 'quantity' => 5],
            ],
        ])->assertCreated()->assertSee('2 items recorded', false);

        $this->assertSame(2, Withdrawal::count());
        $this->assertFigure(17.0, $beef->fresh()->current_stock);
        $this->assertFigure(45.0, $rice->fresh()->current_stock);

        // 3 at $12 and 5 at $2.
        $this->assertFigure(46.0, Withdrawal::sum('cost'));
    }

    /**
     * A top-up at one month's price does not reprice the stock already aboard.
     *
     * The ship's own report of the bug: 13 Kg of coffee at $14.50, 10 Kg drawn,
     * then 3 Kg booked in at $16.00 — and the inventory screen then said the
     * coffee was $16.00, including the 3 Kg that had been sitting there since
     * the month before at $14.50. Nothing on the shelf had changed.
     *
     * Two prices, two homes. The lot carries what a delivery charged, which is
     * what costs the galley. The item carries what the office agreed to pay for
     * the next one, which comes from a quotation. A delivery note writes the
     * first and never the second.
     */
    public function test_a_delivery_does_not_reprice_what_is_already_on_board(): void
    {
        $coffee = $this->item('Coffee Beans, Dark Roast', ['unit_price' => 14.50]);
        StockLedger::receive($coffee, 13, 14.50);

        $this->postJson($this->url('/withdrawals'), [
            'lines' => [['item_id' => $coffee->id, 'quantity' => 10]],
        ])->assertCreated();

        $this->assertFigure(145.0, Withdrawal::sum('cost'), '10 Kg at the price it was bought at');

        // A 3 Kg top-up at this month's price, booked in the cook's own way.
        $receipt = $this->postJson($this->url('/receipts'), [
            'reference' => 'DO-COFFEE',
            'items' => [['item_id' => $coffee->id, 'received_qty' => 3, 'unit_price' => 16.00]],
        ])->assertCreated()->json('data.receipt.id');

        $this->postJson($this->url("/receipts/{$receipt}/confirm"))->assertOk();

        // The standing price is untouched...
        $this->assertFigure(14.50, $coffee->fresh()->unit_price, 'the delivery rewrote the standing price');

        // ...and both batches are on the shelf at what each of them cost.
        $lots = StockLot::where('item_id', $coffee->id)->where('remaining', '>', 0)
            ->orderBy('id')->get();

        $this->assertCount(2, $lots);
        $this->assertFigure(3.0, $lots[0]->remaining);
        $this->assertFigure(14.50, $lots[0]->unit_cost);
        $this->assertFigure(3.0, $lots[1]->remaining);
        $this->assertFigure(16.00, $lots[1]->unit_cost);

        // So the next 3 Kg out still come off the old batch at the old price.
        $this->postJson($this->url('/withdrawals'), [
            'lines' => [['item_id' => $coffee->id, 'quantity' => 3]],
        ])->assertCreated();

        $this->assertFigure(43.50, (float) Withdrawal::latest('id')->first()->cost);
    }

    /**
     * ...and the price on the inventory screen is the price of that batch.
     *
     * The second half of the same report, carried right through: with 3 Kg left
     * at $14.50 and 3 Kg at $16.00 the screen must say $14.50, because that is
     * what the next kilo out costs. Draw all six, book in five at $20.00, and
     * the screen must then say $20.00 — every kilo aboard cost that. One
     * question, asked of the shelf rather than of the paperwork.
     */
    public function test_the_price_on_screen_is_the_price_of_the_batch_on_the_shelf(): void
    {
        $this->asMaster();
        $coffee = $this->item('Coffee Beans, Dark Roast', ['unit_price' => 9.99]);

        $priceOnScreen = function () use ($coffee) {
            $groups = $this->getJson($this->url('/items'))->assertOk()->json('data.groups');
            foreach ($groups as $group) {
                foreach ($group['items'] as $row) {
                    if ($row['id'] === $coffee->id) {
                        return $row;
                    }
                }
            }

            return null;
        };

        // Nothing on board: there is nothing to cost, so the office's figure
        // stands — and it is the only figure the edit form ever offers.
        $row = $priceOnScreen();
        $this->assertFigure(9.99, $row['unit_price']);
        $this->assertNull($row['stock_cost'], 'an empty store cannot have a cost');
        $this->assertFigure(9.99, $row['standing_price']);

        StockLedger::receive($coffee, 3, 14.50);
        StockLedger::receive($coffee->fresh(), 3, 16.00);

        // Two batches aboard, and the older one is what the galley draws next.
        $row = $priceOnScreen();
        $this->assertFigure(14.50, $row['unit_price'], 'the newest delivery is not the price');
        $this->assertFigure(14.50, $row['stock_cost']);
        $this->assertFigure(9.99, $row['standing_price'], 'the stored figure is untouched');
        $this->assertFigure(91.50, $row['stock_value'], '3 at $14.50 and 3 at $16.00');

        // The galley empties the store, and five Kg arrive at a third price.
        $this->postJson($this->url('/withdrawals'), [
            'lines' => [['item_id' => $coffee->id, 'quantity' => 6]],
        ])->assertCreated();

        StockLedger::receive($coffee->fresh(), 5, 20.00);

        $row = $priceOnScreen();
        $this->assertFigure(20.00, $row['unit_price'], 'every Kg aboard cost $20.00');
        $this->assertFigure(100.00, $row['stock_value']);
    }

    /**
     * A batch opened with no price is no information, and must not be read as
     * free food — the 813 items that came aboard with the migration are all
     * like this, and an order priced at zero would sail straight past a budget.
     */
    public function test_a_batch_with_no_price_falls_back_to_the_office_s_figure(): void
    {
        $this->asMaster();
        $rice = $this->item('Rice, White', ['unit_price' => 2.00]);
        StockLedger::receive($rice, 50, 0.0);

        $groups = $this->getJson($this->url('/items'))->assertOk()->json('data.groups');
        $row = collect($groups)->flatMap(fn ($g) => $g['items'])->firstWhere('id', $rice->id);

        $this->assertNull($row['stock_cost']);
        $this->assertFigure(2.00, $row['unit_price']);
    }

    /**
     * A shelf holding two prices says so, instead of showing one and leaving
     * the other to be discovered.
     *
     * The case that caused it: 5 Kg booked in at $20.00 today, then 2 Kg at
     * $25.00 back-dated to the 2nd. The older date puts the dearer batch at the
     * front of the queue, so $25.00 really is what the next Kg costs — and it
     * reads as a mistake to anyone who remembers paying $20.00. Correct, but
     * only half the shelf.
     */
    public function test_a_shelf_with_two_prices_shows_the_spread(): void
    {
        $this->asMaster();
        $coffee = $this->item('Coffee Beans, Dark Roast');

        StockLedger::receive($coffee, 5, 20.00, null, null, Carbon::today()->toDateString());
        StockLedger::receive($coffee->fresh(), 2, 25.00, null, null, Carbon::today()->subDays(25)->toDateString());

        $row = $this->itemRow($coffee);

        $this->assertFigure(25.00, $row['unit_price'], 'the oldest batch is what goes out next');
        $this->assertSame(2, $row['stock_batches']);
        $this->assertFigure(20.00, $row['stock_cost_low']);
        $this->assertFigure(25.00, $row['stock_cost_high']);
        $this->assertFigure(150.00, $row['stock_value'], '5 at $20.00 and 2 at $25.00');

        // One price on the shelf has nothing to qualify.
        $rice = $this->item('Rice, White');
        StockLedger::receive($rice, 10, 2.00);
        StockLedger::receive($rice->fresh(), 10, 2.00);

        $plain = $this->itemRow($rice);
        $this->assertSame(2, $plain['stock_batches']);
        $this->assertFigure(2.00, $plain['stock_cost_low']);
        $this->assertFigure(2.00, $plain['stock_cost_high']);
    }

    /* ------------------------------- going off ----------------------------- */

    /**
     * An item can be GOOD against its target and still be going off on Friday.
     *
     * The batch board had this all along and nothing else did, so the one screen
     * the cook lives on could not tell him what to use up. The warning now rides
     * on the item row and on the dashboard card, both off the same figures, so
     * they cannot disagree about what "soon" means.
     */
    public function test_an_item_carries_the_date_of_its_soonest_batch(): void
    {
        $this->asMaster();
        $window = ProvisionCalculator::EXPIRY_WINDOW_DAYS;
        $milk = $this->item('Milk, Fresh', ['shelf_life_days' => 90]);

        // Plenty of time on this one, and a smaller batch going off this week.
        StockLedger::receive($milk, 40, 2.00, Carbon::today()->addDays($window + 60)->toDateString());
        StockLedger::receive($milk->fresh(), 6, 2.50, Carbon::today()->addDays(3)->toDateString());

        $row = $this->itemRow($milk);

        $this->assertSame(3, $row['days_left'], 'the soonest batch is the one to warn about');
        $this->assertFigure(6.0, $row['expiring_qty'], 'only the batch at risk, not the whole shelf');
        $this->assertFigure(15.0, $row['expiring_value']);
        $this->assertSame(ProvisionCalculator::GOOD, $row['status'], 'plenty on board — this is not a quantity problem');
        $this->assertSame($window, $row['expiry_window_days']);
    }

    /** Past its date is a different word, and a different colour. */
    public function test_a_batch_already_past_its_date_reads_as_negative(): void
    {
        $fish = $this->item('Mackerel');
        StockLedger::receive($fish, 5, 9.50, Carbon::today()->subDays(2)->toDateString());

        $this->assertSame(-2, $this->itemRow($fish)['days_left']);
    }

    /**
     * An undated batch is not a fresh one. The 219 batches that came aboard
     * with the migration have no date at all, and must not be counted as having
     * time left — nor as having run out.
     */
    public function test_an_undated_batch_warns_about_nothing(): void
    {
        $rice = $this->item('Rice, White', ['shelf_life_days' => null]);
        StockLedger::receive($rice, 50, 2.00);

        $row = $this->itemRow($rice);

        $this->assertNull($row['days_left']);
        $this->assertNull($row['expires_on']);
        $this->assertFigure(0.0, $row['expiring_qty']);
    }

    /** The dashboard counts items to deal with, not batches to throw away. */
    public function test_the_dashboard_counts_what_needs_eating(): void
    {
        $this->asMaster();
        $soon = $this->item('Yoghurt');
        StockLedger::receive($soon, 4, 1.50, Carbon::today()->addDays(2)->toDateString());
        StockLedger::receive($soon->fresh(), 4, 1.50, Carbon::today()->addDays(4)->toDateString());

        $gone = $this->item('Cream');
        StockLedger::receive($gone, 2, 3.00, Carbon::today()->subDay()->toDateString());

        $keeps = $this->item('Flour');
        StockLedger::receive($keeps, 25, 1.00, Carbon::today()->addYear()->toDateString());

        $summary = $this->getJson($this->url('/dashboard'))->assertOk()->json('data.summary');

        $this->assertSame(1, $summary['expired_items'], 'two batches of yoghurt is still one item');
        $this->assertSame(1, $summary['expiring_items']);
        $this->assertSame(-1, $summary['soonest_days_left']);
        // 4 + 4 yoghurt at $1.50, 2 cream at $3.00. The flour keeps.
        $this->assertFigure(18.0, $summary['expiring_value']);
    }

    /** The cook is the one recording it, and the log says so. */
    public function test_the_log_remembers_who_recorded_it(): void
    {
        $beef = $this->item('Beef Tenderloin');
        StockLedger::receive($beef, 20, 12.00);

        $this->postJson($this->url('/withdrawals'), [
            'lines' => [['item_id' => $beef->id, 'quantity' => 2]],
        ])->assertCreated();

        $row = $this->getJson($this->url('/withdrawals'))->assertOk()->json('data.withdrawals.0');

        $this->assertSame('Ship Cook', $row['by']);
        $this->assertSame('To the galley', $row['reason_label']);
    }

    /**
     * You cannot take out more than is on board.
     *
     * And the refusal says how much there is, because the next thing the cook
     * needs to know is whether the shelf or the screen is wrong.
     */
    public function test_taking_more_than_is_on_board_is_refused(): void
    {
        $beef = $this->item('Beef Tenderloin');
        StockLedger::receive($beef, 4, 12.00);

        $this->postJson($this->url('/withdrawals'), [
            'lines' => [['item_id' => $beef->id, 'quantity' => 10]],
        ])
            ->assertStatus(422)
            ->assertSee('only 4 Kg', false);

        $this->assertSame(0, Withdrawal::count());
    }

    /** One bad line must not leave the rest of the day's draw recorded. */
    public function test_a_bad_line_saves_none_of_them(): void
    {
        $beef = $this->item('Beef Tenderloin');
        $rice = $this->item('Rice, White');

        StockLedger::receive($beef, 20, 12.00);
        StockLedger::receive($rice, 2, 2.00);

        $this->postJson($this->url('/withdrawals'), [
            'lines' => [
                ['item_id' => $beef->id, 'quantity' => 3],   // fine
                ['item_id' => $rice->id, 'quantity' => 50],  // not
            ],
        ])->assertStatus(422);

        $this->assertSame(0, Withdrawal::count());
        $this->assertFigure(20.0, $beef->fresh()->current_stock, 'the good line is not recorded either');
    }

    /** Another vessel's item is not this cook's to draw. */
    public function test_an_item_from_another_vessel_is_refused(): void
    {
        $other = Vessel::create([
            'name' => 'MV SOUTHERN CROSS', 'crew_size' => 18, 'supply_period_days' => 30,
            'safety_margin_pct' => 0, 'currency' => 'USD', 'exchange_rate' => 1, 'is_active' => true,
        ]);

        $theirs = Item::create([
            'vessel_id' => $other->id,
            'category_id' => Category::firstOrCreate(['name' => 'Meat frozen'], ['sort' => 1])->id,
            'code' => '5009999', 'name' => 'Not Ours', 'unit' => 'Kg', 'current_stock' => 100,
        ]);

        $this->postJson($this->url('/withdrawals'), [
            'lines' => [['item_id' => $theirs->id, 'quantity' => 1]],
        ])->assertStatus(422);

        $this->assertSame(0, Withdrawal::count());
    }

    /* ------------------------------- the log ------------------------------- */

    /**
     * Totals cover the window, not the filtered list.
     *
     * Otherwise narrowing to one item makes the month's spend appear to drop.
     */
    public function test_the_months_totals_do_not_move_when_the_list_is_filtered(): void
    {
        $this->asMaster();
        $beef = $this->item('Beef Tenderloin');
        $rice = $this->item('Rice, White');
        StockLedger::receive($beef, 20, 10.00);
        StockLedger::receive($rice, 20, 5.00);

        $this->postJson($this->url('/withdrawals'), [
            'date' => now()->startOfMonth()->addDay()->toDateString(),
            'lines' => [
                ['item_id' => $beef->id, 'quantity' => 2],   // $20
                ['item_id' => $rice->id, 'quantity' => 2],   // $10
            ],
        ])->assertCreated();

        $all = $this->getJson($this->url('/withdrawals'))->assertOk()->json('data');
        $this->assertCount(2, $all['withdrawals']);
        $this->assertFigure(30.0, $all['totals']['cost']);

        $narrowed = $this->getJson($this->url("/withdrawals?item_id={$beef->id}"))->assertOk()->json('data');
        $this->assertCount(1, $narrowed['withdrawals']);
        $this->assertFigure(30.0, $narrowed['totals']['cost'], 'the window total is unchanged');
    }

    /** Waste and galley use both leave the store; only one of them fed anyone. */
    public function test_what_was_thrown_away_is_counted_separately(): void
    {
        $this->asMaster();
        $milk = $this->item('Milk, Fresh', ['unit' => 'L']);
        StockLedger::receive($milk, 30, 1.50);

        $this->postJson($this->url('/withdrawals'), [
            'date' => now()->toDateString(),
            'lines' => [
                ['item_id' => $milk->id, 'quantity' => 10, 'reason' => Withdrawal::GALLEY],
                ['item_id' => $milk->id, 'quantity' => 4, 'reason' => Withdrawal::WASTE, 'note' => 'Soured'],
            ],
        ])->assertCreated();

        $totals = $this->getJson($this->url('/withdrawals'))->assertOk()->json('data.totals.by_reason');

        $this->assertFigure(15.0, $totals[Withdrawal::GALLEY]['cost']);
        $this->assertFigure(6.0, $totals[Withdrawal::WASTE]['cost']);
    }

    /* -------------------------------- undoing ------------------------------ */

    public function test_a_mistyped_withdrawal_can_be_undone(): void
    {
        $beef = $this->item('Beef Tenderloin');
        $lot = StockLedger::receive($beef, 20, 12.00);

        $this->postJson($this->url('/withdrawals'), [
            'lines' => [['item_id' => $beef->id, 'quantity' => 15]],
        ])->assertCreated();

        $id = Withdrawal::first()->id;

        $this->deleteJson($this->url("/withdrawals/{$id}"))->assertOk();

        $this->assertSame(0, Withdrawal::count());
        $this->assertFigure(20.0, $beef->fresh()->current_stock);
        $this->assertFigure(20.0, $lot->fresh()->remaining);
    }

    /** A counted, closed month is not rewritten from the log screen. */
    public function test_a_withdrawal_in_a_closed_period_cannot_be_undone(): void
    {
        $beef = $this->item('Beef Tenderloin');
        StockLedger::receive($beef, 20, 12.00);

        $period = SupplyPeriod::create([
            'vessel_id' => $this->vessel->id,
            'label' => 'October 2026',
            'start_date' => now()->startOfMonth()->toDateString(),
            'days' => 30,
            'crew_size' => 20,
            'status' => 'open',
        ]);

        $this->postJson($this->url('/withdrawals'), [
            'lines' => [['item_id' => $beef->id, 'quantity' => 5]],
        ])->assertCreated();

        $withdrawal = Withdrawal::first();
        $this->assertSame($period->id, $withdrawal->period_id, 'it landed on the open period');

        $period->update(['status' => 'closed']);

        $this->deleteJson($this->url("/withdrawals/{$withdrawal->id}"))
            ->assertStatus(422)
            ->assertSee('closed and counted', false);

        $this->assertSame(1, Withdrawal::count());
    }

    /** Another ship's log is not reachable by putting its id in the URL. */
    public function test_another_vessels_withdrawal_is_out_of_reach(): void
    {
        $other = Vessel::create([
            'name' => 'MV SOUTHERN CROSS', 'crew_size' => 18, 'supply_period_days' => 30,
            'safety_margin_pct' => 0, 'currency' => 'USD', 'exchange_rate' => 1, 'is_active' => true,
        ]);

        $theirs = Withdrawal::create([
            'vessel_id' => $other->id,
            'item_id' => $this->item('Beef Tenderloin')->id,
            'date' => now()->toDateString(),
            'quantity' => 1,
            'cost' => 10,
            'reason' => Withdrawal::GALLEY,
        ]);

        $this->deleteJson($this->url("/withdrawals/{$theirs->id}"))->assertNotFound();

        $this->assertNotNull(Withdrawal::find($theirs->id));
    }

    /* ----------------------------- the batch board -------------------------- */

    /**
     * What is about to go off comes first, and the board says what it is worth.
     *
     * One query over the lots answers both the costing question and the expiry
     * question, which is why there is no second set of records to keep in step.
     */
    public function test_the_board_puts_what_is_going_off_at_the_top(): void
    {
        $this->asMaster();
        $milk = $this->item('Milk, Fresh', ['unit' => 'L']);

        StockLedger::receive($milk, 10, 1.00, now()->subDays(2)->toDateString());   // gone off
        StockLedger::receive($milk, 10, 2.00, now()->addDays(3)->toDateString());   // this week
        StockLedger::receive($milk, 10, 3.00, now()->addDays(90)->toDateString());  // fine
        StockLedger::receive($this->item('Salt'), 5, 1.00, null);                   // keeps

        $data = $this->getJson($this->url('/stock-lots?within=7'))->assertOk()->json('data');

        $this->assertSame(
            [-2, 3, 90, null],
            array_column($data['lots'], 'days_left'),
            'soonest first, undated last'
        );

        $this->assertFigure(65.0, $data['summary']['value'], '10+20+30 of milk and 5 of salt');
        $this->assertSame(1, $data['summary']['expired']);
        $this->assertFigure(10.0, $data['summary']['expired_value']);
        $this->assertSame(1, $data['summary']['expiring']);
        $this->assertSame(1, $data['summary']['undated']);
    }

    /** A spent batch is history, not stock. */
    public function test_an_empty_batch_is_off_the_board(): void
    {
        $this->asMaster();
        $beef = $this->item('Beef Tenderloin');
        StockLedger::receive($beef, 10, 10.00, null, null, '2026-01-01');
        StockLedger::receive($beef, 10, 20.00, null, null, '2026-02-01');

        $this->postJson($this->url('/withdrawals'), [
            'lines' => [['item_id' => $beef->id, 'quantity' => 10]],
        ])->assertCreated();

        $lots = $this->getJson($this->url('/stock-lots'))->assertOk()->json('data.lots');

        $this->assertCount(1, $lots);
        $this->assertFigure(20.0, $lots[0]['unit_cost'], 'only the dear batch is left');
    }

    /** An expired batch is written off out of itself, not out of FIFO order. */
    public function test_a_spoiled_batch_is_written_off_out_of_that_batch(): void
    {
        $milk = $this->item('Milk, Fresh', ['unit' => 'L']);

        $old = StockLedger::receive($milk, 10, 1.00, now()->subDay()->toDateString(), null, '2026-01-01');
        StockLedger::receive($milk, 10, 5.00, now()->addDays(30)->toDateString(), null, '2026-02-01');

        $this->postJson($this->url('/withdrawals'), [
            'lines' => [[
                'item_id' => $milk->id,
                'quantity' => 10,
                'lot_id' => $old->id,
                'reason' => Withdrawal::WASTE,
            ]],
        ])->assertCreated();

        $this->assertFigure(10.0, Withdrawal::first()->cost, 'charged at the spoiled batch, not the fresh one');
        $this->assertFigure(0.0, $old->fresh()->remaining);
        $this->assertSame(1, StockLot::where('item_id', $milk->id)->where('remaining', '>', 0)->count());
    }

    /* ------------------------------ who may draw ---------------------------- */

    /** Recording what left the store is the cook's job, and he can do it. */
    public function test_the_cook_may_record_and_undo_his_own_draws(): void
    {
        $beef = $this->item('Beef Tenderloin');
        StockLedger::receive($beef, 20, 12.00);

        $this->postJson($this->url('/withdrawals'), [
            'lines' => [['item_id' => $beef->id, 'quantity' => 2]],
        ])->assertCreated();

        $this->getJson($this->url('/withdrawals'))->assertOk();
        $this->getJson($this->url('/stock-lots'))->assertOk();
        $this->deleteJson($this->url('/withdrawals/'.Withdrawal::first()->id))->assertOk();
    }
}

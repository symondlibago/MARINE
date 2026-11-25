<?php

namespace Tests\Feature;

use App\Models\Inventory\Category;
use App\Models\Inventory\Item;
use App\Models\Inventory\StockLot;
use App\Models\Inventory\Vessel;
use App\Models\Inventory\Withdrawal;
use App\Support\Inventory\StockLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FIFO costing, and the lots it runs on.
 *
 * The problem this solves, in the owner's own words: 40 bought at $10, the
 * cook works it down to 10, 40 more arrive at $15 — there are 50 on board but
 * they are not 50 at $15. Draw 20 and you have spent the last 10 of the cheap
 * stock and 10 of the dear, which is $250, not $300.
 *
 * Nothing in here goes through HTTP. This is the arithmetic the whole module
 * will lean on, so it is tested on its own, where a failure says which rule
 * broke rather than which screen.
 */
class StockLedgerTest extends TestCase
{
    use RefreshDatabase;

    private Vessel $vessel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vessel = Vessel::create([
            'name' => 'OEL VARUN',
            'crew_size' => 20,
            'supply_period_days' => 30,
            'safety_margin_pct' => 0,
            'currency' => 'USD',
            'exchange_rate' => 1,
            'is_active' => true,
        ]);
    }

    private function item(array $attributes = []): Item
    {
        static $code = 4001000;

        return Item::create($attributes + [
            'vessel_id' => $this->vessel->id,
            'category_id' => Category::firstOrCreate(['name' => 'Meat frozen'], ['sort' => 1])->id,
            'code' => (string) ++$code,
            'name' => 'Beef Tenderloin',
            'unit' => 'Kg',
            'current_stock' => 0,
            'is_active' => true,
        ]);
    }

    /** Quantities are stored to 3dp and money to 4 — compare as floats. */
    private function assertFigure(float $expected, $actual, string $message = ''): void
    {
        $this->assertSame($expected, round((float) $actual, 4), $message);
    }

    /* ------------------------------ the example ----------------------------- */

    /**
     * The worked example, end to end.
     *
     * If only one test in this file survives, it should be this one.
     */
    public function test_the_cheap_stock_is_spent_before_the_dear_stock(): void
    {
        $item = $this->item();

        StockLedger::receive($item, 40, 10.00, null, null, '2026-01-10');
        StockLedger::withdraw($item->fresh(), 30, ['date' => '2026-01-20']);

        $this->assertFigure(10.0, $item->fresh()->current_stock, 'cooked down to 10');

        StockLedger::receive($item->fresh(), 40, 15.00, null, null, '2026-02-01');

        $this->assertFigure(50.0, $item->fresh()->current_stock);
        $this->assertFigure(700.0, StockLedger::value($item), '10 at $10 plus 40 at $15');

        // 10 of the old at $10, then 10 of the new at $15.
        $withdrawal = StockLedger::withdraw($item->fresh(), 20, ['date' => '2026-02-05']);

        $this->assertFigure(250.0, $withdrawal->cost);
        $this->assertFigure(30.0, $item->fresh()->current_stock);
        $this->assertFigure(450.0, StockLedger::value($item), 'what is left is all at $15');

        // And the working is kept, not just the answer.
        $lines = $withdrawal->lots->sortBy('unit_cost')->values();
        $this->assertCount(2, $lines);
        $this->assertFigure(10.0, $lines[0]->quantity);
        $this->assertFigure(10.0, $lines[0]->unit_cost);
        $this->assertFigure(15.0, $lines[1]->unit_cost);
    }

    /* -------------------------------- receiving ----------------------------- */

    public function test_a_delivery_keeps_the_price_it_was_bought_at(): void
    {
        $item = $this->item(['unit_price' => 9.00]);

        StockLedger::receive($item, 5, 11.50);
        StockLedger::receive($item->fresh(), 5, 12.50);

        $costs = StockLot::where('item_id', $item->id)->orderBy('id')->pluck('unit_cost')
            ->map(fn ($c) => (float) $c)->all();

        $this->assertSame([11.5, 12.5], $costs, 'each lot remembers its own price');
    }

    /** No price given, so the item's standing price is the best guess there is. */
    public function test_a_delivery_with_no_price_falls_back_to_the_items_own(): void
    {
        $item = $this->item(['unit_price' => 7.25]);

        $lot = StockLedger::receive($item, 4);

        $this->assertFigure(7.25, $lot->unit_cost);
    }

    /**
     * An item that says how long it keeps dates its own batches — and only the
     * date somebody actually read off the packet is written down.
     */
    public function test_shelf_life_sets_the_expiry_when_the_delivery_does_not(): void
    {
        $item = $this->item(['shelf_life_days' => 30]);

        $dated = StockLedger::receive($item, 4, 1.00, '2026-03-15', null, '2026-03-01');
        $this->assertSame('2026-03-15', $dated->expires_on->toDateString(), 'what the delivery said is kept');
        $this->assertSame('2026-03-15', $dated->expiry(30)->toDateString(), 'and it wins over the shelf life');
        $this->assertFalse($dated->expiryIsEstimated());

        $derived = StockLedger::receive($item->fresh(), 4, 1.00, null, null, '2026-03-01');
        $this->assertNull($derived->expires_on, 'nothing was read off a packet, so nothing is stored');
        $this->assertSame('2026-03-31', $derived->expiry(30)->toDateString(), 'the shelf life answers instead');
        $this->assertTrue($derived->expiryIsEstimated(), 'and the board can say it is a guess');
    }

    /**
     * Shelf life set AFTER the food came aboard still dates it.
     *
     * The owner's case, and the reason this is worked out when the date is read
     * rather than stamped on at delivery: he received apples, then set their
     * shelf life to 15 days, and the batch stayed blank — because the stamp had
     * already been taken on a day when the item had no shelf life. A shelf life
     * is a statement about how long the food keeps. It has to describe the food
     * already on the shelf, not only the next delivery.
     */
    public function test_a_shelf_life_set_later_dates_the_stock_already_aboard(): void
    {
        $item = $this->item(['shelf_life_days' => null]);

        $lot = StockLedger::receive($item, 10, 90.00, null, null, '2026-11-19');
        $this->assertNull($lot->expiry(null), 'nothing known yet');

        $item->update(['shelf_life_days' => 15]);

        $this->assertSame('2026-12-04', $lot->fresh()->expiry(15)->toDateString());

        // And moving the rule moves every batch it describes.
        $item->update(['shelf_life_days' => 20]);
        $this->assertSame('2026-12-09', $lot->fresh()->expiry(20)->toDateString());
    }

    /**
     * Stock that was never booked in gets no guessed expiry.
     *
     * Its received date means "when this ship started keeping records", not
     * when the food arrived, so counting a shelf life from it invents a warning
     * the crew would act on.
     */
    public function test_an_opening_batch_is_never_given_a_guessed_expiry(): void
    {
        $item = $this->item(['shelf_life_days' => 30]);

        $opening = StockLedger::receive(
            item: $item,
            quantity: 6,
            unitCost: 1.00,
            receivedOn: '2026-03-01',
            source: StockLot::FROM_OPENING,
        );

        $this->assertNull($opening->expiry(30), 'no date, and none invented');
    }

    public function test_an_item_that_keeps_indefinitely_has_no_expiry(): void
    {
        $lot = StockLedger::receive($this->item(['shelf_life_days' => null]), 4, 1.00);

        $this->assertNull($lot->expires_on);
    }

    /* ------------------------------ withdrawing ----------------------------- */

    /** A batch past its date is thrown away, out of that batch, not FIFO. */
    public function test_a_named_batch_can_be_written_off_on_its_own(): void
    {
        $item = $this->item();

        $old = StockLedger::receive($item, 10, 10.00, '2026-01-31', null, '2026-01-01');
        StockLedger::receive($item->fresh(), 10, 20.00, '2026-12-31', null, '2026-02-01');

        $written = StockLedger::withdraw($item->fresh(), 10, [
            'lot_id' => $old->id,
            'reason' => Withdrawal::WASTE,
            'date' => '2026-02-02',
        ]);

        $this->assertFigure(100.0, $written->cost, 'charged at what the spoiled batch cost');
        $this->assertFigure(0.0, $old->fresh()->remaining);
        $this->assertFigure(10.0, $item->fresh()->current_stock);
        $this->assertFigure(200.0, StockLedger::value($item), 'the good batch is untouched');
    }

    /**
     * The day lots are switched on, the ship has stock and no history of it.
     *
     * Refusing the cook's withdrawal would stop him working over a bookkeeping
     * detail, so the shortfall is opened as a lot at the item's standing price
     * and immediately spent — and it says on its face that nobody booked it in.
     */
    public function test_stock_that_was_never_booked_in_can_still_be_withdrawn(): void
    {
        $item = $this->item(['unit_price' => 6.00, 'current_stock' => 25]);

        $withdrawal = StockLedger::withdraw($item, 10);

        $this->assertFigure(60.0, $withdrawal->cost, '10 at the standing price');
        $this->assertFigure(15.0, $item->fresh()->current_stock);

        $lot = StockLot::where('item_id', $item->id)->first();
        $this->assertSame(StockLot::FROM_OPENING, $lot->source);
        $this->assertStringContainsString('before any delivery', $lot->note);
    }

    /**
     * Partly on the books, partly not: the booked stock goes first, and the
     * rest is priced at the last thing actually paid rather than the standing
     * price.
     *
     * A price somebody paid last week is better evidence than a reference
     * figure that may not have been looked at in a year. The standing price is
     * the fallback for an item with no lots at all, which is the case the day
     * this is switched on.
     */
    public function test_unbooked_stock_is_priced_at_the_last_real_cost(): void
    {
        $item = $this->item(['unit_price' => 5.00]);   // stale office figure

        StockLedger::receive($item, 4, 2.00);         // what it really cost

        $withdrawal = StockLedger::withdraw($item->fresh(), 10);

        // 4 out of the lot at $2, and 6 nobody booked in — also at $2.
        $this->assertFigure(20.0, $withdrawal->cost);
        $this->assertCount(2, $withdrawal->lots);
    }

    public function test_nothing_is_recorded_for_a_withdrawal_of_nothing(): void
    {
        $item = $this->item();
        StockLedger::receive($item, 10, 1.00);

        $this->assertNull(StockLedger::withdraw($item->fresh(), 0));
        $this->assertSame(0, Withdrawal::count());
    }

    /* -------------------------------- undoing ------------------------------- */

    /** A mistyped withdrawal goes back onto the exact batches it came out of. */
    public function test_undoing_a_withdrawal_puts_the_stock_back_where_it_was(): void
    {
        $item = $this->item();

        $cheap = StockLedger::receive($item, 10, 10.00, null, null, '2026-01-01');
        $dear = StockLedger::receive($item->fresh(), 10, 20.00, null, null, '2026-02-01');

        $withdrawal = StockLedger::withdraw($item->fresh(), 15);
        $this->assertFigure(200.0, $withdrawal->cost, '10 at $10 and 5 at $20');

        StockLedger::undo($withdrawal);

        $this->assertFigure(10.0, $cheap->fresh()->remaining);
        $this->assertFigure(10.0, $dear->fresh()->remaining);
        $this->assertFigure(20.0, $item->fresh()->current_stock);
        $this->assertFigure(300.0, StockLedger::value($item));
        $this->assertSame(0, Withdrawal::count());
    }

    /** Undoing twice must not invent stock the lot never held. */
    public function test_a_lot_never_holds_more_than_it_was_delivered_with(): void
    {
        $item = $this->item();
        $lot = StockLedger::receive($item, 10, 10.00);

        $withdrawal = StockLedger::withdraw($item->fresh(), 4);
        StockLedger::undo($withdrawal);

        $this->assertFigure(10.0, $lot->fresh()->remaining);
        $this->assertFigure(10.0, $item->fresh()->current_stock);
    }

    /* ----------------------------- the stocktake ---------------------------- */

    /** The shelf is real and the paperwork is not: the count wins. */
    public function test_a_count_that_is_short_books_the_difference_at_fifo_cost(): void
    {
        $item = $this->item();

        StockLedger::receive($item, 10, 10.00, null, null, '2026-01-01');
        StockLedger::receive($item->fresh(), 10, 20.00, null, null, '2026-02-01');

        // Nobody logged a thing all month; the shelf says 6.
        $booked = StockLedger::reconcile($item->fresh(), 6, ['date' => '2026-02-28']);

        $this->assertInstanceOf(Withdrawal::class, $booked);
        $this->assertFigure(14.0, $booked->quantity);
        $this->assertFigure(180.0, $booked->cost, '10 at $10 and 4 at $20');
        $this->assertFigure(6.0, $item->fresh()->current_stock);
        $this->assertFigure(6.0, StockLedger::onHand($item), 'the lots agree with the count');
        $this->assertFigure(120.0, StockLedger::value($item));
    }

    /** More on the shelf than the books knew about. */
    public function test_a_count_that_is_over_books_the_surplus_at_the_newest_price(): void
    {
        $item = $this->item();
        StockLedger::receive($item, 10, 12.00);

        $booked = StockLedger::reconcile($item->fresh(), 14);

        $this->assertInstanceOf(StockLot::class, $booked);
        $this->assertSame(StockLot::FROM_ADJUSTMENT, $booked->source);
        $this->assertFigure(12.0, $booked->unit_cost);
        $this->assertFigure(14.0, StockLedger::onHand($item));
        $this->assertFigure(168.0, StockLedger::value($item));
    }

    public function test_a_count_that_agrees_books_nothing(): void
    {
        $item = $this->item();
        StockLedger::receive($item, 10, 12.00);

        $this->assertNull(StockLedger::reconcile($item->fresh(), 10));
        $this->assertSame(0, Withdrawal::count());
    }

    /**
     * The count is set, not nudged.
     *
     * Arriving at the figure by adding a difference is how a rounding error
     * becomes permanent; the counted number is simply what it is.
     */
    public function test_the_counted_figure_is_what_is_stored(): void
    {
        $item = $this->item(['current_stock' => 99]);
        StockLedger::receive($item->fresh(), 10, 1.00);

        StockLedger::reconcile($item->fresh(), 7.5);

        $this->assertFigure(7.5, $item->fresh()->current_stock);
        $this->assertFigure(7.5, StockLedger::onHand($item));
    }

    /* ------------------------------ valuation ------------------------------- */

    public function test_the_average_cost_is_for_looking_at_not_for_costing_with(): void
    {
        $item = $this->item();
        StockLedger::receive($item, 10, 10.00, null, null, '2026-01-01');
        StockLedger::receive($item->fresh(), 30, 15.00, null, null, '2026-02-01');

        // 550 over 40.
        $this->assertFigure(13.75, StockLedger::averageCost($item));

        // ... and a withdrawal does not use it.
        $this->assertFigure(100.0, StockLedger::withdraw($item->fresh(), 10)->cost);
    }

    public function test_an_empty_store_is_worth_nothing_and_has_no_average(): void
    {
        $item = $this->item();

        $this->assertFigure(0.0, StockLedger::value($item));
        $this->assertNull(StockLedger::averageCost($item));
    }

    /* ------------------------------- expiry --------------------------------- */

    public function test_a_lot_knows_how_long_it_has_left(): void
    {
        $item = $this->item();

        $gone = StockLedger::receive($item, 5, 1.00, now()->subDays(3)->toDateString());
        $soon = StockLedger::receive($item->fresh(), 5, 1.00, now()->addDays(5)->toDateString());
        $open = StockLedger::receive($item->fresh(), 5, 1.00, null);

        $this->assertSame(-3, $gone->daysLeft());
        $this->assertTrue($gone->hasExpired());

        $this->assertSame(5, $soon->daysLeft());
        $this->assertFalse($soon->hasExpired());

        $this->assertNull($open->daysLeft());
        $this->assertFalse($open->hasExpired());
    }

    /* ------------------------- quantity in fractions ------------------------ */

    /**
     * Three decimals is the unit of truth, and a spent lot has to stay spent.
     *
     * Without rounding to the stored precision, a remainder of 1e-15 keeps an
     * empty lot in the FIFO scan forever and the next one is never reached.
     */
    public function test_fractional_quantities_do_not_leave_a_lot_half_alive(): void
    {
        $item = $this->item();

        StockLedger::receive($item, 0.333, 3.00, null, null, '2026-01-01');
        StockLedger::receive($item->fresh(), 0.667, 6.00, null, null, '2026-02-01');

        $withdrawal = StockLedger::withdraw($item->fresh(), 1.0);

        $this->assertFigure(5.001, $withdrawal->cost, '0.333 at $3 plus 0.667 at $6');
        $this->assertFigure(0.0, StockLedger::onHand($item));
        $this->assertFigure(0.0, $item->fresh()->current_stock);
        $this->assertSame(0, StockLot::where('item_id', $item->id)->where('remaining', '>', 0)->count());
    }
}

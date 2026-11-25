<?php

namespace Tests\Feature;

use App\Models\Inventory\CrewRation;
use App\Models\Inventory\Vessel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\NamesItsVessel;
use Tests\TestCase;

/**
 * The crew ration sheet and the report the owner is invoiced from.
 *
 * The vessel keeps one row a day; everything else is worked out from those
 * counts, so these tests are mostly about what the sheet refuses to store â€”
 * totals it could have cached, and rows for days nobody has counted yet.
 */
class CrewRationsTest extends TestCase
{
    use NamesItsVessel, RefreshDatabase;

    private Vessel $vessel;

    /** A full crew and three technicians aboard for all three sittings. */
    private const A_DAY = [
        'breakfast_crew' => 25, 'lunch_crew' => 25, 'dinner_crew' => 25,
        'breakfast_visitors' => 3, 'lunch_visitors' => 3, 'dinner_visitors' => 3,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('admin', 'web');
        $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $user->assignRole('admin');
        $this->grantAllPages($user);
        Sanctum::actingAs($user);

        $this->vessel = Vessel::current();
        $this->vessel->update([
            'crew_size' => 25,
            'ration_rate' => 9.30,
            'visitor_meal_rate' => 3.10,
            'charterer_meal_rate' => 0,
        ]);
    }

    private function save(array $days)
    {
        return $this->postJson($this->url('/crew-rations'), ['days' => $days]);
    }

    /** JSON draws no line between 23.0 and 23, so compare the value not the type. */
    private function assertFigure(float $expected, $actual, string $message = ''): void
    {
        $this->assertSame($expected, (float) $actual, $message);
    }

    public function test_the_sheet_returns_every_day_of_the_month(): void
    {
        $data = $this->getJson($this->url('/crew-rations?month=2026-06'))
            ->assertOk()->json('data');

        $this->assertSame('June 2026', $data['label']);
        $this->assertCount(30, $data['days'], 'June is 30 days and the sheet should show all of them');
        $this->assertSame('2026-06-01', $data['days'][0]['date']);
        $this->assertSame('2026-06-30', $data['days'][29]['date']);

        $this->assertFalse($data['days'][0]['saved']);
        $this->assertSame(0, $data['days'][0]['total_meals']);
    }

    public function test_a_days_counts_are_saved_and_read_back_with_their_totals(): void
    {
        $this->save([['date' => '2026-06-01', 'port' => 'Singapore', 'location' => 'Port'] + self::A_DAY])
            ->assertOk()->assertJsonPath('message', '1 day saved.');

        $day = collect($this->getJson($this->url('/crew-rations?month=2026-06'))->json('data.days'))
            ->firstWhere('date', '2026-06-01');

        $this->assertSame('Singapore', $day['port']);
        $this->assertSame('Port', $day['location']);
        $this->assertTrue($day['saved']);
        $this->assertSame(75, $day['crew_meals']);
        $this->assertSame(9, $day['visitor_meals']);
        $this->assertFigure(28.0, $day['total_rations']);
        $this->assertFigure(260.40, $day['amount'], '25 rations at 9.30 plus 9 meals at 3.10');
    }

    /**
     * No total is ever written down.
     *
     * The same reason the ordering figures are not stored: a saved total is a
     * total that can end up disagreeing with the counts under it.
     */
    public function test_only_the_counts_are_stored(): void
    {
        $this->save([['date' => '2026-06-01'] + self::A_DAY])->assertOk();

        $stored = array_keys(CrewRation::firstOrFail()->getAttributes());

        foreach (['crew_meals', 'crew_rations', 'total_meals', 'total_rations', 'amount'] as $derived) {
            $this->assertNotContains($derived, $stored, "{$derived} is being stored instead of worked out");
        }
    }

    /** Change a rate and every figure in the history follows. */
    public function test_changing_a_rate_restates_the_charge(): void
    {
        $this->save([['date' => '2026-06-01'] + self::A_DAY])->assertOk();

        $this->vessel->update(['ration_rate' => 12.00]);

        $day = collect($this->getJson($this->url('/crew-rations?month=2026-06'))->json('data.days'))
            ->firstWhere('date', '2026-06-01');

        $this->assertFigure(28.0, $day['total_rations'], 'the counts should not have moved');
        $this->assertFigure(327.90, $day['amount'], '25 x 12.00 plus 9 x 3.10');
    }

    /**
     * A day emptied back to zero is removed, not kept as a row of noughts.
     *
     * "Nobody ate" and "nobody has counted yet" are different facts, and only
     * the absence of a row can say the second one.
     */
    public function test_emptying_a_day_clears_it(): void
    {
        $this->save([['date' => '2026-06-01'] + self::A_DAY])->assertOk();
        $this->assertSame(1, CrewRation::count());

        $this->save([['date' => '2026-06-01', 'breakfast_crew' => 0, 'lunch_crew' => 0, 'dinner_crew' => 0]])
            ->assertOk()->assertJsonPath('message', '0 days saved. 1 day cleared.');

        $this->assertSame(0, CrewRation::count());
    }

    /** Saving the same date again edits that day rather than adding another. */
    public function test_a_day_is_counted_once(): void
    {
        $this->save([['date' => '2026-06-01'] + self::A_DAY])->assertOk();
        $this->save([['date' => '2026-06-01', 'breakfast_crew' => 18, 'lunch_crew' => 18, 'dinner_crew' => 18]])->assertOk();

        $this->assertSame(1, CrewRation::count());
        $this->assertSame(18, CrewRation::firstOrFail()->breakfast_crew);
        $this->assertSame(0, CrewRation::firstOrFail()->breakfast_visitors, 'the first save should not linger');
    }

    public function test_the_month_total_is_the_sum_of_its_days(): void
    {
        $this->save([
            ['date' => '2026-06-01'] + self::A_DAY,
            ['date' => '2026-06-02'] + self::A_DAY,
            ['date' => '2026-06-03'] + self::A_DAY,
        ])->assertOk();

        $totals = $this->getJson($this->url('/crew-rations?month=2026-06'))->json('data.totals');

        $this->assertSame(3, $totals['days_recorded'], 'the 27 blank days should not count');
        $this->assertSame(225, $totals['crew_meals']);
        $this->assertFigure(84.0, $totals['total_rations']);
        $this->assertFigure(781.20, $totals['amount']);
        $this->assertFigure(28.0, $totals['average_rations_per_day']);
    }

    public function test_the_report_gives_a_line_per_month(): void
    {
        $this->save([
            ['date' => '2026-06-01'] + self::A_DAY,
            ['date' => '2026-07-01'] + self::A_DAY,
        ])->assertOk();

        $data = $this->getJson($this->url('/crew-rations/report?year=2026'))->assertOk()->json('data');

        $this->assertCount(12, $data['months'], 'a year has twelve lines whether or not they were fed');

        $byMonth = collect($data['months'])->keyBy('month');
        $this->assertFigure(260.40, $byMonth['2026-06']['amount']);
        $this->assertFigure(260.40, $byMonth['2026-07']['amount']);
        $this->assertFigure(0.0, $byMonth['2026-08']['amount']);
        $this->assertFigure(520.80, $data['totals']['amount']);
    }

    /* ------------------------- the ration report ------------------------- */

    /**
     * A period runs from one supply to the next, not month end to month end.
     *
     * The invoice this replaces covered 06.09 to 30.09, which no calendar
     * month boundary would have produced.
     */
    public function test_the_report_covers_an_arbitrary_period(): void
    {
        $this->save([
            ['date' => '2026-09-05'] + self::A_DAY,   // before the period
            ['date' => '2026-09-06'] + self::A_DAY,
            ['date' => '2026-09-07'] + self::A_DAY,
            ['date' => '2026-10-01'] + self::A_DAY,   // after it
        ])->assertOk();

        $data = $this->getJson($this->url('/crew-rations/invoice?from=2026-09-06&to=2026-09-30'))
            ->assertOk()->json('data');

        $this->assertSame(25, $data['days_in_period']);
        $this->assertSame(2, $data['totals']['days_recorded'], 'only the days inside the period');
        $this->assertFigure(520.80, $data['totals']['amount']);
    }

    /** The invoice shows the days that happened, not the ones that did not. */
    public function test_the_report_leaves_out_days_nobody_recorded(): void
    {
        $this->save([['date' => '2026-09-10'] + self::A_DAY])->assertOk();

        $data = $this->getJson($this->url('/crew-rations/invoice?from=2026-09-01&to=2026-09-30'))
            ->assertOk()->json('data');

        $this->assertCount(1, $data['days'], 'an invoice is not a blank calendar');
        $this->assertSame('2026-09-10', $data['days'][0]['date']);
    }

    /** A period typed backwards is a typo, not a request to invoice nothing. */
    public function test_a_backwards_period_is_read_the_right_way_round(): void
    {
        $this->save([['date' => '2026-09-10'] + self::A_DAY])->assertOk();

        $data = $this->getJson($this->url('/crew-rations/invoice?from=2026-09-30&to=2026-09-01'))
            ->assertOk()->json('data');

        $this->assertSame('2026-09-01', $data['from']);
        $this->assertSame('2026-09-30', $data['to']);
        $this->assertSame(1, $data['totals']['days_recorded']);
    }

    /** Four lines, whatever was eaten â€” a zero against a category is a fact. */
    public function test_the_report_carries_a_charge_line_for_every_category(): void
    {
        $this->save([['date' => '2026-09-10'] + self::A_DAY])->assertOk();

        $charges = collect(
            $this->getJson($this->url('/crew-rations/invoice?from=2026-09-01&to=2026-09-30'))
                ->json('data.totals.charges')
        )->keyBy('category');

        $this->assertCount(4, $charges);
        $this->assertSame('Rations', $charges['crew']['unit']);
        $this->assertFigure(232.50, $charges['crew']['amount']);
        $this->assertSame('Meals', $charges['visitor']['unit']);
        $this->assertFigure(27.90, $charges['visitor']['amount']);
        $this->assertFigure(0.0, $charges['charterer']['quantity']);
    }

    public function test_the_report_downloads_as_a_pdf(): void
    {
        $this->save([['date' => '2026-09-10', 'location' => 'Port', 'port' => 'Singapore'] + self::A_DAY])->assertOk();

        $pdf = $this->get($this->url('/crew-rations/invoice/pdf?from=2026-09-01&to=2026-09-30'))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->getContent();

        $this->assertStringStartsWith('%PDF', $pdf);
    }

    /** A period nobody fed anyone in still prints, rather than erroring. */
    public function test_an_empty_period_still_produces_a_report(): void
    {
        $this->get($this->url('/crew-rations/invoice/pdf?from=2026-09-01&to=2026-09-30'))->assertOk();

        $data = $this->getJson($this->url('/crew-rations/invoice?from=2026-09-01&to=2026-09-30'))
            ->assertOk()->json('data');

        $this->assertSame(0, $data['totals']['days_recorded']);
        $this->assertFigure(0.0, $data['totals']['amount']);
    }

    /* ---------------------------- the basics ---------------------------- */

    public function test_a_nonsense_month_does_not_break_the_sheet(): void
    {
        $this->getJson($this->url('/crew-rations?month=not-a-month'))
            ->assertOk()
            ->assertJsonPath('data.month', now()->format('Y-m'));
    }

    public function test_counts_are_validated(): void
    {
        $this->save([['date' => '2026-06-01', 'breakfast_crew' => -1]])
            ->assertStatus(422)->assertJsonValidationErrors('days.0.breakfast_crew');

        $this->save([['date' => 'the first', 'breakfast_crew' => 1]])
            ->assertStatus(422)->assertJsonValidationErrors('days.0.date');

        $this->save([['date' => '2026-06-01', 'location' => 'Orbit']])
            ->assertStatus(422)->assertJsonValidationErrors('days.0.location');
    }

    public function test_the_sheet_needs_a_login(): void
    {
        app('auth')->forgetGuards();

        $this->getJson($this->url('/crew-rations'))->assertUnauthorized();
    }
}

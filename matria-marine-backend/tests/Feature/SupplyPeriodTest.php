<?php

namespace Tests\Feature;

use App\Models\Inventory\Item;
use App\Models\Inventory\SupplyPeriod;
use App\Models\Inventory\Vessel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\NamesItsVessel;
use Tests\TestCase;

/**
 * Supply periods â€” the stocktake that makes consumption measurable.
 *
 * Closing one records the counted ending stock and opens the next, so the
 * vessel is never without a period collecting its deliveries. The dates have
 * to stay in order through that handover, which is what these cover.
 */
class SupplyPeriodTest extends TestCase
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
        $category = \App\Models\Inventory\Category::create(['name' => 'Vegetables fresh', 'sort' => 1]);
        Item::create([
            'vessel_id' => $this->vessel->id,
            'category_id' => $category->id,
            'code' => '1001001', 'name' => 'Asparagus, Fresh', 'unit' => 'Kg',
            'current_stock' => 10, 'is_active' => true,
        ]);
    }

    private function open(): SupplyPeriod
    {
        $this->postJson($this->url('/periods'), [])->assertCreated();

        return SupplyPeriod::open($this->vessel->id);
    }

    /**
     * A period never ends before it began.
     *
     * Closing one opens the next on the following day. Close that one the same
     * afternoon and the end date lands a day behind the start â€” which is what
     * the vessel's list showed: "20 Oct 2026 â†’ 19 Oct 2026, 1 day".
     */
    public function test_a_period_closed_the_day_it_opened_does_not_end_before_it_started(): void
    {
        $first = $this->open();
        $this->postJson($this->url("/periods/{$first->id}/close"), [])->assertOk();

        // The second period starts tomorrow; close it today anyway.
        $second = SupplyPeriod::open($this->vessel->id);
        $this->assertTrue($second->start_date->isTomorrow(), 'the next period should start the day after the close');

        $this->postJson($this->url("/periods/{$second->id}/close"), [])->assertOk();

        $second->refresh();
        $this->assertTrue(
            $second->end_date->gte($second->start_date),
            "period ended {$second->end_date->toDateString()} having started {$second->start_date->toDateString()}"
        );
        $this->assertGreaterThanOrEqual(1, $second->days);
    }

    /** Every closed period reads forwards, however many were closed in a day. */
    public function test_no_period_ever_reads_backwards(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $period = SupplyPeriod::open($this->vessel->id) ?? $this->open();
            $this->postJson($this->url("/periods/{$period->id}/close"), [])->assertOk();
        }

        foreach (SupplyPeriod::whereNotNull('end_date')->get() as $period) {
            $this->assertTrue(
                $period->end_date->gte($period->start_date),
                "period #{$period->id} ends before it starts"
            );
        }
    }

    /** Closing hands straight over, so deliveries always have somewhere to go. */
    public function test_closing_opens_the_next_period(): void
    {
        $first = $this->open();

        $this->postJson($this->url("/periods/{$first->id}/close"), [])
            ->assertOk()
            ->assertJsonPath('data.period.status', 'closed');

        $next = SupplyPeriod::open($this->vessel->id);
        $this->assertNotNull($next, 'the vessel was left with no open period');
        $this->assertNotSame($first->id, $next->id);
    }

    /** Unless the office says otherwise. */
    public function test_the_handover_can_be_declined(): void
    {
        $first = $this->open();

        $this->postJson($this->url("/periods/{$first->id}/close"), ['open_next' => false])->assertOk();

        $this->assertNull(SupplyPeriod::open($this->vessel->id));
    }

    /** The counted stock becomes what is on board. */
    public function test_the_count_becomes_the_stock(): void
    {
        $period = $this->open();
        $item = Item::firstOrFail();

        $this->postJson($this->url("/periods/{$period->id}/close"), [
            'lines' => [['item_id' => $item->id, 'ending_qty' => 4]],
        ])->assertOk();

        $this->assertSame(4.0, (float) $item->fresh()->current_stock);
    }

    /** History is not editable. */
    public function test_a_closed_period_cannot_be_deleted(): void
    {
        $period = $this->open();
        $this->postJson($this->url("/periods/{$period->id}/close"), [])->assertOk();

        $this->deleteJson($this->url("/periods/{$period->id}"))->assertStatus(422);
    }

    public function test_only_one_period_is_open_at_a_time(): void
    {
        $this->open();

        $this->postJson($this->url('/periods'), [])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }
}

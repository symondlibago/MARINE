<?php

namespace Tests\Feature;

use App\Models\Inventory\Category;
use App\Models\Inventory\Item;
use App\Models\Inventory\Receipt;
use App\Models\Inventory\Requisition;
use App\Models\Inventory\Vessel;
use App\Models\User;
use App\Support\Inventory\BudgetCheck;
use App\Support\Inventory\VesselAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The order's journey: the galley writes it, the master signs it, the office
 * buys it.
 *
 * Three people, three screens, and the point of the chain is that they are not
 * the same person. A cook who could approve his own order is a cook with a
 * budget, which is not how a ship is run — and a master who never sees the
 * order cannot be answerable for what she spends.
 *
 * The budget is a warning throughout, never a refusal. The owner was explicit
 * about that, and he was right: there are good reasons to go over, and the only
 * unacceptable outcome is finding out afterwards.
 */
class RequisitionApprovalTest extends TestCase
{
    use RefreshDatabase;

    private Vessel $vessel;
    private User $cook;
    private User $master;
    private User $office;

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

        $this->cook = $this->account(VesselAccess::COOK, 'varun-cook');
        $this->master = $this->account(VesselAccess::MASTER, 'varun-master');

        $this->office = User::factory()->create([
            'name' => 'Matria Office', 'username' => 'office', 'role' => 'admin', 'is_active' => true,
        ]);
        $this->office->assignRole('admin');
        $this->grantAllPages($this->office);
    }

    private function account(string $role, string $username): User
    {
        $user = User::factory()->create([
            'name' => VesselAccess::RANKS[$role],
            'username' => $username,
            'role' => $role,
            'vessel_id' => $this->vessel->id,
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function as(User $user): self
    {
        Sanctum::actingAs($user);

        return $this;
    }

    private function url(string $path): string
    {
        $separator = str_contains($path, '?') ? '&' : '?';

        return '/api/inventory'.$path.$separator.'vessel_id='.$this->vessel->id;
    }

    /**
     * An item whose usage makes it cost $perDay a day to feed the crew, so an
     * order's value can be aimed at a figure the budget test can reason about.
     */
    private function item(float $perDayValue): Item
    {
        static $code = 6001000;

        return Item::create([
            'vessel_id' => $this->vessel->id,
            'category_id' => Category::firstOrCreate(['name' => 'Meat frozen'], ['sort' => 1])->id,
            'code' => (string) ++$code,
            'name' => 'Beef Tenderloin',
            'unit' => 'Kg',
            'unit_price' => 1.00,
            'current_stock' => 0,
            // 1 unit a day at $1 — so N days of supply costs $N.
            'baseline_consumption' => 30,
            'baseline_period_days' => 30,
            'is_active' => true,
        ]);
    }

    private function draft(array $payload = []): Requisition
    {
        $item = $this->item(1.0);

        $this->as($this->cook)
            ->postJson($this->url('/requisitions'), $payload + [
                'lines' => [['item_id' => $item->id, 'order_qty' => 100]],
            ])
            ->assertCreated();

        return Requisition::latest('id')->first();
    }

    private function assertFigure(float $expected, $actual, string $message = ''): void
    {
        $this->assertSame($expected, round((float) $actual, 2), $message);
    }

    /* ------------------------------ the journey ----------------------------- */

    public function test_an_order_goes_from_the_galley_to_the_master_to_the_office(): void
    {
        $req = $this->draft();
        $this->assertSame(Requisition::DRAFT, $req->status);

        // The office cannot see it yet: it has not been approved on board.
        $this->as($this->office)->getJson('/api/inventory/requisitions/inbox')
            ->assertOk()
            ->assertJsonCount(0, 'data.requisitions');

        $this->as($this->cook)->postJson($this->url("/requisitions/{$req->id}/submit"))
            ->assertOk()
            ->assertSee('sent to the master', false);
        $this->assertSame(Requisition::PENDING, $req->fresh()->status);

        $this->as($this->master)->postJson($this->url("/requisitions/{$req->id}/approve"))
            ->assertOk()
            ->assertSee('the office can see it now', false);

        $approved = $req->fresh();
        $this->assertSame(Requisition::APPROVED, $approved->status);
        $this->assertSame($this->master->id, $approved->approved_by);
        $this->assertNotNull($approved->approved_at);

        // And now it is on the office's list, with the ship's name on it.
        $this->as($this->office)->getJson('/api/inventory/requisitions/inbox')
            ->assertOk()
            ->assertJsonCount(1, 'data.requisitions')
            ->assertJsonPath('data.requisitions.0.vessel.name', 'OEL VARUN')
            ->assertJsonPath('data.requisitions.0.approved_by', 'Master');
    }

    /** The whole reason there are two accounts on a ship. */
    public function test_a_cook_cannot_approve_his_own_order(): void
    {
        $req = $this->draft();
        $this->as($this->cook)->postJson($this->url("/requisitions/{$req->id}/submit"))->assertOk();

        $this->as($this->cook)->postJson($this->url("/requisitions/{$req->id}/approve"))
            ->assertForbidden()
            ->assertSee('Only the master', false);

        $this->assertSame(Requisition::PENDING, $req->fresh()->status);
    }

    /** Sent back with a reason, and the cook can work on it again. */
    public function test_the_master_can_send_an_order_back(): void
    {
        $req = $this->draft();
        $this->as($this->cook)->postJson($this->url("/requisitions/{$req->id}/submit"))->assertOk();

        $this->as($this->master)->postJson($this->url("/requisitions/{$req->id}/decline"), [
            'reason' => 'Too much beef — we still have the last lot.',
        ])->assertOk()->assertSee('sent back to the galley', false);

        $sent = $req->fresh();
        $this->assertSame(Requisition::DECLINED, $sent->status);
        $this->assertSame('Too much beef — we still have the last lot.', $sent->decline_reason);
        $this->assertTrue($sent->isEditable(), 'the cook can change it again');

        // Resubmitting clears the old reason: the master should not be reading
        // last week's objection to this week's order.
        $this->as($this->cook)->postJson($this->url("/requisitions/{$req->id}/submit"))->assertOk();
        $this->assertNull($req->fresh()->decline_reason);
    }

    /** "Declined" on its own tells the cook to guess. */
    public function test_sending_an_order_back_needs_a_reason(): void
    {
        $req = $this->draft();
        $this->as($this->cook)->postJson($this->url("/requisitions/{$req->id}/submit"))->assertOk();

        $this->as($this->master)->postJson($this->url("/requisitions/{$req->id}/decline"), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->assertSame(Requisition::PENDING, $req->fresh()->status);
    }

    /** An order with the master is out of the cook's hands until it comes back. */
    public function test_an_order_awaiting_approval_cannot_be_deleted(): void
    {
        $req = $this->draft();
        $this->as($this->cook)->postJson($this->url("/requisitions/{$req->id}/submit"))->assertOk();

        $this->as($this->cook)->deleteJson($this->url("/requisitions/{$req->id}"))
            ->assertStatus(422)
            ->assertSee('with the master', false);

        $this->assertNotNull(Requisition::find($req->id));
    }

    public function test_an_approved_order_cannot_be_sent_up_again(): void
    {
        $req = $this->draft();
        $this->as($this->cook)->postJson($this->url("/requisitions/{$req->id}/submit"))->assertOk();
        $this->as($this->master)->postJson($this->url("/requisitions/{$req->id}/approve"))->assertOk();

        $this->as($this->cook)->postJson($this->url("/requisitions/{$req->id}/submit"))
            ->assertStatus(422);
        $this->as($this->master)->postJson($this->url("/requisitions/{$req->id}/approve"))
            ->assertStatus(422)
            ->assertSee('already approved', false);
    }

    public function test_an_empty_order_is_not_worth_the_masters_time(): void
    {
        $req = $this->draft();
        $req->items()->delete();

        $this->as($this->cook)->postJson($this->url("/requisitions/{$req->id}/submit"))
            ->assertStatus(422)
            ->assertSee('nothing on this order', false);
    }

    /* -------------------------- reading an order --------------------------- */

    /**
     * Everyone in the chain can read what is on an order before acting on it.
     *
     * The endpoint existed and no screen called it, so the master was asked to
     * approve a number of lines rather than a list of them, and the cook could
     * not check what he had sent up. Approving something you cannot read is not
     * approving it.
     */
    public function test_the_whole_chain_can_read_the_lines_on_an_order(): void
    {
        $req = $this->draft();

        foreach ([$this->cook, $this->master] as $reader) {
            $lines = $this->as($reader)
                ->getJson($this->url("/requisitions/{$req->id}"))
                ->assertOk()
                ->json('data.requisition.items');

            $this->assertCount(1, $lines);
            $this->assertSame('Beef Tenderloin', $lines[0]['description']);
            $this->assertFigure(100.0, $lines[0]['order_qty']);
        }
    }

    /**
     * The office reads it without naming a vessel, because its own list spans
     * the fleet and is not inside one. The vessel comes off the requisition.
     */
    public function test_the_office_reads_an_order_without_naming_the_vessel(): void
    {
        $req = $this->draft();

        $this->as($this->office)
            ->getJson("/api/inventory/requisitions/{$req->id}")
            ->assertOk()
            ->assertJsonPath('data.requisition.vessel.name', 'OEL VARUN')
            ->assertJsonCount(1, 'data.requisition.items');
    }

    /** ...but a crew account still cannot read another ship's order. */
    public function test_another_vessels_order_stays_out_of_reach(): void
    {
        $other = Vessel::create([
            'name' => 'MV SOUTHERN CROSS', 'crew_size' => 18, 'supply_period_days' => 30,
            'safety_margin_pct' => 0, 'currency' => 'USD', 'exchange_rate' => 1, 'is_active' => true,
        ]);

        $req = $this->draft();
        $req->update(['vessel_id' => $other->id]);

        $this->as($this->cook)
            ->getJson("/api/inventory/requisitions/{$req->id}")
            ->assertForbidden();
    }

    /* ------------------------- knowing it is coming ------------------------ */

    /**
     * The ship is told what the office has bought for her.
     *
     * Until now nothing was: the order left the galley, was approved, was
     * bought, and the next the cook knew of it was a pallet on the quay. The
     * receiving sheet could always be pre-filled from an order; there was just
     * nothing saying an order existed to fill it from.
     */
    public function test_an_ordered_requisition_shows_up_as_expected(): void
    {
        $req = $this->draft();
        $this->as($this->cook)->postJson($this->url("/requisitions/{$req->id}/submit"))->assertOk();
        $this->as($this->master)->postJson($this->url("/requisitions/{$req->id}/approve"))->assertOk();

        // Approved is the ship waiting on the office, not a delivery to prepare
        // for. Nothing is coming yet.
        $this->assertSame([], $this->as($this->cook)->getJson($this->url('/receipts'))->json('data.expected'));

        $this->as($this->office)->patchJson($this->url("/requisitions/{$req->id}"), ['status' => 'issued'])->assertOk();

        $expected = $this->as($this->cook)->getJson($this->url('/receipts'))->assertOk()->json('data.expected');

        $this->assertCount(1, $expected);
        $this->assertSame($req->req_number, $expected[0]['req_number']);
        $this->assertSame(1, $expected[0]['items_count']);
        $this->assertNull($expected[0]['receipt_id'], 'no sheet started yet');

        $this->assertSame(
            1,
            $this->as($this->cook)->getJson($this->url('/dashboard'))->json('data.expected_deliveries'),
            'the card and the list must agree'
        );
    }

    /** A sheet already started says so, so nobody counts the same load twice. */
    public function test_a_started_sheet_is_offered_instead_of_a_new_one(): void
    {
        $req = $this->ordered();

        $receipt = Receipt::create([
            'vessel_id' => $this->vessel->id,
            'requisition_id' => $req->id,
            'received_date' => now()->toDateString(),
            'status' => 'draft',
        ]);

        $expected = $this->as($this->cook)->getJson($this->url('/receipts'))->json('data.expected');

        $this->assertSame($receipt->id, $expected[0]['receipt_id']);
    }

    /** Once it has arrived it drops off, whether or not anybody closed it. */
    public function test_a_confirmed_delivery_takes_it_off_the_list(): void
    {
        $req = $this->ordered();

        Receipt::create([
            'vessel_id' => $this->vessel->id,
            'requisition_id' => $req->id,
            'received_date' => now()->toDateString(),
            'status' => 'confirmed',
            'confirmed_at' => now(),
        ]);

        $this->assertSame([], $this->as($this->cook)->getJson($this->url('/receipts'))->json('data.expected'));
        $this->assertSame(0, $this->as($this->cook)->getJson($this->url('/dashboard'))->json('data.expected_deliveries'));
    }

    /** An order the whole way through to the office having bought it. */
    private function ordered(): Requisition
    {
        $req = $this->draft();
        $this->as($this->cook)->postJson($this->url("/requisitions/{$req->id}/submit"))->assertOk();
        $this->as($this->master)->postJson($this->url("/requisitions/{$req->id}/approve"))->assertOk();
        $this->as($this->office)->patchJson($this->url("/requisitions/{$req->id}"), ['status' => 'issued'])->assertOk();

        return $req->fresh();
    }

    /* --------------------------- pulling it back --------------------------- */

    /**
     * A cook who sent it up a minute early takes it back himself.
     *
     * The alternative was asking the master to decline it, which writes a
     * refusal and an invented reason onto an order nobody objected to. Pulling
     * it back leaves no trace, because nothing happened.
     */
    public function test_the_cook_pulls_back_an_order_the_master_has_not_answered(): void
    {
        $req = $this->draft();
        $this->as($this->cook)->postJson($this->url("/requisitions/{$req->id}/submit"))->assertOk();

        $this->as($this->cook)
            ->postJson($this->url("/requisitions/{$req->id}/pull-back"))
            ->assertOk()
            ->assertSee('yours to change again', false);

        $req->refresh();

        $this->assertSame(Requisition::DRAFT, $req->status);
        $this->assertNull($req->submitted_at, 'it still says it was sent up');
        $this->assertNull($req->submitted_by);
        $this->assertTrue($req->isEditable(), 'pulled back but still not editable');
    }

    /** Once the master has answered, the answer stands. */
    public function test_an_answered_order_cannot_be_pulled_back(): void
    {
        $req = $this->draft();
        $this->as($this->cook)->postJson($this->url("/requisitions/{$req->id}/submit"))->assertOk();
        $this->as($this->master)->postJson($this->url("/requisitions/{$req->id}/approve"))->assertOk();

        $this->as($this->cook)
            ->postJson($this->url("/requisitions/{$req->id}/pull-back"))
            ->assertStatus(422)
            ->assertSee('already answered it', false);

        $this->assertSame(Requisition::APPROVED, $req->fresh()->status);
    }

    /** Pulling back something never sent up says so rather than doing nothing. */
    public function test_a_draft_cannot_be_pulled_back(): void
    {
        $req = $this->draft();

        $this->as($this->cook)
            ->postJson($this->url("/requisitions/{$req->id}/pull-back"))
            ->assertStatus(422)
            ->assertSee('has not been sent up', false);
    }

    /** And it is still only this ship's order to pull. */
    public function test_another_vessels_order_cannot_be_pulled_back(): void
    {
        $other = Vessel::create([
            'name' => 'MV SOUTHERN CROSS', 'crew_size' => 18, 'supply_period_days' => 30,
            'safety_margin_pct' => 0, 'currency' => 'USD', 'exchange_rate' => 1, 'is_active' => true,
        ]);

        $req = $this->draft();
        $this->as($this->cook)->postJson($this->url("/requisitions/{$req->id}/submit"))->assertOk();
        $req->update(['vessel_id' => $other->id]);

        $this->as($this->cook)
            ->postJson("/api/inventory/requisitions/{$req->id}/pull-back")
            ->assertForbidden();
    }

    /**
     * The whole point of pulling it back: edit it and send it up again.
     */
    public function test_a_pulled_back_order_can_be_rewritten_and_resent(): void
    {
        $req = $this->draft();
        $item = $req->items()->first()->item_id;
        $extra = $this->item(1.0);

        $this->as($this->cook)->postJson($this->url("/requisitions/{$req->id}/submit"))->assertOk();
        $this->as($this->cook)->postJson($this->url("/requisitions/{$req->id}/pull-back"))->assertOk();

        $this->as($this->cook)->patchJson($this->url("/requisitions/{$req->id}"), [
            'lines' => [
                ['item_id' => $item, 'order_qty' => 100],
                ['item_id' => $extra->id, 'order_qty' => 12],
            ],
        ])->assertOk();

        $this->as($this->cook)->postJson($this->url("/requisitions/{$req->id}/submit"))->assertOk();

        $req->refresh();
        $this->assertSame(Requisition::PENDING, $req->status);
        $this->assertCount(2, $req->fresh('items')->items);
    }

    /* -------------------------- rewriting an order ------------------------- */

    /**
     * The cook remembers something after saving, and puts it on.
     *
     * A draft is still his, so the lines go back in whole: quantities changed,
     * a line dropped, an item added that the calculator never raised.
     */
    public function test_the_cook_can_rewrite_a_draft(): void
    {
        $req = $this->draft();
        $onIt = $req->items()->first();
        $extra = $this->item(1.0);

        $this->as($this->cook)
            ->patchJson($this->url("/requisitions/{$req->id}"), [
                'lines' => [
                    ['item_id' => $onIt->item_id, 'order_qty' => 40],
                    ['item_id' => $extra->id, 'order_qty' => 7],
                ],
            ])
            ->assertOk()
            ->assertSee('2 lines', false);

        $lines = $req->fresh('items')->items->keyBy('item_id');

        $this->assertCount(2, $lines);
        $this->assertFigure(40.0, $lines[$onIt->item_id]->order_qty);
        $this->assertFigure(7.0, $lines[$extra->id]->order_qty);
        // $1 an item, so the total follows the quantities.
        $this->assertFigure(47.0, $req->fresh()->total_value);
    }

    /** A line left off the list is a line taken off the order. */
    public function test_a_line_can_be_taken_off(): void
    {
        $req = $this->draft();
        $keep = $this->item(1.0);

        $this->as($this->cook)->patchJson($this->url("/requisitions/{$req->id}"), [
            'lines' => [
                ['item_id' => $req->items()->first()->item_id, 'order_qty' => 5],
                ['item_id' => $keep->id, 'order_qty' => 5],
            ],
        ])->assertOk();

        $this->as($this->cook)->patchJson($this->url("/requisitions/{$req->id}"), [
            'lines' => [['item_id' => $keep->id, 'order_qty' => 5]],
        ])->assertOk();

        $this->assertSame([$keep->id], $req->fresh('items')->items->pluck('item_id')->all());
    }

    /** An order the master sent back is the cook's again. */
    public function test_an_order_sent_back_can_be_rewritten(): void
    {
        $req = $this->draft();
        $this->as($this->cook)->postJson($this->url("/requisitions/{$req->id}/submit"))->assertOk();
        $this->as($this->master)
            ->postJson($this->url("/requisitions/{$req->id}/decline"), ['reason' => 'Too much coffee'])
            ->assertOk();

        $this->as($this->cook)->patchJson($this->url("/requisitions/{$req->id}"), [
            'lines' => [['item_id' => $req->items()->first()->item_id, 'order_qty' => 3]],
        ])->assertOk();

        $this->assertFigure(3.0, $req->fresh('items')->items->first()->order_qty);
    }

    /**
     * ...but an order with the master is not. This is the whole reason the two
     * accounts exist: what the master signed for cannot change after he signed.
     */
    public function test_an_order_with_the_master_cannot_be_rewritten(): void
    {
        $req = $this->draft();
        $this->as($this->cook)->postJson($this->url("/requisitions/{$req->id}/submit"))->assertOk();

        $this->as($this->cook)
            ->patchJson($this->url("/requisitions/{$req->id}"), [
                'lines' => [['item_id' => $req->items()->first()->item_id, 'order_qty' => 999]],
            ])
            ->assertStatus(422)
            ->assertSee('is with the master', false);

        $this->assertFigure(100.0, $req->fresh('items')->items->first()->order_qty, 'the order changed anyway');
    }

    /** Nor once the office has it. */
    public function test_an_approved_order_cannot_be_rewritten(): void
    {
        $req = $this->draft();
        $this->as($this->cook)->postJson($this->url("/requisitions/{$req->id}/submit"))->assertOk();
        $this->as($this->master)->postJson($this->url("/requisitions/{$req->id}/approve"))->assertOk();

        $this->as($this->cook)
            ->patchJson($this->url("/requisitions/{$req->id}"), [
                'lines' => [['item_id' => $req->items()->first()->item_id, 'order_qty' => 1]],
            ])
            ->assertStatus(422)
            ->assertSee('can no longer be changed', false);
    }

    /** Emptying an order is deleting it, and says so rather than doing it. */
    public function test_an_order_cannot_be_emptied(): void
    {
        $req = $this->draft();

        $this->as($this->cook)
            ->patchJson($this->url("/requisitions/{$req->id}"), ['lines' => []])
            ->assertStatus(422)
            ->assertSee('needs at least one line', false);

        $this->assertCount(1, $req->fresh('items')->items);
    }

    /** Another ship's order is not editable either, whatever its status. */
    public function test_another_vessels_order_cannot_be_rewritten(): void
    {
        $other = Vessel::create([
            'name' => 'MV SOUTHERN CROSS', 'crew_size' => 18, 'supply_period_days' => 30,
            'safety_margin_pct' => 0, 'currency' => 'USD', 'exchange_rate' => 1, 'is_active' => true,
        ]);

        $req = $this->draft();
        $item = $req->items()->first()->item_id;
        $req->update(['vessel_id' => $other->id]);

        $this->as($this->cook)
            ->patchJson("/api/inventory/requisitions/{$req->id}", [
                'lines' => [['item_id' => $item, 'order_qty' => 1]],
            ])
            ->assertForbidden();
    }

    /** Marking it sent out is the office's, and only after the ship approved it. */
    public function test_only_the_office_marks_an_order_as_sent_out(): void
    {
        $req = $this->draft();

        $this->as($this->office)->patchJson($this->url("/requisitions/{$req->id}"), ['status' => 'issued'])
            ->assertStatus(422)
            ->assertSee('not been approved on board', false);

        $this->as($this->cook)->postJson($this->url("/requisitions/{$req->id}/submit"))->assertOk();
        $this->as($this->master)->postJson($this->url("/requisitions/{$req->id}/approve"))->assertOk();

        $this->as($this->master)->patchJson($this->url("/requisitions/{$req->id}"), ['status' => 'issued'])
            ->assertStatus(422)
            ->assertSee('Only the office', false);

        $this->as($this->office)->patchJson($this->url("/requisitions/{$req->id}"), ['status' => 'issued'])
            ->assertOk();

        $this->assertSame(Requisition::ISSUED, $req->fresh()->status);
        $this->assertNotNull($req->fresh()->issued_at);
    }

    /* ---------------------------- days of supply ---------------------------- */

    /**
     * Sixty days of supply is twice the order of thirty.
     *
     * The vessel's own figure is what she normally runs on; this is what the
     * cook asked for this time, and every target on the order scales to it.
     */
    public function test_the_order_scales_to_the_days_it_has_to_cover(): void
    {
        $this->item(1.0);   // 1 unit a day
        $this->as($this->cook);

        $thirty = $this->getJson($this->url('/requisition/preview?days=30'))->assertOk()->json('data');
        $sixty = $this->getJson($this->url('/requisition/preview?days=60'))->assertOk()->json('data');

        $this->assertFigure(30.0, $thirty['lines'][0]['order_qty']);
        $this->assertFigure(60.0, $sixty['lines'][0]['order_qty']);
        $this->assertSame(60, $sixty['days_of_supply']);
    }

    public function test_the_days_an_order_was_written_for_are_kept_on_it(): void
    {
        $req = $this->draft(['days_of_supply' => 45]);

        $this->assertSame(45, $req->days_of_supply);
        $this->assertSame(45, $req->days());
    }

    /* ----------------------------- the budget ------------------------------- */

    /**
     * The owner's own example, as arithmetic.
     *
     * $5,000 a month and sixty days of supply is not a $5,000 overspend — it is
     * two months bought at once. What the office needs to hear is that next
     * month is already committed, not that the ship has blown its budget.
     */
    public function test_sixty_days_on_a_five_thousand_month_draws_on_two_months(): void
    {
        $check = BudgetCheck::for($this->vessel, 9000, 60);

        $this->assertSame('spans', $check['level'], 'inside the allowance, but across two months');
        $this->assertFigure(10000.0, $check['allowance'], 'two months of $5,000');
        $this->assertFigure(2.0, $check['months']);
        $this->assertStringContainsString('more than one month', $check['message']);
        $this->assertStringContainsString('$5,000.00 comes out of this month', $check['message']);
        $this->assertStringContainsString('$4,000.00 out of next', $check['message']);
    }

    /** Past the allowance for the time it covers is a real overspend. */
    public function test_an_order_past_its_allowance_says_so(): void
    {
        $check = BudgetCheck::for($this->vessel, 12000, 60);

        $this->assertSame('over', $check['level']);
        $this->assertFigure(-2000.0, $check['balance']);
        $this->assertStringContainsString('$2,000.00 over', $check['message']);

        $oneMonth = BudgetCheck::for($this->vessel, 6000, 30);
        $this->assertSame('over', $oneMonth['level']);
        $this->assertStringContainsString('$1,000.00 over', $oneMonth['message']);
    }

    public function test_an_order_within_the_month_is_quiet(): void
    {
        $check = BudgetCheck::for($this->vessel, 2000, 30);

        $this->assertSame('ok', $check['level']);
        $this->assertNull($check['message'], 'nothing to say');
        $this->assertFigure(40.0, $check['used_pct']);
    }

    public function test_an_order_nearly_at_the_limit_gives_warning_first(): void
    {
        $check = BudgetCheck::for($this->vessel, 4800, 30);

        $this->assertSame('close', $check['level']);
        $this->assertStringContainsString('96% of the month', $check['message']);
    }

    /**
     * A vessel nobody has given a budget is not a vessel on a budget of zero.
     *
     * Saying "over budget" there would teach the crew to ignore the warning on
     * the ships where it means something.
     */
    public function test_a_vessel_with_no_budget_is_not_over_it(): void
    {
        $this->vessel->update(['budget' => 0]);

        $check = BudgetCheck::for($this->vessel->fresh(), 9999, 30);

        $this->assertSame('none', $check['level']);
        $this->assertNull($check['message']);
    }

    /** It warns; it never stops the order. */
    public function test_the_budget_never_blocks_an_order(): void
    {
        $item = $this->item(1.0);

        $response = $this->as($this->cook)->postJson($this->url('/requisitions'), [
            'days_of_supply' => 60,
            'lines' => [['item_id' => $item->id, 'order_qty' => 99999]],
        ])->assertCreated();

        $this->assertSame('over', $response->json('data.budget.level'));
        // And the warning rides along with the save, so the cook sees it on the
        // order he just wrote.
        $this->assertStringContainsString('over', $response->json('message'));

        $req = Requisition::latest('id')->first();
        $this->as($this->cook)->postJson($this->url("/requisitions/{$req->id}/submit"))->assertOk();
        $this->as($this->master)->postJson($this->url("/requisitions/{$req->id}/approve"))->assertOk();

        $this->assertSame(Requisition::APPROVED, $req->fresh()->status);
    }

    /** The warning is on the preview too, before anything is saved. */
    public function test_the_preview_carries_the_budget_warning(): void
    {
        $this->item(1.0);

        $budget = $this->as($this->master)
            ->getJson($this->url('/requisition/preview?days=60'))
            ->assertOk()
            ->json('data.budget');

        $this->assertSame(60, $budget['days']);
        $this->assertFigure(10000.0, $budget['allowance']);
        $this->assertTrue($budget['spans_months']);

        // The cook gets the same verdict with the figures taken out. He is told
        // the order runs past one month — which is what makes him do something
        // about it — and not what the month is worth.
        $his = $this->as($this->cook)
            ->getJson($this->url('/requisition/preview?days=60'))
            ->assertOk()
            ->json('data.budget');

        $this->assertSame($budget['level'], $his['level'], 'the same verdict, either way');
        $this->assertTrue($his['spans_months']);
        $this->assertNull($his['allowance']);
        $this->assertNull($his['budget']);
        $this->assertStringContainsString('more than one month', (string) $his['message']);
        $this->assertStringNotContainsString('$', (string) $his['message']);
    }

    /**
     * What was approved against is frozen at approval.
     *
     * That is the moment somebody took responsibility for the spend, so if the
     * office moves the vessel's budget next week the order still reads right.
     */
    public function test_the_budget_is_frozen_when_the_master_approves(): void
    {
        $req = $this->draft();
        $this->as($this->cook)->postJson($this->url("/requisitions/{$req->id}/submit"))->assertOk();
        $this->as($this->master)->postJson($this->url("/requisitions/{$req->id}/approve"))->assertOk();

        $this->assertFigure(5000.0, $req->fresh()->budget_at_issue);

        $this->vessel->update(['budget' => 1000]);

        $this->assertFigure(5000.0, $req->fresh()->budget_at_issue, 'what he signed against');
    }

    /* ------------------------------- the inbox ------------------------------ */

    /** A ship's crew have no business reading the fleet's order book. */
    public function test_the_crew_cannot_see_the_fleets_inbox(): void
    {
        $this->as($this->master)->getJson('/api/inventory/requisitions/inbox')->assertForbidden();
        $this->as($this->cook)->getJson('/api/inventory/requisitions/inbox')->assertForbidden();
    }

    /** And they cannot approve another ship's order either. */
    public function test_a_master_cannot_approve_another_vessels_order(): void
    {
        $other = Vessel::create([
            'name' => 'MV SOUTHERN CROSS', 'crew_size' => 18, 'supply_period_days' => 30,
            'safety_margin_pct' => 0, 'budget' => 3000, 'currency' => 'USD',
            'exchange_rate' => 1, 'is_active' => true,
        ]);

        $theirs = Requisition::create([
            'vessel_id' => $other->id,
            'req_number' => 'REQ-OTHER-1',
            'req_date' => now()->toDateString(),
            'status' => Requisition::PENDING,
            'currency' => 'USD',
        ]);

        $this->as($this->master)
            ->postJson("/api/inventory/requisitions/{$theirs->id}/approve?vessel_id={$this->vessel->id}")
            ->assertForbidden();

        $this->assertSame(Requisition::PENDING, $theirs->fresh()->status);
    }
}

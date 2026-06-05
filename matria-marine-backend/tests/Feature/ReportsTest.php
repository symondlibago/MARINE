<?php

namespace Tests\Feature;

use App\Models\Award;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\Rfq;
use App\Models\RfqVendor;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use RefreshDatabase;

    private function actingStaff(): User
    {
        Role::findOrCreate('admin', 'web');
        $staff = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $staff->assignRole('admin');
        Sanctum::actingAs($staff);

        return $staff;
    }

    /** One item, two vendors quote (3 and 5), award the cheaper, generate a PO. */
    private function seedActivity(): void
    {
        $rfq = Rfq::create(['reference' => 'RFQ-5001', 'ship_name' => 'MV Alpha', 'base_currency' => 'USD', 'status' => 'awarded']);
        $i1 = $rfq->items()->create(['description' => 'Apples', 'qty' => 10, 'unit' => 'kg', 'sort' => 0]);

        $vA = Vendor::factory()->create(['name' => 'Alpha', 'currency' => 'USD', 'nav_code' => 'V1', 'email' => 'a@a.com']);
        $vB = Vendor::factory()->create(['name' => 'Beta', 'currency' => 'USD', 'nav_code' => 'V2', 'email' => 'b@b.com']);

        foreach ([$vA, $vB] as $v) {
            RfqVendor::create(['rfq_id' => $rfq->id, 'vendor_id' => $v->id, 'token' => Str::random(48), 'status' => 'requested', 'sent_at' => now()]);
        }

        $qA = Quote::create(['rfq_id' => $rfq->id, 'vendor_id' => $vA->id, 'currency' => 'USD', 'exchange_rate' => 1, 'status' => 'submitted']);
        $qiA = QuoteItem::create(['quote_id' => $qA->id, 'rfq_item_id' => $i1->id, 'unit_cost' => 3, 'qty_quoted' => 10]);
        $qB = Quote::create(['rfq_id' => $rfq->id, 'vendor_id' => $vB->id, 'currency' => 'USD', 'exchange_rate' => 1, 'status' => 'submitted']);
        QuoteItem::create(['quote_id' => $qB->id, 'rfq_item_id' => $i1->id, 'unit_cost' => 5, 'qty_quoted' => 10]);

        Award::create(['rfq_item_id' => $i1->id, 'vendor_id' => $vA->id, 'quote_item_id' => $qiA->id, 'qty_to_buy' => 10, 'unit_cost' => 3]);

        $this->postJson("/api/portal/rfqs/{$rfq->id}/purchase-orders")->assertOk();
    }

    public function test_spend_report(): void
    {
        $this->actingStaff();
        $this->seedActivity();

        $res = $this->getJson('/api/portal/reports/spend')->assertOk();
        $res->assertJsonPath('data.base_currency', 'USD');
        $this->assertEquals(30, $res->json('data.totals.ordered'));  // 10 * 3
        $this->assertEquals(1, $res->json('data.totals.po_count'));
        $this->assertEquals('Alpha', $res->json('data.by_vendor.0.vendor'));
        $this->assertEquals('MV Alpha', $res->json('data.by_vessel.0.vessel'));
    }

    public function test_vendor_scorecard(): void
    {
        $this->actingStaff();
        $this->seedActivity();

        $rows = collect($this->getJson('/api/portal/reports/vendors')->assertOk()->json('data.rows'));
        $alpha = $rows->firstWhere('vendor', 'Alpha');

        $this->assertNotNull($alpha);
        $this->assertEquals(1, $alpha['sent']);
        $this->assertEquals(1, $alpha['quoted']);
        $this->assertEquals(100, $alpha['response_rate']);
        $this->assertEquals(1, $alpha['orders']);
        $this->assertEquals(30, $alpha['ordered_value']);
    }

    public function test_pipeline_funnel_and_savings(): void
    {
        $this->actingStaff();
        $this->seedActivity();

        $res = $this->getJson('/api/portal/reports/pipeline')->assertOk();
        $funnel = collect($res->json('data.funnel'));

        $this->assertEquals(1, $funnel->firstWhere('stage', 'Enquiries')['count']);
        $this->assertEquals(1, $funnel->firstWhere('stage', 'Quoted')['count']);
        $this->assertEquals(1, $funnel->firstWhere('stage', 'Awarded')['count']);
        $this->assertEquals(1, $funnel->firstWhere('stage', 'Ordered')['count']);

        // avg(3,5)=4, awarded 3 -> (4-3) * 10 = 10 saved
        $this->assertEquals(10, $res->json('data.savings.total'));
        $this->assertEquals(1, $res->json('data.savings.lines'));
    }
}

<?php

namespace Tests\Feature;

use App\Mail\VendorQuoteRequest;
use App\Models\RfqVendor;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProcurementSpineTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_enquiry_spine_create_send_quote_compare_award_pdf(): void
    {
        Mail::fake();

        Role::findOrCreate('admin', 'web');
        $staff = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $staff->assignRole('admin');

        $v1 = Vendor::factory()->create(['email' => 'v1@test.com', 'currency' => 'USD']);
        $v2 = Vendor::factory()->create(['email' => 'v2@test.com', 'currency' => 'EUR']);

        Sanctum::actingAs($staff);

        // 1) create enquiry with two hand-typed line items
        $create = $this->postJson('/api/portal/rfqs', [
            'base_currency' => 'USD',
            'ship_name' => 'MV Test',
            'items' => [
                ['description' => 'Apples', 'qty' => 10, 'unit' => 'kg'],
                ['description' => 'Water', 'qty' => 100, 'unit' => 'btl'],
            ],
        ])->assertCreated();

        $rfqId = $create->json('data.id');
        $this->assertNotNull($create->json('data.reference'));

        // 2) send to both vendors (mail faked) — tokens created
        $this->postJson("/api/portal/rfqs/{$rfqId}/send", [
            'vendor_ids' => [$v1->id, $v2->id],
            'message' => 'Please quote',
        ])->assertOk();
        Mail::assertSent(VendorQuoteRequest::class, 2);

        $tokens = RfqVendor::where('rfq_id', $rfqId)->pluck('token', 'vendor_id');

        // 3) vendors submit via public magic link (no auth required)
        $show = $this->getJson("/api/quote/{$tokens[$v1->id]}")->assertOk();
        $items = collect($show->json('data.items'));
        $this->assertCount(2, $items);

        $this->postJson("/api/quote/{$tokens[$v1->id]}", [
            'currency' => 'USD',
            'lines' => $items->map(fn ($i) => ['rfq_item_id' => $i['rfq_item_id'], 'unit_cost' => 10])->all(),
        ])->assertOk();

        $this->postJson("/api/quote/{$tokens[$v2->id]}", [
            'currency' => 'EUR',
            'lines' => $items->map(fn ($i) => ['rfq_item_id' => $i['rfq_item_id'], 'unit_cost' => 5])->all(),
        ])->assertOk();

        // 4) compare — two vendor columns, lowest base cost is v2 (5)
        $cmp = $this->getJson("/api/portal/rfqs/{$rfqId}/compare")->assertOk();
        $this->assertCount(2, $cmp->json('data.vendors'));
        $this->assertCount(2, $cmp->json('data.rows'));
        $this->assertEquals(5, $cmp->json('data.rows.0.lowest_base_cost'));

        // 5) award both lines to v2 and finish (lock)
        $awards = collect($cmp->json('data.rows'))->map(fn ($row) => [
            'rfq_item_id' => $row['rfq_item_id'],
            'vendor_id' => $v2->id,
            'qty_to_buy' => $row['qty'],
            'unit_cost' => 5,
        ])->all();

        $this->postJson("/api/portal/rfqs/{$rfqId}/awards", ['awards' => $awards])->assertOk();
        $this->postJson("/api/portal/rfqs/{$rfqId}/finish")->assertOk();

        $this->assertDatabaseHas('rfqs', ['id' => $rfqId, 'status' => 'closed']);
        $this->assertDatabaseCount('awards', 2);
        $this->assertDatabaseHas('awards', ['vendor_id' => $v2->id]);

        // 6) per-vendor award PDF
        $pdf = $this->get("/api/portal/rfqs/{$rfqId}/vendors/{$v2->id}/award-pdf");
        $pdf->assertOk();
        $this->assertEquals('application/pdf', $pdf->headers->get('content-type'));
    }

    public function test_portal_requires_authentication(): void
    {
        $this->getJson('/api/portal/rfqs')->assertUnauthorized();
    }

    public function test_invalid_quote_token_is_rejected(): void
    {
        $this->getJson('/api/quote/nonexistenttoken')->assertNotFound();
    }
}

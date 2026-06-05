<?php

namespace Tests\Feature;

use App\Mail\PurchaseOrderMail;
use App\Models\Award;
use App\Models\PurchaseOrder;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\Rfq;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PurchaseOrderTest extends TestCase
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

    /** Build an awarded enquiry: items 1&2 -> vendor A (USD), item 3 -> vendor B (EUR). */
    private function buildAwardedRfq(): array
    {
        $rfq = Rfq::create([
            'reference' => 'RFQ-9001',
            'ship_name' => 'MV Cargo',
            'delivery_port' => 'Singapore',
            'base_currency' => 'USD',
            'status' => 'awarded',
        ]);
        $i1 = $rfq->items()->create(['description' => 'Apples', 'qty' => 10, 'unit' => 'kg', 'sort' => 0]);
        $i2 = $rfq->items()->create(['description' => 'Water', 'qty' => 100, 'unit' => 'btl', 'sort' => 1]);
        $i3 = $rfq->items()->create(['description' => 'Rope', 'qty' => 5, 'unit' => 'm', 'sort' => 2]);

        $vA = Vendor::factory()->create(['name' => 'Alpha Supplies', 'email' => 'alpha@test.com', 'currency' => 'USD']);
        $vB = Vendor::factory()->create(['name' => 'Beta Trading', 'email' => 'beta@test.com', 'currency' => 'EUR']);

        $qA = Quote::create(['rfq_id' => $rfq->id, 'vendor_id' => $vA->id, 'currency' => 'USD', 'exchange_rate' => 1, 'status' => 'submitted']);
        $qB = Quote::create(['rfq_id' => $rfq->id, 'vendor_id' => $vB->id, 'currency' => 'EUR', 'exchange_rate' => 1.1, 'status' => 'submitted']);

        $qiA1 = QuoteItem::create(['quote_id' => $qA->id, 'rfq_item_id' => $i1->id, 'unit_cost' => 3, 'qty_quoted' => 10]);
        $qiA2 = QuoteItem::create(['quote_id' => $qA->id, 'rfq_item_id' => $i2->id, 'unit_cost' => 1, 'qty_quoted' => 100]);
        $qiB3 = QuoteItem::create(['quote_id' => $qB->id, 'rfq_item_id' => $i3->id, 'unit_cost' => 2, 'qty_quoted' => 5]);

        Award::create(['rfq_item_id' => $i1->id, 'vendor_id' => $vA->id, 'quote_item_id' => $qiA1->id, 'qty_to_buy' => 10, 'unit_cost' => 3]);
        Award::create(['rfq_item_id' => $i2->id, 'vendor_id' => $vA->id, 'quote_item_id' => $qiA2->id, 'qty_to_buy' => 100, 'unit_cost' => 1]);
        Award::create(['rfq_item_id' => $i3->id, 'vendor_id' => $vB->id, 'quote_item_id' => $qiB3->id, 'qty_to_buy' => 5, 'unit_cost' => 2]);

        return compact('rfq', 'vA', 'vB');
    }

    public function test_generate_creates_one_po_per_awarded_vendor(): void
    {
        $this->actingStaff();
        ['rfq' => $rfq, 'vA' => $vA, 'vB' => $vB] = $this->buildAwardedRfq();

        $res = $this->postJson("/api/portal/rfqs/{$rfq->id}/purchase-orders")->assertOk();
        $this->assertCount(2, $res->json('data.created'));

        $this->assertDatabaseCount('purchase_orders', 2);
        $this->assertDatabaseCount('purchase_order_items', 3);

        // Vendor A: 2 lines in USD, subtotal 10*3 + 100*1 = 130
        $poA = PurchaseOrder::where('vendor_id', $vA->id)->first();
        $this->assertEquals('USD', $poA->currency);
        $this->assertCount(2, $poA->items);
        $this->assertEquals(130, (float) $poA->subtotal);
        $this->assertNotNull($poA->po_number);
        $this->assertEquals('Singapore', $poA->delivery_port);

        // Vendor B: 1 line in EUR @1.1, subtotal 5*2 = 10
        $poB = PurchaseOrder::where('vendor_id', $vB->id)->first();
        $this->assertEquals('EUR', $poB->currency);
        $this->assertEquals(10, (float) $poB->subtotal);
        $this->assertEquals(1.1, (float) $poB->exchange_rate);
    }

    public function test_generate_is_idempotent(): void
    {
        $this->actingStaff();
        ['rfq' => $rfq] = $this->buildAwardedRfq();

        $this->postJson("/api/portal/rfqs/{$rfq->id}/purchase-orders")->assertOk();
        $second = $this->postJson("/api/portal/rfqs/{$rfq->id}/purchase-orders")->assertOk();

        $this->assertCount(0, $second->json('data.created'));
        $this->assertDatabaseCount('purchase_orders', 2);
    }

    public function test_index_show_update_pdf_and_delete(): void
    {
        $this->actingStaff();
        ['rfq' => $rfq, 'vA' => $vA] = $this->buildAwardedRfq();
        $this->postJson("/api/portal/rfqs/{$rfq->id}/purchase-orders")->assertOk();

        $idx = $this->getJson("/api/portal/purchase-orders?rfq_id={$rfq->id}")->assertOk();
        $this->assertCount(2, $idx->json('data'));

        $po = PurchaseOrder::where('vendor_id', $vA->id)->first();

        $this->getJson("/api/portal/purchase-orders/{$po->id}")
            ->assertOk()
            ->assertJsonPath('data.po_number', $po->po_number);

        // Edit a draft line: keep one item (qty 20), drop the other, add a note.
        $first = $po->items()->orderBy('sort')->first();
        $this->patchJson("/api/portal/purchase-orders/{$po->id}", [
            'items' => [
                ['id' => $first->id, 'description' => $first->description, 'unit' => $first->unit, 'qty' => 20, 'unit_cost' => 3],
            ],
            'notes' => 'Rush order',
        ])->assertOk();

        $po->refresh();
        $this->assertEquals(1, $po->items()->count());
        $this->assertEquals(60, (float) $po->subtotal); // 20 * 3
        $this->assertEquals('Rush order', $po->notes);

        $pdf = $this->get("/api/portal/purchase-orders/{$po->id}/pdf");
        $pdf->assertOk();
        $this->assertEquals('application/pdf', $pdf->headers->get('content-type'));

        // Issue it -> draft delete is then refused.
        $this->patchJson("/api/portal/purchase-orders/{$po->id}", ['status' => 'issued'])->assertOk();
        $po->refresh();
        $this->assertEquals('issued', $po->status);
        $this->assertNotNull($po->issued_date);

        $this->deleteJson("/api/portal/purchase-orders/{$po->id}")->assertStatus(422);

        $draft = PurchaseOrder::where('status', 'draft')->first();
        $this->deleteJson("/api/portal/purchase-orders/{$draft->id}")->assertOk();
    }

    public function test_email_marks_po_issued(): void
    {
        Mail::fake();
        $this->actingStaff();
        ['rfq' => $rfq, 'vA' => $vA] = $this->buildAwardedRfq();
        $this->postJson("/api/portal/rfqs/{$rfq->id}/purchase-orders")->assertOk();

        $po = PurchaseOrder::where('vendor_id', $vA->id)->first();
        $this->postJson("/api/portal/purchase-orders/{$po->id}/email")->assertOk();

        Mail::assertSent(PurchaseOrderMail::class);
        $this->assertEquals('issued', $po->fresh()->status);
    }

    public function test_generate_rejects_enquiry_without_awards(): void
    {
        $this->actingStaff();
        $rfq = Rfq::create(['reference' => 'RFQ-9002', 'base_currency' => 'USD', 'status' => 'draft']);

        $this->postJson("/api/portal/rfqs/{$rfq->id}/purchase-orders")->assertStatus(422);
    }

    public function test_generated_pos_have_a_magic_link_token(): void
    {
        $this->actingStaff();
        ['rfq' => $rfq, 'vA' => $vA] = $this->buildAwardedRfq();
        $this->postJson("/api/portal/rfqs/{$rfq->id}/purchase-orders")->assertOk();

        $this->assertNotEmpty(PurchaseOrder::where('vendor_id', $vA->id)->first()->token);
    }

    public function test_public_show_returns_po_and_marks_opened(): void
    {
        $this->actingStaff();
        ['rfq' => $rfq, 'vA' => $vA] = $this->buildAwardedRfq();
        $this->postJson("/api/portal/rfqs/{$rfq->id}/purchase-orders")->assertOk();
        $po = PurchaseOrder::where('vendor_id', $vA->id)->first();
        $this->assertNull($po->opened_at);

        $this->getJson("/api/po/{$po->token}")
            ->assertOk()
            ->assertJsonPath('data.po_number', $po->po_number)
            ->assertJsonCount(2, 'data.items');

        $this->assertNotNull($po->fresh()->opened_at);
    }

    public function test_public_accept_records_acceptance(): void
    {
        $this->actingStaff();
        ['rfq' => $rfq, 'vA' => $vA] = $this->buildAwardedRfq();
        $this->postJson("/api/portal/rfqs/{$rfq->id}/purchase-orders")->assertOk();
        $po = PurchaseOrder::where('vendor_id', $vA->id)->first();

        $this->postJson("/api/po/{$po->token}/accept", [
            'name' => 'Captain Vendor',
            'note' => 'Delivery by 12 June.',
        ])->assertOk();

        $po->refresh();
        $this->assertNotNull($po->accepted_at);
        $this->assertEquals('Captain Vendor', $po->accepted_by_name);
        $this->assertEquals('Delivery by 12 June.', $po->acceptance_note);
        $this->assertNotNull($po->opened_at);
    }

    public function test_public_invalid_token_is_404(): void
    {
        $this->getJson('/api/po/totallybogustoken')->assertNotFound();
    }

    public function test_cancelled_po_link_is_blocked(): void
    {
        $this->actingStaff();
        ['rfq' => $rfq, 'vA' => $vA] = $this->buildAwardedRfq();
        $this->postJson("/api/portal/rfqs/{$rfq->id}/purchase-orders")->assertOk();
        $po = PurchaseOrder::where('vendor_id', $vA->id)->first();

        $this->patchJson("/api/portal/purchase-orders/{$po->id}", ['status' => 'cancelled'])->assertOk();

        $this->getJson("/api/po/{$po->token}")->assertNotFound();
        $this->postJson("/api/po/{$po->token}/accept")->assertStatus(422);
    }

    public function test_email_carries_acceptance_link(): void
    {
        Mail::fake();
        $this->actingStaff();
        ['rfq' => $rfq, 'vA' => $vA] = $this->buildAwardedRfq();
        $this->postJson("/api/portal/rfqs/{$rfq->id}/purchase-orders")->assertOk();
        $po = PurchaseOrder::where('vendor_id', $vA->id)->first();

        $this->postJson("/api/portal/purchase-orders/{$po->id}/email")->assertOk();

        Mail::assertSent(PurchaseOrderMail::class, fn ($mail) => str_contains((string) $mail->link, '/po/'.$po->token));
    }
}

<?php

namespace Tests\Feature;

use App\Models\Award;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\Rfq;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PurchaseInvoiceTest extends TestCase
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

    /** Awarded RFQ -> generate a single PO (vendor A, 2 lines @ 130 USD). */
    private function makePo(): PurchaseOrder
    {
        $rfq = Rfq::create(['reference' => 'RFQ-7001', 'ship_name' => 'MV Test', 'delivery_port' => 'Cebu', 'base_currency' => 'USD', 'status' => 'awarded']);
        $i1 = $rfq->items()->create(['description' => 'Apples', 'qty' => 10, 'unit' => 'kg', 'sort' => 0]);
        $i2 = $rfq->items()->create(['description' => 'Water', 'qty' => 100, 'unit' => 'btl', 'sort' => 1]);

        $v = Vendor::factory()->create(['name' => 'Alpha', 'email' => 'a@test.com', 'currency' => 'USD', 'nav_code' => 'V001']);
        $q = Quote::create(['rfq_id' => $rfq->id, 'vendor_id' => $v->id, 'currency' => 'USD', 'exchange_rate' => 1, 'status' => 'submitted']);
        $qi1 = QuoteItem::create(['quote_id' => $q->id, 'rfq_item_id' => $i1->id, 'unit_cost' => 3, 'qty_quoted' => 10]);
        $qi2 = QuoteItem::create(['quote_id' => $q->id, 'rfq_item_id' => $i2->id, 'unit_cost' => 1, 'qty_quoted' => 100]);
        Award::create(['rfq_item_id' => $i1->id, 'vendor_id' => $v->id, 'quote_item_id' => $qi1->id, 'qty_to_buy' => 10, 'unit_cost' => 3]);
        Award::create(['rfq_item_id' => $i2->id, 'vendor_id' => $v->id, 'quote_item_id' => $qi2->id, 'qty_to_buy' => 100, 'unit_cost' => 1]);

        $this->postJson("/api/portal/rfqs/{$rfq->id}/purchase-orders")->assertOk();

        return PurchaseOrder::where('vendor_id', $v->id)->firstOrFail();
    }

    public function test_create_invoice_from_po_prefills_lines_and_snapshots(): void
    {
        $this->actingStaff();
        $po = $this->makePo();

        $invId = $this->postJson("/api/portal/purchase-orders/{$po->id}/invoice")->assertCreated()->json('data.id');

        $inv = PurchaseInvoice::find($invId);
        $this->assertEquals($po->id, $inv->purchase_order_id);
        $this->assertEquals('USD', $inv->currency);
        $this->assertEquals('draft', $inv->status);
        $this->assertCount(2, $inv->items);
        $this->assertEquals(130, (float) $inv->total);
        $this->assertNotNull($inv->reference);

        // PO snapshots drive the 3-way match.
        $first = $inv->items()->orderBy('sort')->first();
        $this->assertEquals(10, (float) $first->po_qty);
        $this->assertEquals(3, (float) $first->po_unit_cost);
    }

    public function test_update_recomputes_totals_with_other_charges(): void
    {
        $this->actingStaff();
        $po = $this->makePo();
        $invId = $this->postJson("/api/portal/purchase-orders/{$po->id}/invoice")->json('data.id');
        $inv = PurchaseInvoice::find($invId);
        $first = $inv->items()->orderBy('sort')->first();

        $this->patchJson("/api/portal/purchase-invoices/{$invId}", [
            'vendor_invoice_no' => 'INV-555',
            'other_charges' => 20,
            'items' => [
                ['id' => $first->id, 'description' => $first->description, 'unit' => $first->unit, 'qty' => 12, 'unit_cost' => 3],
            ],
        ])->assertOk();

        $inv->refresh();
        $this->assertEquals('INV-555', $inv->vendor_invoice_no);
        $this->assertEquals(1, $inv->items()->count());   // dropped the second line
        $this->assertEquals(36, (float) $inv->subtotal);  // 12 * 3
        $this->assertEquals(56, (float) $inv->total);     // 36 + 20 charges
    }

    public function test_approve_then_export_csv_marks_exported(): void
    {
        $this->actingStaff();
        $po = $this->makePo();
        $invId = $this->postJson("/api/portal/purchase-orders/{$po->id}/invoice")->json('data.id');

        $this->patchJson("/api/portal/purchase-invoices/{$invId}", ['vendor_invoice_no' => 'INV-900', 'status' => 'approved'])->assertOk();

        $res = $this->post('/api/portal/purchase-invoices/export')->assertOk();
        $this->assertStringContainsString('text/csv', $res->headers->get('content-type'));

        $csv = $res->getContent();
        $this->assertStringContainsString('Document No.', $csv);  // header row
        $this->assertStringContainsString('PINV-', $csv);         // our invoice ref
        $this->assertStringContainsString('V001', $csv);          // vendor Navision No.
        $this->assertStringContainsString('INV-900', $csv);       // external document no.

        $this->assertEquals('exported', PurchaseInvoice::find($invId)->status);
    }

    public function test_export_without_approved_is_422(): void
    {
        $this->actingStaff();
        $this->postJson('/api/portal/purchase-invoices/export')->assertStatus(422);
    }

    public function test_exported_invoice_cannot_be_edited(): void
    {
        $this->actingStaff();
        $po = $this->makePo();
        $invId = $this->postJson("/api/portal/purchase-orders/{$po->id}/invoice")->json('data.id');
        $this->patchJson("/api/portal/purchase-invoices/{$invId}", ['status' => 'approved'])->assertOk();
        $this->post('/api/portal/purchase-invoices/export')->assertOk();

        $this->patchJson("/api/portal/purchase-invoices/{$invId}", ['notes' => 'too late'])->assertStatus(422);
    }
}

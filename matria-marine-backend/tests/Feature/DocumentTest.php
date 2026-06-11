<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Document;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DocumentTest extends TestCase
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

    public function test_create_invoice_auto_numbers_and_totals(): void
    {
        $this->actingStaff();
        $cust = Customer::factory()->create(['name' => 'Acme Shipping', 'currency' => 'USD']);

        $res = $this->postJson('/api/portal/documents', [
            'type' => 'invoice',
            'party_kind' => 'customer',
            'customer_id' => $cust->id,
            'party_name' => $cust->name,
            'currency' => 'USD',
            'date' => '2026-06-11',
            'gst_rate' => 0,
            'items' => [
                ['description' => 'Apples', 'unit' => 'kg', 'qty' => 10, 'unit_price' => 3],
                ['description' => 'Water', 'unit' => 'btl', 'qty' => 100, 'unit_price' => 1],
            ],
        ])->assertCreated();

        $doc = Document::find($res->json('data.id'));
        $this->assertStringStartsWith('MMS-INV-26-', $doc->number);
        $this->assertEquals(130, (float) $doc->subtotal);
        $this->assertEquals(130, (float) $doc->total);
        $this->assertCount(2, $doc->items);
    }

    public function test_next_number_increments(): void
    {
        $this->actingStaff();

        $first = $this->postJson('/api/portal/documents', [
            'type' => 'invoice', 'currency' => 'USD', 'party_name' => 'X',
            'items' => [['description' => 'A', 'qty' => 1, 'unit_price' => 10]],
        ])->assertCreated()->json('data.number');

        $this->assertStringEndsWith('000001', $first);

        $next = $this->getJson('/api/portal/documents/next-number?type=invoice')->assertOk()->json('data.number');
        $this->assertStringEndsWith('000002', $next);
    }

    public function test_gst_and_heading_line(): void
    {
        $this->actingStaff();

        $res = $this->postJson('/api/portal/documents', [
            'type' => 'quotation', 'currency' => 'USD', 'party_name' => 'Vessel Y', 'gst_rate' => 9,
            'items' => [
                ['is_heading' => true, 'description' => 'SECTION A'],
                ['description' => 'Item', 'qty' => 2, 'unit_price' => 50],
            ],
        ])->assertCreated();

        $doc = Document::find($res->json('data.id'));
        $this->assertStringStartsWith('MMS-QTN-', $doc->number);
        $this->assertEquals(100, (float) $doc->subtotal);   // heading excluded
        $this->assertEquals(9, (float) $doc->gst_amount);    // 100 * 9%
        $this->assertEquals(109, (float) $doc->total);
        $this->assertCount(2, $doc->items);
    }

    public function test_enquiry_addresses_a_vendor(): void
    {
        $this->actingStaff();
        $vendor = Vendor::factory()->create(['name' => 'Supplier Z']);

        $res = $this->postJson('/api/portal/documents', [
            'type' => 'enquiry', 'party_kind' => 'vendor', 'vendor_id' => $vendor->id,
            'party_name' => $vendor->name, 'currency' => 'USD',
            'items' => [['description' => 'Spare part', 'qty' => 1, 'unit_price' => 0]],
        ])->assertCreated();

        $doc = Document::find($res->json('data.id'));
        $this->assertStringStartsWith('MMS-ENQ-', $doc->number);
        $this->assertEquals($vendor->id, $doc->vendor_id);
    }

    public function test_document_pdf_downloads(): void
    {
        $this->actingStaff();
        $res = $this->postJson('/api/portal/documents', [
            'type' => 'delivery_note', 'currency' => 'USD', 'party_name' => 'mv Test',
            'items' => [['description' => 'Crate', 'unit' => 'pcs', 'qty' => 1, 'unit_price' => 0]],
        ])->assertCreated();

        $pdf = $this->get('/api/portal/documents/'.$res->json('data.id').'/pdf');
        $pdf->assertOk();
        $this->assertEquals('application/pdf', $pdf->headers->get('content-type'));
    }
}

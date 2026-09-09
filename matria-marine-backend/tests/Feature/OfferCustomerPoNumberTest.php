<?php

namespace Tests\Feature;

use App\Models\CustomerInvoice;
use App\Models\DeliveryOrder;
use App\Models\Offer;
use App\Models\Rfq;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OfferCustomerPoNumberTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_po_is_stored_separately_and_carried_to_new_documents(): void
    {
        Role::findOrCreate('admin', 'web');
        $staff = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $staff->assignRole('admin');
        Sanctum::actingAs($staff);

        $rfq = Rfq::create([
            'reference' => 'MMS-QTN-2026-009999',
            'customer_reference' => 'CUSTOMER-ENQUIRY-123',
            'base_currency' => 'USD',
        ]);

        $offer = Offer::create([
            'offer_number' => $rfq->reference,
            'rfq_id' => $rfq->id,
            'currency' => 'USD',
            'status' => 'draft',
        ]);

        $this->patchJson("/api/portal/offers/{$offer->id}", [
            'customer_po_number' => 'PO-CLIENT-456',
        ])->assertOk()
            ->assertJsonPath('data.customer_po_number', 'PO-CLIENT-456')
            ->assertJsonPath('data.rfq.customer_reference', 'CUSTOMER-ENQUIRY-123');

        $offer->refresh();
        $this->assertSame('PO-CLIENT-456', $offer->customer_po_number);
        $this->assertSame('CUSTOMER-ENQUIRY-123', $offer->rfq->customer_reference);

        $this->getJson("/api/offer/{$offer->token}")
            ->assertOk()
            ->assertJsonPath('data.customer_reference', 'CUSTOMER-ENQUIRY-123')
            ->assertJsonPath('data.customer_po_number', 'PO-CLIENT-456');

        $this->postJson("/api/portal/offers/{$offer->id}/delivery-order")->assertCreated();
        $this->postJson("/api/portal/offers/{$offer->id}/invoice")->assertCreated();

        $this->assertSame('PO-CLIENT-456', DeliveryOrder::where('offer_id', $offer->id)->value('customer_reference'));
        $this->assertSame('PO-CLIENT-456', CustomerInvoice::where('offer_id', $offer->id)->value('customer_reference'));
    }
}

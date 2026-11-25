<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\Inventory\Category;
use App\Models\Inventory\Item;
use App\Models\Inventory\Requisition;
use App\Models\Inventory\Vessel;
use App\Models\Offer;
use App\Models\PurchaseOrder;
use App\Models\Rfq;
use App\Models\User;
use App\Models\Vendor;
use App\Support\DocumentOrigin;
use App\Support\Inventory\VesselAccess;
use App\Mail\OfferMail;
use App\Support\LedgerEntries;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Where the provisions side hands over to procurement, and how the books stay
 * one set of books.
 *
 * The owner's instruction, in his own words: "if im at the general page for
 * inventory i will only see the accounting of the invnetory part i cannot see
 * the PS, if i am at the PS i can see everything included the inventory part."
 *
 * So: one ledger, two views. Not two ledgers — that would mean two purchase
 * order sequences, two GST returns to reconcile, and a vendor appearing twice
 * with half his balance in each. One ledger with a filter means the
 * accountant's totals are still the company's totals.
 */
class ProvisionBridgeTest extends TestCase
{
    use RefreshDatabase;

    private Vessel $vessel;
    private User $office;
    private User $master;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', VesselAccess::MASTER, VesselAccess::COOK] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->vessel = Vessel::create([
            'name' => 'OEL VARUN',
            'imo' => '9414759',
            'crew_size' => 20,
            'previous_crew_size' => 20,
            'supply_period_days' => 30,
            'safety_margin_pct' => 0,
            'budget' => 5000,
            'currency' => 'USD',
            'exchange_rate' => 1,
            'is_active' => true,
        ]);

        $this->office = User::factory()->create([
            'name' => 'Matria Office', 'username' => 'office', 'role' => 'admin', 'is_active' => true,
        ]);
        $this->office->assignRole('admin');
        $this->grantAllPages($this->office);

        $this->master = User::factory()->create([
            'name' => 'Master', 'username' => 'varun-master',
            'role' => VesselAccess::MASTER, 'vessel_id' => $this->vessel->id, 'is_active' => true,
        ]);
        $this->master->assignRole(VesselAccess::MASTER);

        Sanctum::actingAs($this->office);
    }

    /** An order that has been through the chain and is waiting to be bought. */
    private function approved(): Requisition
    {
        $item = Item::create([
            'vessel_id' => $this->vessel->id,
            'category_id' => Category::firstOrCreate(['name' => 'Meat frozen'], ['sort' => 1])->id,
            'code' => '8001000',
            'name' => 'Beef Tenderloin',
            'variant' => 'Norway',
            'unit' => 'Kg',
            'unit_price' => 12.00,
            'current_stock' => 0,
            'baseline_consumption' => 30,
            'baseline_period_days' => 30,
            'is_active' => true,
        ]);

        $req = Requisition::create([
            'vessel_id' => $this->vessel->id,
            'req_number' => 'PRQ-0001',
            'req_date' => now()->toDateString(),
            'days_of_supply' => 30,
            'status' => Requisition::APPROVED,
            'currency' => 'USD',
            'approved_at' => now(),
            'approved_by' => $this->master->id,
            'budget_at_issue' => 5000,
        ]);

        $req->items()->create([
            'item_id' => $item->id,
            'code' => $item->code,
            'description' => 'Beef Tenderloin Norway',
            'unit' => 'Kg',
            'order_qty' => 30,
            'unit_price' => 12.00,
            'line_total' => 360,
        ]);
        $req->recalcTotal();

        return $req->fresh();
    }

    /* ------------------------------ the handover ---------------------------- */

    /**
     * An approved order becomes an ordinary enquiry.
     *
     * The same table, the same screens, the same numbering as the chandling
     * trade. There is no reason to build a second procurement system, and
     * every reason not to.
     */
    public function test_an_approved_order_becomes_an_enquiry(): void
    {
        $req = $this->approved();

        $this->postJson("/api/inventory/requisitions/{$req->id}/to-enquiry?vessel_id={$this->vessel->id}")
            ->assertOk()
            ->assertSee('ask vendors for prices', false);

        $rfq = Rfq::where('requisition_id', $req->id)->first();

        $this->assertNotNull($rfq);
        $this->assertSame(DocumentOrigin::INVENTORY, $rfq->origin);
        $this->assertSame('PRQ-0001', $rfq->reference);
        $this->assertSame('OEL VARUN', $rfq->ship_name);
        $this->assertCount(1, $rfq->items);
        $this->assertSame('Beef Tenderloin Norway', $rfq->items->first()->description);
        $this->assertSame('8001000', $rfq->items->first()->impa_no, "the ship's own code, for checking a quote against");
        $this->assertSame('30.000', $rfq->items->first()->qty);

        // And it is off the office's "to buy" list, so it is not sent out twice.
        $this->assertSame(Requisition::ISSUED, $req->fresh()->status);
    }

    /** A button somebody will double-click must not buy the order twice. */
    public function test_handing_it_over_twice_makes_one_enquiry(): void
    {
        $req = $this->approved();
        $url = "/api/inventory/requisitions/{$req->id}/to-enquiry?vessel_id={$this->vessel->id}";

        $this->postJson($url)->assertOk();
        $this->postJson($url)->assertOk()->assertSee('Already with procurement', false);

        $this->assertSame(1, Rfq::where('requisition_id', $req->id)->count());
    }

    /** Nothing goes to the office until the master has signed it. */
    public function test_an_unapproved_order_cannot_be_handed_over(): void
    {
        $req = $this->approved();
        $req->update(['status' => Requisition::PENDING]);

        $this->postJson("/api/inventory/requisitions/{$req->id}/to-enquiry?vessel_id={$this->vessel->id}")
            ->assertStatus(422)
            ->assertSee('not been approved on board', false);

        $this->assertSame(0, Rfq::count());
    }

    /** Buying is the office's job, not the ship's. */
    public function test_the_crew_cannot_hand_an_order_to_procurement(): void
    {
        $req = $this->approved();
        Sanctum::actingAs($this->master);

        $this->postJson("/api/inventory/requisitions/{$req->id}/to-enquiry?vessel_id={$this->vessel->id}")
            ->assertForbidden();

        $this->assertSame(0, Rfq::count());
    }

    /**
     * The vessel becomes a customer, because that is what she is here.
     *
     * One ordinary customer record means she shows up in every picker,
     * statement and ageing report without a single procurement screen having
     * to learn what a vessel is.
     */
    public function test_the_vessel_is_given_a_customer_record(): void
    {
        $req = $this->approved();

        $this->postJson("/api/inventory/requisitions/{$req->id}/to-enquiry?vessel_id={$this->vessel->id}")
            ->assertOk();

        $customer = Customer::where('name', 'OEL VARUN')->first();

        $this->assertNotNull($customer);
        $this->assertNotNull($customer->customer_no, 'numbered like any other customer');
        $this->assertSame($customer->id, $this->vessel->fresh()->customer_id);
        $this->assertStringContainsString('9414759', $customer->notes);
    }

    /** A fleet with two "OEL VARUN" customers is a statement nobody can read. */
    public function test_a_second_order_reuses_the_same_customer(): void
    {
        $first = $this->approved();
        $this->postJson("/api/inventory/requisitions/{$first->id}/to-enquiry?vessel_id={$this->vessel->id}")->assertOk();

        $second = Requisition::create([
            'vessel_id' => $this->vessel->id,
            'req_number' => 'PRQ-0002',
            'req_date' => now()->toDateString(),
            'status' => Requisition::APPROVED,
            'currency' => 'USD',
        ]);
        $second->items()->create(['description' => 'Rice', 'unit' => 'Kg', 'order_qty' => 10, 'line_total' => 20]);

        $this->postJson("/api/inventory/requisitions/{$second->id}/to-enquiry?vessel_id={$this->vessel->id}")->assertOk();

        $this->assertSame(1, Customer::where('name', 'OEL VARUN')->count());
    }

    /** A customer somebody created by hand first is adopted, not duplicated. */
    public function test_a_customer_created_by_hand_is_adopted(): void
    {
        $existing = Customer::create(['name' => 'OEL VARUN', 'currency' => 'USD']);
        $req = $this->approved();

        $this->postJson("/api/inventory/requisitions/{$req->id}/to-enquiry?vessel_id={$this->vessel->id}")->assertOk();

        $this->assertSame(1, Customer::where('name', 'OEL VARUN')->count());
        $this->assertSame($existing->id, $this->vessel->fresh()->customer_id);
    }

    /* ----------------------------- the two views ---------------------------- */

    /**
     * Everything descended from a provisions enquiry is a provisions document.
     *
     * This is why the origin is inherited rather than set by each controller:
     * one of them would forget, and a provisions invoice would turn up in the
     * chandling accounts.
     */
    public function test_a_documents_origin_comes_down_the_chain(): void
    {
        $req = $this->approved();
        $this->postJson("/api/inventory/requisitions/{$req->id}/to-enquiry?vessel_id={$this->vessel->id}")->assertOk();

        $rfq = Rfq::where('requisition_id', $req->id)->first();
        $vendor = Vendor::create(['name' => 'Singapore Chandler', 'currency' => 'USD']);

        $offer = Offer::create([
            'offer_number' => 'OF-0001', 'rfq_id' => $rfq->id, 'currency' => 'USD', 'status' => 'draft',
        ]);
        $po = PurchaseOrder::create([
            'po_number' => 'PO-0001', 'rfq_id' => $rfq->id, 'vendor_id' => $vendor->id,
            'currency' => 'USD', 'status' => 'draft',
        ]);
        $invoice = CustomerInvoice::create([
            'invoice_number' => 'INV-0001', 'offer_id' => $offer->id, 'currency' => 'USD', 'status' => 'draft',
        ]);

        foreach ([$offer, $po, $invoice] as $document) {
            $this->assertSame(
                DocumentOrigin::INVENTORY,
                $document->fresh()->origin,
                class_basename($document).' should have come down the chain as a provisions document'
            );
        }
    }

    /** Nothing descended from a chandling enquiry changes. */
    public function test_the_chandling_trade_is_untouched(): void
    {
        $rfq = Rfq::create([
            'reference' => 'RFQ-0001', 'priority' => 'normal',
            'received_date' => now()->toDateString(), 'base_currency' => 'USD', 'status' => 'draft',
        ]);

        $offer = Offer::create([
            'offer_number' => 'OF-9001', 'rfq_id' => $rfq->id, 'currency' => 'USD', 'status' => 'draft',
        ]);

        $this->assertSame(DocumentOrigin::PROCUREMENT, $rfq->fresh()->origin);
        $this->assertSame(DocumentOrigin::PROCUREMENT, $offer->fresh()->origin);
    }

    /** A direct sale with no enquiry behind it is the chandling trade's own. */
    public function test_a_document_with_no_parent_is_procurement(): void
    {
        $invoice = CustomerInvoice::create([
            'invoice_number' => 'INV-9001', 'currency' => 'USD', 'status' => 'draft',
        ]);

        $this->assertSame(DocumentOrigin::PROCUREMENT, $invoice->fresh()->origin);
    }

    /**
     * The two views, on one list.
     *
     * No origin asked for is the portal's own view — the whole of what the
     * company does. Asking for the provisions gets the provisions only.
     */
    public function test_the_enquiry_list_can_be_narrowed_to_either_operation(): void
    {
        $req = $this->approved();
        $this->postJson("/api/inventory/requisitions/{$req->id}/to-enquiry?vessel_id={$this->vessel->id}")->assertOk();

        Rfq::create([
            'reference' => 'RFQ-0001', 'priority' => 'normal',
            'received_date' => now()->toDateString(), 'base_currency' => 'USD', 'status' => 'draft',
        ]);

        $all = $this->getJson('/api/portal/rfqs')->assertOk()->json('data');
        $provisions = $this->getJson('/api/portal/rfqs?origin=inventory')->assertOk()->json('data');
        $chandling = $this->getJson('/api/portal/rfqs?origin=procurement')->assertOk()->json('data');

        $this->assertCount(2, $all, 'the portal sees everything');
        $this->assertCount(1, $provisions);
        $this->assertCount(1, $chandling);
        $this->assertSame('PRQ-0001', $provisions[0]['reference']);
        $this->assertSame('RFQ-0001', $chandling[0]['reference']);
    }

    /** A nonsense origin shows the normal screen rather than refusing. */
    public function test_an_unknown_origin_is_treated_as_all_of_it(): void
    {
        Rfq::create([
            'reference' => 'RFQ-0001', 'priority' => 'normal',
            'received_date' => now()->toDateString(), 'base_currency' => 'USD', 'status' => 'draft',
        ]);

        $this->assertCount(1, $this->getJson('/api/portal/rfqs?origin=nonsense')->assertOk()->json('data'));
    }

    /* ------------------------------ the ledger ------------------------------ */

    /**
     * One ledger, two views.
     *
     * The invoice for a ship's provisions and the invoice for a chandling job
     * sit in the same table and the same numbering. Standing at the portal you
     * see both, because that is what the company billed. Standing on the
     * provisions side you see the ship's.
     */
    public function test_the_ledger_can_be_read_either_way(): void
    {
        $customer = Customer::create(['name' => 'OEL VARUN', 'currency' => 'USD']);
        $other = Customer::create(['name' => 'Pacific Shipping', 'currency' => 'USD']);

        $provisionsRfq = Rfq::create([
            'origin' => DocumentOrigin::INVENTORY, 'reference' => 'PRQ-0001', 'priority' => 'normal',
            'received_date' => now()->toDateString(), 'base_currency' => 'USD', 'status' => 'draft',
        ]);

        CustomerInvoice::create([
            'invoice_number' => 'INV-0001', 'rfq_id' => $provisionsRfq->id,
            'customer_id' => $customer->id, 'customer_name' => 'OEL VARUN',
            'currency' => 'USD', 'grand_total' => 360, 'status' => 'issued',
            'issue_date' => now()->toDateString(),
        ]);

        CustomerInvoice::create([
            'invoice_number' => 'INV-0002',
            'customer_id' => $other->id, 'customer_name' => 'Pacific Shipping',
            'currency' => 'USD', 'grand_total' => 5000, 'status' => 'issued',
            'issue_date' => now()->toDateString(),
        ]);

        $everything = LedgerEntries::build('customer');
        $provisions = LedgerEntries::build('customer', null, null, false, DocumentOrigin::INVENTORY);

        $this->assertSame(2, $everything['party_count'], 'the portal sees the whole book');
        $this->assertSame(1, $provisions['party_count']);
        $this->assertSame('OEL VARUN', $provisions['parties'][0]['name']);
        $this->assertSame('INV-0001', $provisions['parties'][0]['entries'][0]['number']);
    }

    /**
     * A master cannot widen the view past his own ship's operation.
     *
     * The gate already keeps him off the fleet's screens; this is the second
     * lock, on the reports themselves.
     */
    public function test_a_crew_account_cannot_ask_for_the_whole_book(): void
    {
        $this->assertSame(
            DocumentOrigin::INVENTORY,
            DocumentOrigin::visibleTo($this->master, null),
            'asking for everything still gets the provisions only'
        );

        $this->assertSame(
            DocumentOrigin::INVENTORY,
            DocumentOrigin::visibleTo($this->master, DocumentOrigin::PROCUREMENT)
        );

        $this->assertNull(
            DocumentOrigin::visibleTo($this->office, null),
            'the office asking for everything gets everything'
        );
    }

    /**
     * The accounting screens are scoped by where you are standing.
     *
     * The buying screens are mounted twice — under /portal, where they show
     * the whole company, and under /inventory, where they show the fleet's.
     * The books behind them have to follow, or the fleet's accounting page
     * would quietly total up the chandling trade under a provisions heading.
     *
     * Worth running the two calls in this order and in one test: the first
     * caught a real bug. Laravel caches the controller instance on the Route
     * object, so the second request reused the first one and was served the
     * portal's view of the books.
     */
    public function test_the_accounting_follows_the_screen_it_is_opened_from(): void
    {
        $vesselCustomer = Customer::create(['name' => 'OEL VARUN', 'currency' => 'USD']);
        $other = Customer::create(['name' => 'Pacific Shipping', 'currency' => 'USD']);

        $provisionsRfq = Rfq::create([
            'origin' => DocumentOrigin::INVENTORY, 'reference' => 'PRQ-0001', 'priority' => 'normal',
            'received_date' => now()->toDateString(), 'base_currency' => 'USD', 'status' => 'draft',
        ]);

        CustomerInvoice::create([
            'invoice_number' => 'INV-0001', 'rfq_id' => $provisionsRfq->id,
            'customer_id' => $vesselCustomer->id, 'customer_name' => 'OEL VARUN',
            'currency' => 'USD', 'exchange_rate' => 1, 'grand_total' => 360, 'subtotal' => 360,
            'status' => 'issued', 'issue_date' => now()->toDateString(),
        ]);

        CustomerInvoice::create([
            'invoice_number' => 'INV-0002',
            'customer_id' => $other->id, 'customer_name' => 'Pacific Shipping',
            'currency' => 'USD', 'exchange_rate' => 1, 'grand_total' => 5000, 'subtotal' => 5000,
            'status' => 'issued', 'issue_date' => now()->toDateString(),
        ]);

        // The stamp first: without it the filter below has nothing to work on.
        $this->assertSame(
            ['INV-0001' => 'inventory', 'INV-0002' => 'procurement'],
            CustomerInvoice::orderBy('id')->pluck('origin', 'invoice_number')->all()
        );

        $numbers = fn (array $rows) => collect($rows)->pluck('number')->filter()->values()->all();

        $portal = $this->getJson('/api/portal/accounting/sales-invoices')->assertOk()->json('data.rows');

        $fleetResponse = $this->getJson('/api/portal/accounting/sales-invoices?origin=inventory')->assertOk();
        $this->assertSame('inventory', $fleetResponse->json('data.origin'), 'the screen knows which view it is');
        $fleet = $fleetResponse->json('data.rows');

        $this->assertContains('INV-0001', $numbers($portal));
        $this->assertContains('INV-0002', $numbers($portal), 'the portal shows the whole company');

        $this->assertContains('INV-0001', $numbers($fleet));
        $this->assertNotContains('INV-0002', $numbers($fleet), "the fleet's pages show the fleet's");
    }

    /** An origin is stamped once and does not move afterwards. */
    public function test_an_origin_is_not_rewritten_later(): void
    {
        $rfq = Rfq::create([
            'origin' => DocumentOrigin::INVENTORY, 'reference' => 'PRQ-0001', 'priority' => 'normal',
            'received_date' => now()->toDateString(), 'base_currency' => 'USD', 'status' => 'draft',
        ]);

        $rfq->update(['status' => 'closed', 'notes' => 'changed my mind about something else']);

        $this->assertSame(DocumentOrigin::INVENTORY, $rfq->fresh()->origin);
    }

    /* ----------------------- booking the delivery in ------------------------ */

    /**
     * The receiving sheet is priced from the quotation, not from the order.
     *
     * An order's own prices are whatever the catalogue held when it was
     * written, and for most lines that is nothing — finding out what they cost
     * is why the order went to vendors at all. Pre-filling the sheet from those
     * blanks opened every batch at zero, and the galley then ate the food for
     * free for the rest of its life.
     */
    public function test_the_receiving_sheet_is_priced_from_the_quotation(): void
    {
        $req = $this->approved();
        $req->items()->update(['unit_price' => null, 'line_total' => 0]);   // nobody knew the price

        $this->postJson("/api/inventory/requisitions/{$req->id}/to-enquiry?vessel_id={$this->vessel->id}")->assertOk();

        $rfq = Rfq::where('requisition_id', $req->id)->firstOrFail();
        $offer = Offer::create([
            'offer_number' => 'OF-6001', 'rfq_id' => $rfq->id, 'customer_id' => $rfq->customer_id,
            'currency' => 'USD', 'status' => 'accepted',
        ]);

        // The vendor quoted 14.25 against the ship's own catalogue code.
        $offer->items()->create([
            'rfq_item_id' => $rfq->items()->first()->id,
            'description' => 'Beef Tenderloin Norway',
            'qty' => 30, 'unit' => 'Kg', 'unit_price' => 14.25,
        ]);

        $sheet = $this->getJson("/api/inventory/requisitions/{$req->id}/receiving-sheet?vessel_id={$this->vessel->id}")
            ->assertOk()
            ->json('data');

        $this->assertSame('OF-6001', $sheet['quotation'], 'the cook is told where the figures came from');
        $this->assertSame(14.25, round((float) $sheet['lines'][0]['unit_price'], 2));
    }

    /** With no quotation yet, the order's own figure is still the best there is. */
    public function test_an_unquoted_order_falls_back_to_its_own_price(): void
    {
        $req = $this->approved();   // its line carries 12.00 from the catalogue

        $sheet = $this->getJson("/api/inventory/requisitions/{$req->id}/receiving-sheet?vessel_id={$this->vessel->id}")
            ->assertOk()
            ->json('data');

        $this->assertNull($sheet['quotation']);
        $this->assertSame(12.0, round((float) $sheet['lines'][0]['unit_price'], 2));
    }

    /** A line quoted at nothing is not a price, and must not overwrite one. */
    public function test_a_line_quoted_at_zero_is_left_alone(): void
    {
        $req = $this->approved();
        $this->postJson("/api/inventory/requisitions/{$req->id}/to-enquiry?vessel_id={$this->vessel->id}")->assertOk();

        $rfq = Rfq::where('requisition_id', $req->id)->firstOrFail();
        $offer = Offer::create([
            'offer_number' => 'OF-6002', 'rfq_id' => $rfq->id, 'customer_id' => $rfq->customer_id,
            'currency' => 'USD', 'status' => 'draft',
        ]);
        $offer->items()->create([
            'rfq_item_id' => $rfq->items()->first()->id,
            'description' => 'Beef Tenderloin Norway',
            'qty' => 30, 'unit' => 'Kg', 'unit_price' => 0,
        ]);

        $sheet = $this->getJson("/api/inventory/requisitions/{$req->id}/receiving-sheet?vessel_id={$this->vessel->id}")
            ->assertOk()
            ->json('data');

        $this->assertNull($sheet['quotation'], 'nothing was actually quoted');
        $this->assertSame(12.0, round((float) $sheet['lines'][0]['unit_price'], 2));
    }

    /**
     * A receiving sheet with an order and a quotation behind it.
     *
     * @return array{0: \App\Models\Inventory\Receipt, 1: Item}
     */
    private function sheet(): array
    {
        $req = $this->approved();
        $item = Item::where('vessel_id', $this->vessel->id)->firstOrFail();

        $receipt = \App\Models\Inventory\Receipt::create([
            'vessel_id' => $this->vessel->id,
            'requisition_id' => $req->id,
            'reference' => $req->req_number,
            'received_date' => now()->toDateString(),
            'status' => 'draft',
        ]);

        $receipt->items()->create([
            'item_id' => $item->id,
            'ordered_qty' => 30,
            'received_qty' => 30,
            'unit_price' => 14.25,   // off the quotation
            'sort' => 0,
        ]);

        return [$receipt, $item];
    }

    /**
     * The ship counts. She does not retype what was ordered or what it cost.
     *
     * Both columns are read-only on her screen, which stops an honest mistake
     * and nothing else — the save rebuilds every line from the payload, so
     * whatever the browser sends is what lands. Hence the rule here rather than
     * only in the markup.
     *
     * Editing the ordered quantity is the one that matters: a short delivery
     * tidied away by moving the order down to meet it leaves no trace of the
     * shortfall, and the Difference column exists for nothing else.
     */
    public function test_the_ship_cannot_retype_what_was_ordered_or_what_it_cost(): void
    {
        [$receipt, $item] = $this->sheet();

        Sanctum::actingAs($this->master);

        $this->putJson("/api/inventory/receipts/{$receipt->id}?vessel_id={$this->vessel->id}", [
            'reference' => 'PRQ-0001',
            'items' => [[
                'item_id' => $item->id,
                'ordered_qty' => 24,     // "we only ever ordered 24"
                'received_qty' => 24,    // 6 short, which is the true part
                'unit_price' => 1.00,    // and a price nobody quoted
            ]],
        ])->assertOk();

        $line = $receipt->fresh('items')->items->first();

        $this->assertSame(24.0, (float) $line->received_qty, 'the count is hers to give');
        $this->assertSame(30.0, (float) $line->ordered_qty, 'the order is not');
        $this->assertSame(14.25, round((float) $line->unit_price, 2), 'neither is the price');
    }

    /**
     * The office holds the invoice, so the office may correct a price.
     *
     * Not the ordered quantity, though. That is what the order said, whoever is
     * looking at it.
     */
    public function test_the_office_may_correct_a_price_against_the_invoice(): void
    {
        [$receipt, $item] = $this->sheet();

        $this->putJson("/api/inventory/receipts/{$receipt->id}?vessel_id={$this->vessel->id}", [
            'reference' => 'PRQ-0001',
            'items' => [[
                'item_id' => $item->id,
                'ordered_qty' => 24,
                'received_qty' => 30,
                'unit_price' => 15.10,   // the invoice came in higher
            ]],
        ])->assertOk();

        $line = $receipt->fresh('items')->items->first();

        $this->assertSame(15.10, round((float) $line->unit_price, 2));
        $this->assertSame(30.0, (float) $line->ordered_qty);
    }

    /**
     * A line added at the gangway has no order and no quotation behind it, so
     * there is nothing to protect and it takes the figures it was given.
     */
    public function test_a_hand_added_line_keeps_what_it_was_sent(): void
    {
        [$receipt, $item] = $this->sheet();

        $extra = Item::create([
            'vessel_id' => $this->vessel->id,
            'category_id' => $item->category_id,
            'code' => '8001001', 'name' => 'Onions', 'unit' => 'Kg',
            'unit_price' => 2.50, 'current_stock' => 0,
            'baseline_consumption' => 10, 'baseline_period_days' => 30, 'is_active' => true,
        ]);

        Sanctum::actingAs($this->master);

        $this->putJson("/api/inventory/receipts/{$receipt->id}?vessel_id={$this->vessel->id}", [
            'reference' => 'PRQ-0001',
            'items' => [
                ['item_id' => $item->id, 'ordered_qty' => 30, 'received_qty' => 30, 'unit_price' => 14.25],
                ['item_id' => $extra->id, 'ordered_qty' => 0, 'received_qty' => 5, 'unit_price' => 2.50],
            ],
        ])->assertOk();

        $added = $receipt->fresh('items')->items->firstWhere('item_id', $extra->id);

        $this->assertNotNull($added, 'the ship can still book in what turned up');
        $this->assertSame(5.0, (float) $added->received_qty);
        $this->assertSame(0.0, (float) $added->ordered_qty, 'nothing was ordered, so nothing is owed');
        $this->assertSame(2.50, round((float) $added->unit_price, 2));
    }

    /**
     * Once the office has quoted, the master reads the quotation on his order.
     *
     * The gap this closes, in the owner's words: *"i logged in as master why i
     * cant see the qouted prices lol so we can adjust the items to be bought
     * that exceeds the budget."* He had approved the order, the office had come
     * back at $46,226 against a $5,000 month, and his screen showed him four
     * lines and no figure — so the one person answerable for the spend was the
     * only one who could not see it.
     *
     * The requisition's OWN prices stay hidden either way. Those are last
     * month's catalogue and mostly blank. A quotation is what the vessel will
     * be invoiced, which is a different kind of number entirely.
     */
    public function test_the_master_reads_the_quotation_on_his_order(): void
    {
        $req = $this->approved();
        $this->postJson("/api/inventory/requisitions/{$req->id}/to-enquiry?vessel_id={$this->vessel->id}")->assertOk();

        $rfq = Rfq::where('requisition_id', $req->id)->firstOrFail();
        $offer = Offer::create([
            'offer_number' => 'OF-7001', 'rfq_id' => $rfq->id, 'customer_id' => $rfq->customer_id,
            'currency' => 'USD', 'status' => 'accepted',
            'grand_total' => 46226.00,
        ]);
        $offer->items()->create([
            'rfq_item_id' => $rfq->items()->first()->id,
            'description' => 'Beef Tenderloin Norway',
            'qty' => 30, 'unit' => 'Kg', 'unit_price' => 90.00,
        ]);

        Sanctum::actingAs($this->master);

        $seen = $this->getJson("/api/inventory/requisitions/{$req->id}?vessel_id={$this->vessel->id}")
            ->assertOk()->json('data');

        $this->assertSame('OF-7001', $seen['quotation']['number']);
        $this->assertSame('accepted', $seen['quotation']['status']);
        $this->assertSame(46226.0, round((float) $seen['quotation']['total'], 2));
        $this->assertSame(90.0, round((float) $seen['quotation']['prices']['8001000'], 2), 'priced by the ship\'s own code');

        // And he is told what that means against the ship's budget, which is
        // the point of showing him at all.
        $this->assertSame('over', $seen['quotation']['budget']['level']);

        // The order's own prices are still none of the ship's business.
        $this->assertNull($seen['requisition']['items'][0]['unit_price']);

        // The cook sees no quotation at all.
        $cook = User::factory()->create([
            'name' => 'Cook', 'username' => 'varun-cook',
            'role' => VesselAccess::COOK, 'vessel_id' => $this->vessel->id, 'is_active' => true,
        ]);
        $cook->assignRole(VesselAccess::COOK);
        Sanctum::actingAs($cook);

        $his = $this->getJson("/api/inventory/requisitions/{$req->id}?vessel_id={$this->vessel->id}")
            ->assertOk()->json('data');

        $this->assertNull($his['quotation']);
    }

    /** No quotation yet is not an empty one — the block is simply absent. */
    public function test_an_unquoted_order_carries_no_quotation_block(): void
    {
        $req = $this->approved();

        Sanctum::actingAs($this->master);

        $seen = $this->getJson("/api/inventory/requisitions/{$req->id}?vessel_id={$this->vessel->id}")
            ->assertOk()->json('data');

        $this->assertNull($seen['quotation']);
    }

    /**
     * An order trimmed ashore is counted against what was bought, not against
     * what was asked for.
     *
     * The owner's workflow when a quotation comes back over budget: the office
     * revises the offer. Nothing in the module stopped that — but the receiving
     * sheet went on taking its quantities from the requisition, so a 500 Kg
     * request cut to 50 Kg arrived in full and was recorded as 450 Kg short.
     * A shortfall that never happened, against a chandler who delivered
     * everything he was asked for, on the ship's permanent record.
     *
     * Both figures are kept. What the ship asked for is hers and she is shown
     * it — that is the transparency half — but the count is against what was
     * actually bought.
     */
    public function test_a_trimmed_order_is_received_against_what_was_bought(): void
    {
        $req = $this->approved();   // 30 Kg of beef
        $this->postJson("/api/inventory/requisitions/{$req->id}/to-enquiry?vessel_id={$this->vessel->id}")->assertOk();

        $rfq = Rfq::where('requisition_id', $req->id)->firstOrFail();
        $offer = Offer::create([
            'offer_number' => 'OF-8001', 'rfq_id' => $rfq->id, 'customer_id' => $rfq->customer_id,
            'currency' => 'USD', 'status' => 'accepted',
        ]);

        // Too dear, so the office bought 8 of the 30.
        $offer->items()->create([
            'rfq_item_id' => $rfq->items()->first()->id,
            'description' => 'Beef Tenderloin Norway',
            'qty' => 8, 'unit' => 'Kg', 'unit_price' => 14.25,
        ]);

        $sheet = $this->getJson("/api/inventory/requisitions/{$req->id}/receiving-sheet?vessel_id={$this->vessel->id}")
            ->assertOk()->json('data');

        $this->assertSame(30.0, round((float) $sheet['lines'][0]['asked_qty'], 3), 'what she asked for survives');
        $this->assertSame(8.0, round((float) $sheet['lines'][0]['ordered_qty'], 3), 'what was bought is what she counts against');
        $this->assertSame(8.0, round((float) $sheet['lines'][0]['received_qty'], 3));

        // And the sheet the ship then opens says the same, whatever the browser
        // sent on the way in.
        $item = Item::where('vessel_id', $this->vessel->id)->firstOrFail();

        $created = $this->postJson("/api/inventory/receipts?vessel_id={$this->vessel->id}", [
            'requisition_id' => $req->id,
            'reference' => $req->req_number,
            'items' => [[
                'item_id' => $item->id,
                'ordered_qty' => 30,     // a stale browser, or a stale draft
                'received_qty' => 8,
            ]],
        ])->assertCreated()->json('data.receipt.id');

        $saved = $this->getJson("/api/inventory/receipts/{$created}?vessel_id={$this->vessel->id}")
            ->assertOk()->json('data.receipt');

        $this->assertSame(8.0, round((float) $saved['items'][0]['ordered_qty'], 3), 'the server decides, not the payload');
        $this->assertSame(30.0, round((float) $saved['items'][0]['asked_qty'], 3));
        $this->assertSame(14.25, round((float) $saved['items'][0]['unit_price'], 2));
    }

    /** With nothing trimmed, asked and bought are the same figure. */
    public function test_an_untrimmed_order_asks_and_buys_alike(): void
    {
        $req = $this->approved();

        $sheet = $this->getJson("/api/inventory/requisitions/{$req->id}/receiving-sheet?vessel_id={$this->vessel->id}")
            ->assertOk()->json('data');

        $this->assertSame(30.0, round((float) $sheet['lines'][0]['asked_qty'], 3));
        $this->assertSame(30.0, round((float) $sheet['lines'][0]['ordered_qty'], 3));
    }

    /* ------------------------ the quotation goes back ----------------------- */

    /**
     * A vessel's quotation is emailed to her master.
     *
     * The customer record standing for a ship is made from her name and carries
     * no address, so this used to refuse with "this customer has no valid email
     * on file" — true, and no help to anybody. Her master is the address: he
     * approved the order this quotation answers, and he is answerable for what
     * she spends.
     */
    public function test_a_vessels_quotation_is_emailed_to_her_master(): void
    {
        Mail::fake();

        $offer = $this->provisionsOffer();

        $this->postJson("/api/portal/offers/{$offer->id}/email")
            ->assertOk()
            ->assertSee($this->master->email, false);

        Mail::assertSent(
            OfferMail::class,
            fn ($mail) => $mail->hasTo($this->master->email)
        );
    }

    /** The chandling trade is untouched: its customers have real addresses. */
    public function test_a_chandling_quotation_still_goes_to_the_customer(): void
    {
        Mail::fake();

        $customer = Customer::create([
            'name' => 'Pacific Shipping', 'email' => 'buyer@pacific.example', 'currency' => 'USD', 'is_active' => true,
        ]);

        $rfq = Rfq::create([
            'reference' => 'RFQ-7001', 'customer_id' => $customer->id, 'priority' => 'normal',
            'received_date' => now()->toDateString(), 'base_currency' => 'USD', 'status' => 'draft',
        ]);

        $offer = Offer::create([
            'offer_number' => 'OF-7001', 'rfq_id' => $rfq->id, 'customer_id' => $customer->id,
            'currency' => 'USD', 'status' => 'draft',
        ]);

        $this->postJson("/api/portal/offers/{$offer->id}/email")->assertOk();

        Mail::assertSent(OfferMail::class, fn ($mail) => $mail->hasTo('buyer@pacific.example'));
    }

    /** No master aboard, and the refusal says what to do about it. */
    public function test_a_vessel_with_no_master_says_so(): void
    {
        Mail::fake();

        $this->master->delete();
        $offer = $this->provisionsOffer();

        $this->postJson("/api/portal/offers/{$offer->id}/email")
            ->assertStatus(422)
            ->assertSee('OEL VARUN has no master account', false);

        Mail::assertNothingSent();
    }

    /** An inactive account is not an address either. */
    public function test_a_deactivated_master_is_not_written_to(): void
    {
        Mail::fake();

        $this->master->update(['is_active' => false]);
        $offer = $this->provisionsOffer();

        $this->postJson("/api/portal/offers/{$offer->id}/email")->assertStatus(422);

        Mail::assertNothingSent();
    }

    /** An approved order carried through to a quotation, the way the office does it. */
    private function provisionsOffer(): Offer
    {
        $req = $this->approved();
        $this->postJson("/api/inventory/requisitions/{$req->id}/to-enquiry?vessel_id={$this->vessel->id}")->assertOk();

        $rfq = Rfq::where('requisition_id', $req->id)->firstOrFail();

        return Offer::create([
            'offer_number' => 'OF-5001',
            'rfq_id' => $rfq->id,
            'customer_id' => $rfq->customer_id,
            'currency' => 'USD',
            'status' => 'draft',
        ]);
    }
}

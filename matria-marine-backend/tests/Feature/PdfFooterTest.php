<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * One footer, on every document Matria sends out.
 *
 * Dru asked for it in a line: "kindly fix all the footers kay lahi lahi og
 * format, dapat uniform tanan." He was right on every count. There were three
 * different footer layouts across four documents, and the other four —
 * delivery order, purchase order, final invoice, return note — carried no
 * footer at all. The return note did not even receive the company details, so
 * it went to vendors with nothing on it identifying who had sent it.
 *
 * The same company looked like three companies depending on which paper you
 * were holding. These tests hold it to one.
 */
class PdfFooterTest extends TestCase
{
    use RefreshDatabase;

    /** Everything that goes out of the building. */
    private const DOCUMENTS = [
        'offer',
        'proforma-invoice',
        'customer-invoice',
        'credit-memo',
        'delivery-order',
        'purchase-order',
        'final-invoice',
        'return-note',
        'statement',
        'document',
    ];

    /** Only where the customer is being asked to pay us. */
    private const ASK_FOR_MONEY = ['customer-invoice', 'proforma-invoice'];

    /**
     * Documents that decide at render time.
     *
     * The statement goes to customers AND vendors off the same template: to a
     * customer it is a demand for payment, to a vendor it is a list of what we
     * owe them, so the bank line follows $isCustomer rather than being fixed.
     */
    private const ASK_CONDITIONALLY = ['statement'];

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('admin', 'web');
        $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $user->assignRole('admin');
        $this->grantAllPages($user);
        Sanctum::actingAs($user);
    }

    /**
     * Every template includes the shared footer, and defines none of its own.
     *
     * Read off the source, because this is the property that decayed: each
     * document grew its own copy, and the copies drifted apart one edit at a
     * time. A template that writes its own footer markup or its own page margin
     * has started down that road again.
     */
    public function test_every_document_uses_the_one_shared_footer(): void
    {
        foreach (self::DOCUMENTS as $document) {
            $blade = file_get_contents(resource_path("views/pdf/{$document}.blade.php"));

            $this->assertStringContainsString(
                "@include('pdf.partials.footer'",
                $blade,
                "{$document} does not include the shared footer"
            );
            $this->assertStringContainsString(
                "@include('pdf.partials.footer-css')",
                $blade,
                "{$document} does not include the shared footer styling"
            );

            // Its own copy of either half is how they drifted the first time.
            $this->assertStringNotContainsString(
                '<div class="page-footer">',
                $blade,
                "{$document} still writes its own footer"
            );
            $this->assertDoesNotMatchRegularExpression(
                '/^\s*@page\s*\{/m',
                $blade,
                "{$document} sets its own page margin, which the footer has to fit inside"
            );
        }
    }

    /** The bank line goes only on the documents that ask to be paid. */
    public function test_only_the_documents_asking_for_money_print_bank_details(): void
    {
        foreach (self::DOCUMENTS as $document) {
            $blade = file_get_contents(resource_path("views/pdf/{$document}.blade.php"));

            if (in_array($document, self::ASK_CONDITIONALLY, true)) {
                $this->assertMatchesRegularExpression(
                    "/'bank' => \\\$/",
                    $blade,
                    "{$document} should decide the bank line from the recipient"
                );

                continue;
            }

            $this->assertSame(
                in_array($document, self::ASK_FOR_MONEY, true),
                str_contains($blade, "'bank' => true"),
                "{$document} prints bank details when it should not, or the other way round"
            );
        }
    }

    /**
     * Rendered, not just included — covering the two the local data has no
     * example of, which is exactly how they came to be missed.
     */
    public function test_the_delivery_order_and_return_note_carry_the_footer(): void
    {
        $company = config('procurement.company');

        $order = \App\Models\DeliveryOrder::create([
            'do_number' => 'MMS-DO-2026-000600',
            'customer_id' => Customer::factory()->create()->id,
            'customer_name' => 'Thomas Schulte Crew Management',
            'currency' => 'USD',
            'status' => 'issued',
        ]);
        $order->items()->create([
            'description' => 'Provisions', 'unit' => 'ctn', 'qty' => 2,
            'unit_price' => 100, 'line_total' => 200,
        ]);
        $order->load('items');

        $vendor = Vendor::factory()->create();
        $po = \App\Models\PurchaseOrder::create([
            'po_number' => 'MMS-PO-2026-000600',
            'vendor_id' => $vendor->id,
            'currency' => 'USD',
            'exchange_rate' => 1,
            'status' => 'received',
            'issued_date' => '2026-06-15',
            'subtotal' => 500,
        ]);

        $note = \App\Models\ReturnNote::create([
            'rtn_number' => 'MMS-RTN-2026-000600',
            'purchase_order_id' => $po->id,
            'vendor_id' => $vendor->id,
            'vendor_name' => 'Tropical Ship Supply',
            'currency' => 'USD',
            'status' => 'issued',
            'return_date' => '2026-06-20',
            'subtotal' => 150,
        ]);
        $note->items()->create([
            'description' => 'Damaged carton', 'qty' => 1,
            'unit_cost' => 150, 'line_total' => 150,
        ]);
        $note->load('items');

        foreach ([
            ['delivery-order', ['do' => $order, 'company' => $company, 'logo' => null]],
            ['return-note', ['rn' => $note, 'company' => $company, 'logo' => null]],
        ] as [$view, $data]) {
            $html = view("pdf.{$view}", $data)->render();

            $this->assertStringContainsString('class="page-footer"', $html, "{$view} renders no footer");
            $this->assertStringContainsString($company['uen'], $html, "{$view} does not print the UEN");
            $this->assertStringContainsString($company['email'], $html, "{$view} does not print the email");
            $this->assertStringNotContainsString('SWIFT:', $html, "{$view} should not carry bank details");
        }
    }

    /**
     * The address collapses to one line without doubling up its punctuation.
     *
     * The stored address already ends some of its lines with a comma, so
     * joining them with ", " produced "…#06-29 Unit B,, Pantech Business Hub".
     */
    public function test_the_address_reads_as_one_clean_line(): void
    {
        $html = view('pdf.partials.footer', [
            'company' => ['name' => 'X', 'address' => "12 Some Road, #01-02 Unit B,\nA Business Hub\nSingapore 128381"],
            'bank' => false,
        ])->render();

        $this->assertStringContainsString('12 Some Road, #01-02 Unit B, A Business Hub, Singapore 128381', $html);
        $this->assertStringNotContainsString(',,', $html);
    }

    /** The invoice keeps its bank details — customers need them to pay. */
    public function test_the_invoice_footer_still_carries_the_bank_details(): void
    {
        $invoice = CustomerInvoice::create([
            'invoice_number' => 'MMS-INV-2026-000600',
            'customer_id' => Customer::factory()->create()->id,
            'currency' => 'SGD',
            'status' => 'sent',
            'issue_date' => '2026-06-15',
        ]);
        $invoice->items()->create([
            'description' => 'Services', 'qty' => 1, 'unit' => 'lot',
            'unit_price' => 1000, 'line_total' => 1000,
        ]);
        $invoice->recalcTotals();

        $company = config('procurement.company');
        $html = view('pdf.customer-invoice', [
            'invoice' => $invoice, 'company' => $company, 'logo' => null, 'due' => null, 'fx' => null,
        ])->render();

        $this->assertStringContainsString($company['bank_name'], $html);
        $this->assertStringContainsString($company['bank_account'], $html);
        $this->assertStringContainsString($company['bank_swift'], $html);
    }

    /**
     * The statement of accounts prints our bank details to a customer, and
     * never to a vendor.
     *
     * It was the ninth document, and the one that got away: it goes out to
     * customers asking to be paid, wrote its own third footer format (no
     * address, no phone, "UEN No." and "Account number:" where every other
     * document says "UEN:" and "Account:"), and printed our account number on
     * vendor statements too — flatly contradicting the "Please remit to" block
     * in its own body, which it correctly hides from vendors.
     */
    public function test_the_statement_prints_bank_details_to_customers_only(): void
    {
        $company = config('procurement.company');

        $party = Customer::factory()->create(['name' => 'Thomas Schulte Crew Management']);
        $line = [
            'number' => 'MMS-INV-2026-000700', 'reference' => 'PO-4499283', 'vessel' => 'MV Test One',
            'date' => '15.06.2026', 'due_date' => '15.07.2026', 'overdue_days' => 0,
            'currency' => 'SGD', 'amount' => 1000.0, 'settled' => 0.0, 'outstanding' => 1000.0,
        ];

        foreach ([true, false] as $isCustomer) {
            $html = view('pdf.statement', [
                'party' => $party,
                'isCustomer' => $isCustomer,
                'lines' => [$line],
                'totals' => [['currency' => 'SGD', 'outstanding' => 1000.0]],
                'payments' => [],
                'aging' => ['current' => 1000.0, 'd30' => 0.0, 'd60' => 0.0, 'd90' => 0.0],
                'showAging' => $isCustomer,
                'multiCurrency' => false,
                'singleCurrency' => 'SGD',
                'periodLabel' => 'All open items',
                'periodSentence' => '',
                'issuedOn' => '15.10.2026',
                'company' => $company,
                'logo' => null,
            ])->render();

            // The one shared footer, not its own: address and phone, which the
            // old one left off entirely.
            $this->assertStringContainsString('class="page-footer"', $html);
            $this->assertStringContainsString('UEN: '.$company['uen'], $html);
            $this->assertStringContainsString($company['phone'], $html);
            $this->assertStringContainsString('Pandan Loop', $html);

            $who = $isCustomer ? 'customer' : 'vendor';
            $this->assertSame(
                $isCustomer,
                str_contains($html, 'SWIFT: '.$company['bank_swift']),
                "the {$who} statement gets the bank line wrong"
            );
        }
    }

    /** And the PDF itself still builds, which is the thing that matters. */
    public function test_the_invoice_pdf_still_downloads(): void
    {
        $invoice = CustomerInvoice::create([
            'invoice_number' => 'MMS-INV-2026-000601',
            'customer_id' => Customer::factory()->create()->id,
            'currency' => 'SGD',
            'status' => 'sent',
            'issue_date' => '2026-06-15',
        ]);
        $invoice->items()->create([
            'description' => 'Services', 'qty' => 1, 'unit' => 'lot',
            'unit_price' => 1000, 'line_total' => 1000,
        ]);
        $invoice->recalcTotals();

        $pdf = $this->get("/api/portal/invoices/{$invoice->id}/pdf")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->getContent();

        $this->assertStringStartsWith('%PDF', $pdf);
    }
}

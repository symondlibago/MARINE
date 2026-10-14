<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Numbered items on the customer-facing documents.
 *
 * Dru asked for it on the quotation and then for the same on invoices,
 * delivery orders and credit notes: an "Item" column, with a number against
 * every line. Without it a customer writing "please supply item 3" is pointing
 * at a line nobody else can identify.
 *
 * The rule the whole thing turns on is that it counts ITEMS, not rows. A
 * heading is a label over a group of lines, not something anybody orders, so
 * it takes no number. Number the rows instead and a quotation with one heading
 * reads 1, 3, 4 — and then their item 3 is our item 4, which is worse than
 * having no numbers at all.
 */
class PdfItemNumbersTest extends TestCase
{
    use RefreshDatabase;

    /** The documents Dru named, plus the proforma, which is the same family. */
    private const DOCUMENTS = [
        'offer',             // the quotation he sent the screenshot of
        'customer-invoice',
        'delivery-order',
        'credit-memo',
        'proforma-invoice',
    ];

    private function staff(): void
    {
        Role::findOrCreate('admin', 'web');
        $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $user->assignRole('admin');
        $this->grantAllPages($user);
        Sanctum::actingAs($user);
    }

    /** An invoice whose second line is a heading, so numbering has to step over it. */
    private function invoiceWithHeading(): CustomerInvoice
    {
        $this->staff();

        $invoice = CustomerInvoice::create([
            'invoice_number' => 'MMS-INV-2026-000500',
            'customer_id' => Customer::factory()->create()->id,
            'currency' => 'USD',
            'status' => 'sent',
            'issue_date' => '2026-10-14',
        ]);

        $lines = [
            ['description' => 'Hull photo inspection', 'qty' => 1, 'unit_price' => 100],
            ['description' => 'UNDERWATER SERVICES', 'is_heading' => true, 'qty' => 0, 'unit_price' => 0],
            ['description' => 'Single propeller polishing', 'qty' => 1, 'unit_price' => 200],
            ['description' => 'Vertical sides cleaning', 'qty' => 1, 'unit_price' => 300],
        ];

        foreach ($lines as $line) {
            $invoice->items()->create($line + [
                'unit' => 'lot',
                'line_total' => (float) $line['qty'] * (float) $line['unit_price'],
            ]);
        }
        $invoice->recalcTotals();

        return $invoice;
    }

    /** The same view data the controller builds, minus the logo file. */
    private function viewData(CustomerInvoice $invoice, ?array $fx = null): array
    {
        return [
            'invoice' => $invoice,
            'company' => config('procurement.company'),
            'logo' => null,
            'due' => $invoice->due_date ? \Illuminate\Support\Carbon::parse($invoice->due_date) : null,
            'fx' => $fx,
        ];
    }

    /** The Item cells of a rendered template, in the order they appear. */
    private function itemColumn(string $html): array
    {
        preg_match_all('/<td class="itemno">([^<]*)<\/td>/', $html, $matches);

        return array_map('trim', $matches[1]);
    }

    /**
     * Every document Dru named carries the column, and carries it the same way.
     *
     * Read off the templates rather than rendered, because building a realistic
     * offer, delivery order and credit note here would test the fixtures more
     * than the change. What this catches is the one that gets forgotten — a
     * sixth customer-facing document added later without it.
     */
    public function test_every_customer_facing_document_has_an_item_column(): void
    {
        foreach (self::DOCUMENTS as $document) {
            $blade = file_get_contents(resource_path("views/pdf/{$document}.blade.php"));

            $this->assertStringContainsString('>Item</th>', $blade, "{$document} has no Item column header");
            $this->assertStringContainsString('class="itemno"', $blade, "{$document} prints no item number");
            $this->assertStringContainsString('$itemNo', $blade, "{$document} does not count items");
            // The counter must be guarded by the heading check somewhere, or it
            // is counting rows.
            $this->assertMatchesRegularExpression(
                '/is_heading|isHeading/',
                $blade,
                "{$document} numbers every row, headings included"
            );
        }
    }

    /**
     * The case that matters. Four lines, one of them a heading, must number
     * 1, 2, 3 — not 1, 3, 4, and not 1, 2, 3, 4 with a number on the heading.
     */
    public function test_a_heading_does_not_take_an_item_number(): void
    {
        $invoice = $this->invoiceWithHeading();

        $html = view('pdf.customer-invoice', $this->viewData($invoice))->render();

        $this->assertSame(['1', '2', '3'], $this->itemColumn($html));

        // And the heading is still printed — skipped for numbering, not dropped
        // from the page.
        $this->assertStringContainsString('UNDERWATER SERVICES', $html);
    }

    public function test_the_numbers_survive_a_second_currency(): void
    {
        $invoice = $this->invoiceWithHeading();

        // The dual-currency layout adds a column on the right. Item is the
        // first column either way, so the numbers must not move.
        $html = view('pdf.customer-invoice', $this->viewData($invoice, ['code' => 'AED', 'rate' => 3.6725]))->render();

        $this->assertSame(['1', '2', '3'], $this->itemColumn($html));
    }

    /**
     * The delivery order and the proforma drawn from it.
     *
     * Rendered rather than read, because these two are the ones the local data
     * has no example of — nothing else in the suite draws them, so an extra
     * column that broke the layout would have gone out unnoticed. Neither
     * delivery_order_items nor credit_memo_items has an is_heading column, so
     * every line here is a real item and the numbers simply run 1, 2, 3.
     */
    public function test_the_delivery_order_and_its_proforma_number_their_items(): void
    {
        $this->staff();

        $order = \App\Models\DeliveryOrder::create([
            'do_number' => 'MMS-DO-2026-000500',
            'customer_id' => Customer::factory()->create()->id,
            'customer_name' => 'Thomas Schulte Crew Management',
            'currency' => 'USD',
            'status' => 'issued',
        ]);

        foreach ([['Provisions — dry store', 4], ['Provisions — frozen', 2], ['Bonded stores', 1]] as [$what, $qty]) {
            $order->items()->create([
                'description' => $what,
                'unit' => 'ctn',
                'qty' => $qty,
                'unit_price' => 100,
                'line_total' => $qty * 100,
            ]);
        }
        $order->load('items');

        $company = config('procurement.company');

        $note = view('pdf.delivery-order', ['do' => $order, 'company' => $company, 'logo' => null])->render();
        $this->assertSame(['1', '2', '3'], $this->itemColumn($note));

        $proforma = view('pdf.proforma-invoice', [
            'pf' => \App\Support\ProformaDoc::fromDeliveryOrder($order),
            'company' => $company,
            'logo' => null,
        ])->render();
        $this->assertSame(['1', '2', '3'], $this->itemColumn($proforma));
    }

    public function test_the_invoice_pdf_still_downloads(): void
    {
        $invoice = $this->invoiceWithHeading();

        $pdf = $this->get("/api/portal/invoices/{$invoice->id}/pdf")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->getContent();

        $this->assertStringStartsWith('%PDF', $pdf);
    }

    /**
     * Every row has to have as many cells as the header, or the table shears
     * sideways and the amounts print under the wrong captions — which is the
     * way an extra column usually goes wrong.
     */
    public function test_the_header_and_every_row_line_up(): void
    {
        $invoice = $this->invoiceWithHeading();

        foreach ([null, ['code' => 'AED', 'rate' => 3.6725]] as $fx) {
            $html = view('pdf.customer-invoice', $this->viewData($invoice, $fx))->render();

            preg_match('/<thead>(.*?)<\/thead>/s', $html, $head);
            $headerCells = preg_match_all('/<th/', $head[1]);
            $this->assertSame($fx ? 7 : 6, $headerCells);

            preg_match('/<tbody>(.*?)<\/tbody>/s', $html, $body);

            foreach (explode('<tr>', $body[1]) as $row) {
                if (! str_contains($row, '<td')) {
                    continue;
                }

                // A heading row spans the whole table on purpose.
                if (preg_match('/colspan="(\d+)"/', $row, $span)) {
                    $this->assertSame($headerCells, (int) $span[1], 'a heading row must span the whole table');

                    continue;
                }

                $this->assertSame($headerCells, preg_match_all('/<td/', $row), 'a row does not match the header');
            }
        }
    }
}

<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Offer;
use App\Models\Rfq;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The first page carries content.
 *
 * Dru: "so much space and nasayang sa page 1 — I need to cut some lines para
 * niya ma utilize ang first page." A towage quotation has one line item whose
 * description runs to twenty-odd lines of terms. dompdf cannot split a table
 * cell across a page, so the whole row was pushed to page 2 and page 1 was
 * left with a column header and half a page of white.
 */
class PdfPageUseTest extends TestCase
{
    use RefreshDatabase;

    /** The real thing, off the quotation Dru sent back. */
    private const LONG_DESCRIPTION = <<<'TXT'
    Two tug towage from Yalova Yard to Tuzla Shipyard, Türkiye

    Tug 1: TBN - 31 tonnes bollard pull.
    Tug 2: TBN - 11 tonnes bollard pull.
    Subject to availability and final technical and
    authority approval.

    **Towage fee:** USD 50,000 lump sum.

    **Free time:** A total of 6 hours, reversible between
    departure and arrival.

    **Port demurrage:** USD 20,000 per day, pro rata,
    total for both tugs.

    **Anchor demurrage:** USD 25,000 per day, pro rata, total for both tugs

    **Drift demurrage:** USD 25,000 per day, pro rata, total for both tugs

    All demurrage rates apply pro rata for fractions of a
    day, based on actual elapsed time.
    TXT;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('admin', 'web');
        $this->staff = User::factory()->create([
            'name' => 'Matria Admin', 'role' => 'admin', 'is_active' => true,
            'phone' => '(65) 91295283',
        ]);
        $this->staff->assignRole('admin');
        $this->grantAllPages($this->staff);
        Sanctum::actingAs($this->staff);
    }

    /** A separate field on the line, printed under the description. */
    private const REMARKS = 'All agency fees, port charges, pilotage, assisting tug charges, '
        ."permits, dues, taxes and other related expenses for the tug and towed unit shall be\n"
        ."for the Charterer's account and are excluded from the lump sum.";

    private function towageOffer(): Offer
    {
        $customer = Customer::factory()->create([
            'name' => 'ETHRA 1 (IMO 9414759)',
            'address' => "To the Owners and/or Master of the MV ETHRA 1\nETHRA SHIPPING COMPANY SPV1\n"
                ."P.O. Box 10008\nWillow House Cricket Square Grand Cayman KY1 - 1001\nCayman Islands\n"
                ."REG.# CO-376760\nc/o Helios Ship Management GmbH & Co. KG\nBeim Strohhause 27\n20097 Hamburg\nGermany",
        ]);

        $rfq = Rfq::create([
            'reference' => 'MMS-QTN-2026-00090',
            'customer_id' => $customer->id,
            'ship_name' => 'ETHRA 1',
            'base_currency' => 'USD',
            'status' => 'awarded',
        ]);

        $offer = Offer::create([
            'offer_number' => 'MMS-QTN-2026-00090',
            'rfq_id' => $rfq->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'currency' => 'USD',
            'status' => 'sent',
            'payment_terms' => 'Prepayment',
            'customer_reference' => 'TOWAGE YALOVA-TK TUZLA',
            'valid_until' => '2026-10-24',
            'created_by' => $this->staff->id,
        ]);
        $offer->items()->create([
            'description' => self::LONG_DESCRIPTION,
            'remarks' => self::REMARKS,
            'unit' => 'SET', 'qty' => 1,
            'base_price' => 40000, 'markup_pct' => 25,
            'unit_price' => 50000, 'line_total' => 50000,
        ]);
        $offer->recalcTotals();

        return $offer->fresh()->load('items');
    }

    /**
     * The drawing instructions for each page, in order.
     *
     * dompdf flate-compresses every stream it writes, so the page content sits
     * alongside the embedded fonts; a page's stream is the one that positions
     * and draws text.
     *
     * @return string[]
     */
    private function pages(string $pdf): array
    {
        $this->assertStringStartsWith('%PDF', $pdf);
        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $m);

        $pages = [];
        foreach ($m[1] as $raw) {
            $stream = @gzuncompress($raw);
            if ($stream !== false && str_contains($stream, ' Td ') && str_contains($stream, ' TJ ')) {
                $pages[] = $stream;
            }
        }

        $this->assertNotEmpty($pages, 'no page content found in the PDF');

        return $pages;
    }

    /** Text is drawn as UTF-16BE inside the content stream. */
    private function printed(string $page, string $text): bool
    {
        return str_contains($page, mb_convert_encoding($text, 'UTF-16BE', 'UTF-8'));
    }

    public function test_the_quotation_starts_its_items_on_page_one(): void
    {
        $offer = $this->towageOffer();

        $pages = $this->pages(
            $this->get("/api/portal/offers/{$offer->id}/pdf")->assertOk()->getContent()
        );

        $this->assertTrue(
            $this->printed($pages[0], 'Two tug towage from Yalova Yard'),
            'page 1 carries a column header and nothing else'
        );
    }

    public function test_the_proforma_starts_its_items_on_page_one(): void
    {
        $offer = $this->towageOffer();

        $pages = $this->pages(
            $this->get("/api/portal/offers/{$offer->id}/proforma")->assertOk()->getContent()
        );

        $this->assertTrue(
            $this->printed($pages[0], 'Two tug towage from Yalova Yard'),
            'page 1 carries a column header and nothing else'
        );
    }

    /**
     * The remarks are still printed, once, under the last block.
     *
     * They are a separate field on the line — on this quotation they carry who
     * pays the agency fees, port charges and dues, which is a term of the job
     * and not a note. Cutting the description into rows must not drop them,
     * and must not repeat them under every block either.
     */
    public function test_a_lines_remarks_survive_the_split(): void
    {
        $offer = $this->towageOffer();

        foreach (['pdf', 'proforma'] as $document) {
            $whole = implode('', $this->pages(
                $this->get("/api/portal/offers/{$offer->id}/{$document}")->assertOk()->getContent()
            ));

            // Every line of them, so a truncation shows up as a failure.
            foreach (preg_split('/\R/', self::REMARKS) as $line) {
                $this->assertTrue(
                    $this->printed($whole, substr(trim($line), 0, 40)),
                    "the {$document} dropped the remarks"
                );
            }

            $this->assertSame(
                1,
                substr_count($whole, mb_convert_encoding('All agency fees', 'UTF-16BE', 'UTF-8')),
                "the {$document} repeats the remarks under every block"
            );
        }
    }

    /**
     * One logo per document, in the letterhead.
     *
     * The proforma and the delivery order printed it a second time just above
     * the footer — Dru wrote "take out" beside it — and the delivery order
     * repeated the UEN there as well, which the footer already carries. On the
     * page that was short of room it cost a line for nothing.
     */
    public function test_no_document_prints_a_second_logo_above_the_footer(): void
    {
        $documents = [
            'offer', 'proforma-invoice', 'customer-invoice', 'credit-memo',
            'delivery-order', 'purchase-order', 'final-invoice', 'return-note',
            'document',
        ];

        foreach ($documents as $document) {
            $blade = file_get_contents(resource_path("views/pdf/{$document}.blade.php"));

            $this->assertSame(
                1,
                substr_count($blade, '<img src="{{ $logo }}"'),
                "{$document} prints the logo more than once"
            );
        }
    }

    /**
     * Carrying on over the break, not restarting.
     *
     * The item number, quantity and price belong to the item, so they are
     * printed once — on the row the item starts at, wherever that lands.
     */
    public function test_a_split_description_prints_its_figures_once(): void
    {
        $offer = $this->towageOffer();

        $pdf = $this->get("/api/portal/offers/{$offer->id}/pdf")->assertOk()->getContent();
        $whole = implode('', $this->pages($pdf));

        // The unit is printed on the row the item starts at and nowhere else,
        // so it counts the rows that think they are the start of an item.
        $this->assertSame(
            1,
            substr_count($whole, mb_convert_encoding('SET', 'UTF-16BE', 'UTF-8')),
            'the item figures repeat on every block of the description'
        );
    }
}

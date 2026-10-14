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
 * Every accounting screen, totalled in Singapore dollars.
 *
 * Converting the sales register's NET column was not enough. BILLED, SETTLED
 * and OUTSTANDING next to it, the per-account subtotals under it, the F5 boxes,
 * the income statement, the trial balance, the balance sheet and both ageings
 * were all still adding face values — so an invoice for AED 536,185 went into a
 * Singapore dollar outstanding balance as 536,185 and the trial balance did not
 * balance.
 *
 * The rule these tests hold to is one line: NO total anywhere is a face value.
 * Each test takes the same two invoices — one foreign, one domestic — and
 * checks one screen reports the converted sum rather than the sum of the
 * numbers printed on the documents.
 */
class EverySectionConvertsTest extends TestCase
{
    use RefreshDatabase;

    /** 10,000 AED at 0.36 is 3,600 SGD. Plus 1,000 SGD makes 4,600. */
    private const AED = 10000.0;

    private const RATE = 0.36;

    private const SGD = 1000.0;

    private const CONVERTED = 4600.0;

    /** What the old code produced: 10,000 + 1,000, in no currency at all. */
    private const FACE_VALUE = 11000.0;

    private const RANGE = '?from=2026-01-01&to=2026-12-31';

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('admin', 'web');
        $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $user->assignRole('admin');
        $this->grantAllPages($user);
        Sanctum::actingAs($user);

        $this->invoice('AED', self::AED, self::RATE);
        $this->invoice('SGD', self::SGD, null);
    }

    private function invoice(string $currency, float $amount, ?float $rate): CustomerInvoice
    {
        $invoice = CustomerInvoice::create([
            'invoice_number' => 'MMS-INV-'.$currency.'-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'customer_id' => Customer::factory()->create()->id,
            'currency' => $currency,
            'exchange_rate' => $rate,
            'status' => 'sent',
            'issue_date' => '2026-06-15',
            'due_date' => '2026-07-15',
            'account_code' => '4100',
        ]);

        $invoice->items()->create([
            'description' => 'Services',
            'qty' => 1,
            'unit' => 'lot',
            'unit_price' => $amount,
            'line_total' => $amount,
            'account_code' => '4100',
        ]);
        $invoice->recalcTotals();

        return $invoice;
    }

    private function section(string $path): array
    {
        return $this->getJson('/api/portal/accounting/'.$path.self::RANGE)
            ->assertOk()
            ->json('data');
    }

    /**
     * The figure the accountant circled in red.
     *
     * OUTSTANDING is what the customer still owes. Summed at face value it put
     * 536,185 dirhams into a Singapore dollar balance; the top-line card and
     * the register footer both read from here.
     */
    public function test_the_outstanding_total_is_converted(): void
    {
        $totals = $this->section('sales-invoices')['totals'];

        $this->assertEqualsWithDelta(self::CONVERTED, $totals['outstanding'], 0.02);
        $this->assertNotEqualsWithDelta(self::FACE_VALUE, $totals['outstanding'], 0.02);
    }

    /** The column beside it, highlighted yellow on the same screenshot. */
    public function test_the_billed_and_settled_totals_are_converted(): void
    {
        $totals = $this->section('sales-invoices')['totals'];

        $this->assertEqualsWithDelta(self::CONVERTED, $totals['billed'], 0.02);
        $this->assertEqualsWithDelta(0.0, $totals['settled'], 0.02);
    }

    /**
     * "Subtotals by account" under the register, which is also what the income
     * statement and the trial balance are built out of.
     */
    public function test_the_per_account_subtotals_are_converted(): void
    {
        $byAccount = collect($this->section('sales-invoices')['by_account'])
            ->firstWhere('account_code', '4100');

        $this->assertNotNull($byAccount, 'both invoices are booked to 4100');
        $this->assertEqualsWithDelta(self::CONVERTED, $byAccount['net'], 0.02);
    }

    /** "sayop gyapon ang total" — the income statement's own revenue line. */
    public function test_the_income_statement_is_converted(): void
    {
        $pl = $this->section('income-statement');

        $this->assertEqualsWithDelta(self::CONVERTED, $pl['revenue']['total'], 0.02);
        $this->assertEqualsWithDelta(self::CONVERTED, $pl['net_profit'], 0.02);
        $this->assertSame('SGD', $pl['base_currency']);
    }

    /** "sa trial balance mali japon ang total wala siya na convert". */
    public function test_the_trial_balance_is_converted_and_balances(): void
    {
        $tb = $this->section('trial-balance');

        $sales = collect($tb['rows'])->firstWhere('code', '4100');
        $this->assertEqualsWithDelta(self::CONVERTED, $sales['credit'], 0.02);

        // And the whole thing still balances once it is all in one currency,
        // which is the only way the two sides can ever agree.
        $this->assertTrue(
            $tb['totals']['balanced'],
            'trial balance is out by '.$tb['totals']['difference']
        );
    }

    /**
     * Receivables on the balance sheet come from OpenEntries, not from the
     * register, so they are converted on their own path and have to be
     * checked on it.
     */
    public function test_the_balance_sheet_is_converted(): void
    {
        $bs = $this->section('balance-sheet');

        $this->assertEqualsWithDelta(self::CONVERTED, $bs['assets']['receivables']['amount'], 0.02);
        $this->assertTrue(
            $bs['check']['balanced'],
            'balance sheet is out by '.$bs['check']['difference']
        );
    }

    /**
     * The boxes on the return itself. IRAS takes the F5 in Singapore dollars,
     * so a box built from face values declares dirhams as SGD supplies.
     */
    public function test_the_gst_boxes_are_converted(): void
    {
        $gst = $this->section('gst-summary');

        $box2 = collect($gst['boxes'])->firstWhere('box', 2);
        $this->assertEqualsWithDelta(self::CONVERTED, $box2['amount'], 0.02);

        $box4 = collect($gst['boxes'])->firstWhere('box', 4);
        $this->assertEqualsWithDelta(self::CONVERTED, $box4['amount'], 0.02);
    }

    /** Ageing buckets are added across every party, so they must be converted too. */
    public function test_the_ar_ageing_buckets_are_converted(): void
    {
        $ageing = $this->section('ar-ageing');

        $this->assertEqualsWithDelta(self::CONVERTED, $ageing['total'], 0.02);
        $this->assertEqualsWithDelta(self::CONVERTED, array_sum($ageing['buckets']), 0.02);

        // The native breakdown stays alongside it — chasing a customer you
        // want "AED 10,000", not a converted figure they will not recognise.
        $this->assertNotEmpty($ageing['by_currency']);
    }

    /**
     * A document with no rate is absent from every one of those totals. That is
     * the right answer, but only if each screen says so — an understated total
     * reads exactly like a complete one.
     */
    public function test_an_unconvertible_document_is_excluded_and_named_everywhere(): void
    {
        $this->invoice('EUR', 5000, null);

        $sales = $this->section('sales-invoices');
        $this->assertEqualsWithDelta(self::CONVERTED, $sales['totals']['outstanding'], 0.02);
        $this->assertCount(1, $sales['totals']['unrated']);

        $this->assertSame(1, $this->section('income-statement')['unrated_count']);
        $this->assertSame(1, $this->section('ar-ageing')['unrated']);

        $warnings = $this->section('gst-summary')['warnings'];
        $this->assertNotEmpty(
            array_filter($warnings, fn ($w) => str_contains($w, 'no exchange rate')),
            'the return has to say a document is missing from its boxes'
        );
    }
}

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
 * When the books do not balance, they say why — and name the documents.
 *
 * Three things were leaving money unaccounted for and reporting none of it:
 *
 *  1. Documents on an account code the chart does not have. Counted ("6
 *     purchase documents…") but never named, so finding them meant reading
 *     four list screens. They were also silently dropped from the trial
 *     balance while staying in the income statement, which is why those two
 *     reports disagreed by exactly that amount.
 *
 *  2. Invoices ticked "paid" with no payment recorded. The money leaves
 *     receivables and never arrives in cash, so it is gone from the books
 *     entirely — and from the invoice screen it looks like the job is done.
 *
 *  3. Cash to Master principal. Billed to the client, so it lands in
 *     receivables as an asset; the matching obligation to the vessel master is
 *     coded to 2100, which the balance sheet had no line for at all.
 */
class BooksExplainThemselvesTest extends TestCase
{
    use RefreshDatabase;

    private const RANGE = '?from=2026-01-01&to=2026-12-31';

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
     * @param  array<array{0: string, 1: float, 2: ?string}>  $lines  description, amount, account
     */
    private function invoice(array $lines, ?string $account = '4100', bool $markPaid = false): CustomerInvoice
    {
        $invoice = CustomerInvoice::create([
            'invoice_number' => 'MMS-INV-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
            'customer_id' => Customer::factory()->create()->id,
            'currency' => 'SGD',
            'status' => $markPaid ? 'paid' : 'sent',
            'paid_at' => $markPaid ? '2026-06-20' : null,
            'issue_date' => '2026-06-15',
            'account_code' => $account,
        ]);

        foreach ($lines as [$description, $amount, $lineAccount]) {
            $invoice->items()->create([
                'description' => $description,
                'qty' => 1,
                'unit' => 'lot',
                'unit_price' => $amount,
                'line_total' => $amount,
                'account_code' => $lineAccount,
            ]);
        }
        $invoice->recalcTotals();

        return $invoice;
    }

    private function section(string $path): array
    {
        return $this->getJson('/api/portal/accounting/'.$path.self::RANGE)->assertOk()->json('data');
    }

    /* ---------------------------------------------------------------- */
    /*  1. Documents on no account code                                 */
    /* ---------------------------------------------------------------- */

    public function test_a_document_on_no_account_is_named_not_just_counted(): void
    {
        $stray = $this->invoice([['Consultancy', 2000.0, null]], account: '9999');

        $register = $this->section('sales-invoices');

        $this->assertSame(1, $register['unclassified']);
        $this->assertCount(1, $register['unclassified_rows']);
        $this->assertSame($stray->invoice_number, $register['unclassified_rows'][0]['number']);
        $this->assertEqualsWithDelta(2000.0, $register['unclassified_total'], 0.02);
    }

    /**
     * The disagreement the live books showed: the income statement counted
     * 8,282.22 of unclassified cost and the trial balance did not, so the two
     * were out by exactly that much and nothing on either screen said so.
     */
    public function test_an_unclassified_amount_is_posted_on_the_trial_balance_not_dropped(): void
    {
        $this->invoice([['Consultancy', 2000.0, null]], account: '9999');

        $trial = $this->section('trial-balance');

        $stray = collect($trial['rows'])->first(fn ($r) => str_contains($r['name'], 'no account code'));

        $this->assertNotNull($stray, 'the trial balance must show what it could not post');
        $this->assertEqualsWithDelta(2000.0, $stray['credit'], 0.02, 'income is credit-normal');

        // And it is named, so the documents can be opened and coded.
        $this->assertCount(1, $trial['unclassified_rows']);
    }

    public function test_the_gst_return_says_what_input_tax_is_going_unclaimed(): void
    {
        // Standard-rated purchase on an account the chart does not have: the
        // GST on it reaches no box, which is money not claimed back.
        $po = \App\Models\PurchaseOrder::create([
            'po_number' => 'MMS-PO-2026-000900',
            'vendor_id' => \App\Models\Vendor::factory()->create()->id,
            'currency' => 'SGD',
            'exchange_rate' => 1,
            'status' => 'issued',
            'issued_date' => '2026-06-15',
            'account_code' => '9999',
            'subtotal' => 1000,
            'tax_rate' => 9,
            'tax_amount' => 90,
        ]);
        $po->items()->create([
            'description' => 'Office laptop', 'qty' => 1, 'unit' => 'pc',
            'unit_cost' => 1000, 'line_total' => 1000,
        ]);

        $gst = $this->section('gst-summary');

        $this->assertEqualsWithDelta(90.0, $gst['unclaimed_input_tax'], 0.02);
        $this->assertNotEmpty($gst['unclassified_rows']);
    }

    /* ---------------------------------------------------------------- */
    /*  2. Marked paid with nothing recorded                            */
    /* ---------------------------------------------------------------- */

    public function test_an_invoice_marked_paid_with_no_payment_is_reported(): void
    {
        $ticked = $this->invoice([['Services', 5000.0, '4100']], markPaid: true);
        $this->invoice([['Services', 1000.0, '4100']]);   // ordinary, still owed

        $register = $this->section('sales-invoices');

        $this->assertCount(1, $register['assumed_paid']);
        $this->assertSame($ticked->invoice_number, $register['assumed_paid'][0]['number']);
        $this->assertEqualsWithDelta(5000.0, $register['assumed_paid_total'], 0.02);

        // It shows on the statements too, where the imbalance is visible.
        $this->assertEqualsWithDelta(5000.0, $this->section('trial-balance')['assumed_paid_total'], 0.02);
        $this->assertEqualsWithDelta(5000.0, $this->section('balance-sheet')['assumed_paid_total'], 0.02);
    }

    /** An invoice with a real payment behind it is not flagged. */
    public function test_a_properly_paid_invoice_is_not_flagged(): void
    {
        $invoice = $this->invoice([['Services', 5000.0, '4100']]);

        $payment = \App\Models\Payment::create([
            'payment_number' => 'PAY-0001',
            'party_type' => 'customer',
            'customer_id' => $invoice->customer_id,
            'payment_date' => '2026-06-20',
            'currency' => 'SGD',
            'amount' => 5000,
            'direction' => 'in',
        ]);
        \App\Models\PaymentAllocation::create([
            'payment_id' => $payment->id,
            'customer_invoice_id' => $invoice->id,
            'amount' => 5000,
        ]);

        $register = $this->section('sales-invoices');

        $this->assertSame([], $register['assumed_paid']);
        $this->assertEqualsWithDelta(0.0, $register['totals']['outstanding'], 0.02);
    }

    /* ---------------------------------------------------------------- */
    /*  2b. Lines coded to the wrong side of the books                  */
    /* ---------------------------------------------------------------- */

    /**
     * MMS-INV-2026-000423, exactly as it stands on the live books: three sales
     * lines — Agency Fee, Crew Handling, Crew Transport — every one of them
     * coded to 5000 Cost of Sales.
     *
     * Because 5000 is an expense account, the split reads the whole invoice as
     * money passing through rather than as a sale: nil revenue, 290.00 of
     * pass-through, and a receivable with nothing behind it. Nothing on any
     * screen said so — the figures simply came out low.
     */
    public function test_a_sales_line_coded_to_cost_of_sales_is_reported(): void
    {
        $miscoded = $this->invoice([
            ['Agency Fee', 150.0, '5000'],
            ['Crew Handling', 40.0, '5000'],
            ['Crew Transport', 100.0, '5000'],
        ]);

        $register = $this->section('sales-invoices');

        $this->assertCount(1, $register['miscoded']);
        $this->assertSame($miscoded->invoice_number, $register['miscoded'][0]['number']);
        $this->assertEqualsWithDelta(290.0, $register['miscoded_total'], 0.02);

        // The message names the account, because "it is on the wrong account"
        // without saying which is not something anybody can act on.
        $this->assertStringContainsString('5000', $register['miscoded'][0]['accounts']);

        // And the damage: the sale is not in revenue at all.
        $this->assertEqualsWithDelta(0.0, $register['totals']['net'], 0.02);
        $this->assertEqualsWithDelta(290.0, $register['totals']['billed'], 0.02);
    }

    /** A line correctly on a liability is money held, and is NOT flagged. */
    public function test_a_line_on_a_liability_account_is_not_flagged(): void
    {
        $this->invoice([
            ['CTM handling fee', 500.0, '4200'],
            ['Cash to Master principal', 10000.0, '2100'],
        ]);

        $this->assertSame([], $this->section('sales-invoices')['miscoded']);
    }

    public function test_the_statements_report_miscoded_lines_too(): void
    {
        $this->invoice([['Agency Fee', 150.0, '5000']]);

        $this->assertCount(1, $this->section('trial-balance')['miscoded']);
        $this->assertCount(1, $this->section('balance-sheet')['miscoded']);
    }

    /* ---------------------------------------------------------------- */
    /*  2c. Putting a marked-paid document right                        */
    /* ---------------------------------------------------------------- */

    /**
     * The dead end: the payment screen would not offer the one document that
     * most needed a payment recorded.
     *
     * Marking an invoice paid sets its outstanding to nil, and the form only
     * ever listed documents with something outstanding — so the invoice that
     * had money missing from the books was invisible on the only screen that
     * could put the money back. The únlock was to know to un-tick the flag
     * first, which nobody would guess.
     */
    public function test_a_marked_paid_invoice_can_still_have_its_payment_recorded(): void
    {
        $invoice = $this->invoice([['Services', 5000.0, '4100']], markPaid: true);

        $docs = $this->getJson("/api/portal/payments/open/customer/{$invoice->customer_id}")
            ->assertOk()
            ->json('data.documents');

        $this->assertCount(1, $docs, 'the invoice has to be offered, flag or no flag');
        $this->assertSame($invoice->id, $docs[0]['id']);
        $this->assertEqualsWithDelta(5000.0, $docs[0]['outstanding'], 0.02, 'the whole amount is still unpaid in reality');
        $this->assertTrue($docs[0]['assumed_paid'], 'and the form says why it is here');
    }

    /** And the payment is accepted, rather than refused as already settled. */
    public function test_recording_the_payment_on_a_marked_paid_invoice_is_allowed(): void
    {
        $invoice = $this->invoice([['Services', 5000.0, '4100']], markPaid: true);

        $this->postJson('/api/portal/payments', [
            'party_type' => 'customer',
            'party_id' => $invoice->customer_id,
            'payment_date' => '2026-06-20',
            'currency' => 'SGD',
            'amount' => 5000,
            'method' => 'bank transfer',
            'allocations' => [['document_id' => $invoice->id, 'amount' => 5000]],
        ])->assertCreated();

        // Now there is real money behind it, so it stops being reported.
        $register = $this->section('sales-invoices');
        $this->assertSame([], $register['assumed_paid']);

        // And it cannot be paid a second time.
        $this->assertSame([], $this->getJson("/api/portal/payments/open/customer/{$invoice->customer_id}")
            ->json('data.documents'));
    }

    /**
     * The document itself says whether money is actually missing, so the screen
     * can offer to record a payment only where there is one to record.
     *
     * Offering it on an order that has been paid for real sends somebody to a
     * payment form with no rows in it — the document is not listed, because
     * there is nothing left to apply. Worse than not offering at all.
     */
    public function test_a_document_says_whether_its_paid_flag_has_money_behind_it(): void
    {
        $ticked = $this->invoice([['Services', 5000.0, '4100']], markPaid: true);
        $real = $this->invoice([['Services', 3000.0, '4100']]);

        $payment = \App\Models\Payment::create([
            'payment_number' => 'PAY-0002',
            'party_type' => 'customer',
            'customer_id' => $real->customer_id,
            'payment_date' => '2026-06-20',
            'currency' => 'SGD',
            'amount' => 3000,
            'direction' => 'in',
        ]);
        \App\Models\PaymentAllocation::create([
            'payment_id' => $payment->id,
            'customer_invoice_id' => $real->id,
            'amount' => 3000,
        ]);

        $this->assertTrue(
            $this->getJson("/api/portal/invoices/{$ticked->id}")->json('data.settlement.assumed_paid'),
            'ticked with nothing behind it — the screen should offer to record the payment'
        );
        $this->assertFalse(
            $this->getJson("/api/portal/invoices/{$real->id}")->json('data.settlement.assumed_paid'),
            'paid for real — there is nothing left to record'
        );
    }

    /** A purchase order answers the same question the same way. */
    public function test_an_order_says_whether_its_paid_tickbox_has_money_behind_it(): void
    {
        $po = \App\Models\PurchaseOrder::create([
            'po_number' => 'MMS-PO-2026-000902',
            'vendor_id' => \App\Models\Vendor::factory()->create()->id,
            'currency' => 'SGD',
            'exchange_rate' => 1,
            'status' => 'received',
            'issued_date' => '2026-06-15',
            'account_code' => '5000',
            'subtotal' => 2000,
            'paid_at' => '2026-06-20',   // ticked, with nothing recorded
        ]);

        $shown = $this->getJson("/api/portal/purchase-orders/{$po->id}")->assertOk();

        $this->assertTrue($shown->json('data.settlement.assumed_paid'));
        $this->assertSame(0, $shown->json('data.payment_count'));
    }

    /** A genuinely settled document is still protected from a double payment. */
    public function test_a_document_already_paid_for_real_cannot_be_paid_again(): void
    {
        $invoice = $this->invoice([['Services', 5000.0, '4100']]);

        $body = [
            'party_type' => 'customer',
            'party_id' => $invoice->customer_id,
            'payment_date' => '2026-06-20',
            'currency' => 'SGD',
            'amount' => 5000,
            'method' => 'bank transfer',
            'allocations' => [['document_id' => $invoice->id, 'amount' => 5000]],
        ];

        $this->postJson('/api/portal/payments', $body)->assertCreated();
        $this->postJson('/api/portal/payments', $body)->assertStatus(422);
    }

    /* ---------------------------------------------------------------- */
    /*  3. Cash to Master held on a client's behalf                     */
    /* ---------------------------------------------------------------- */

    /**
     * The invoice bills 10,500: a 500 handling fee that is ours, and 10,000 of
     * principal that belongs to a vessel master. The customer owes the whole
     * 10,500 — but only 500 is revenue, and the other 10,000 has to appear as
     * something we owe, or the statement reports an asset with nothing against
     * it.
     */
    public function test_client_funds_held_appear_as_a_liability(): void
    {
        $this->invoice([
            ['CTM handling fee', 500.0, '4200'],
            ['Cash to Master principal', 10000.0, '2100'],
        ]);

        $balance = $this->section('balance-sheet');

        $held = collect($balance['liabilities']['other'])->firstWhere('code', '2100');

        $this->assertNotNull($held, 'client funds held must be reported');
        $this->assertEqualsWithDelta(10000.0, $held['amount'], 0.02);

        // The customer owes all of it, so it is an asset as well — and with
        // both sides reported the statement balances.
        $this->assertEqualsWithDelta(10500.0, $balance['assets']['receivables']['amount'], 0.02);
        $this->assertTrue(
            $balance['check']['balanced'],
            'balance sheet is out by '.$balance['check']['difference']
        );
    }

    public function test_client_funds_held_appear_on_the_trial_balance_too(): void
    {
        $this->invoice([
            ['CTM handling fee', 500.0, '4200'],
            ['Cash to Master principal', 10000.0, '2100'],
        ]);

        $trial = $this->section('trial-balance');
        $held = collect($trial['rows'])->firstWhere('code', '2100');

        $this->assertEqualsWithDelta(10000.0, $held['credit'], 0.02);
        $this->assertTrue($trial['totals']['balanced'], 'out by '.$trial['totals']['difference']);
    }

    /** Paying the master down reduces what is held. */
    public function test_paying_the_master_reduces_the_funds_held(): void
    {
        $this->invoice([
            ['CTM handling fee', 500.0, '4200'],
            ['Cash to Master principal', 10000.0, '2100'],
        ]);

        $po = \App\Models\PurchaseOrder::create([
            'po_number' => 'MMS-PO-2026-000901',
            'vendor_id' => \App\Models\Vendor::factory()->create()->id,
            'currency' => 'SGD',
            'exchange_rate' => 1,
            'status' => 'issued',
            'issued_date' => '2026-06-20',
            'account_code' => '2100',
            'subtotal' => 4000,
        ]);
        $po->items()->create([
            'description' => 'Cash handed to master', 'qty' => 1, 'unit' => 'lot',
            'unit_cost' => 4000, 'line_total' => 4000, 'account_code' => '2100',
        ]);

        $held = collect($this->section('balance-sheet')['liabilities']['other'])
            ->firstWhere('code', '2100');

        $this->assertEqualsWithDelta(6000.0, $held['amount'], 0.02, '10,000 collected less 4,000 paid out');
    }
}

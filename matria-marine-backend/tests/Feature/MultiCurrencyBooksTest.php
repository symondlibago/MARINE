<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\User;
use App\Support\AccountingBooks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Totalling a set of books that holds more than one currency.
 *
 * The register used to add face values together: an invoice for AED 536,185
 * went into a Singapore dollar total as 536,185, and the figure underneath it
 * was not a number anyone could file. Every amount is now converted at the
 * rate recorded on its own document, and a document without a rate is left
 * out and named rather than counted at par.
 */
class MultiCurrencyBooksTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): User
    {
        Role::findOrCreate('admin', 'web');
        $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $user->assignRole('admin');
        $this->grantAllPages($user);
        Sanctum::actingAs($user);

        return $user;
    }

    private function invoice(string $currency, float $amount, ?float $rate): CustomerInvoice
    {
        $customer = Customer::factory()->create();

        $invoice = CustomerInvoice::create([
            'invoice_number' => 'MMS-INV-'.$currency.'-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'customer_id' => $customer->id,
            'currency' => $currency,
            'exchange_rate' => $rate,
            'status' => 'sent',
            'issue_date' => '2026-06-15',
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

    public function test_the_books_are_kept_in_singapore_dollars(): void
    {
        $this->assertSame('SGD', AccountingBooks::baseCurrency());
    }

    public function test_an_invoice_in_the_reporting_currency_needs_no_rate(): void
    {
        $invoice = $this->invoice('SGD', 1000, null);

        // One SGD is one SGD; nobody should have to type that.
        $this->assertSame(1.0, AccountingBooks::rateOf($invoice));
    }

    /**
     * The bug, stated plainly.
     *
     * A foreign invoice with no rate must not convert at 1.0 — that is exactly
     * what put 536,185 dirhams into a Singapore dollar total.
     */
    public function test_a_foreign_invoice_without_a_rate_does_not_convert_at_par(): void
    {
        $invoice = $this->invoice('AED', 536185, null);

        $this->assertNull(AccountingBooks::rateOf($invoice));
    }

    public function test_a_foreign_invoice_converts_at_the_rate_on_it(): void
    {
        $this->staff();
        $this->invoice('AED', 536185, 0.368521);

        $totals = $this->getJson('/api/portal/accounting/sales-invoices?from=2026-01-01&to=2026-12-31')
            ->assertOk()
            ->json('data.totals');

        // 536,185 x 0.368521 = 197,595.43, not 536,185.
        $this->assertEqualsWithDelta(197595.43, $totals['base_net'], 0.02);
        $this->assertSame(536185.0, (float) $totals['net']);
        $this->assertSame([], $totals['unrated']);
    }

    public function test_currencies_are_converted_before_they_are_added(): void
    {
        $this->staff();

        // What the accountant asked for: C1(AED->SGD) + C2(USD->SGD) + C3(SGD).
        $this->invoice('AED', 1000, 0.36);   //   360
        $this->invoice('USD', 1000, 1.35);   // 1,350
        $this->invoice('SGD', 1000, null);   // 1,000  (rate is 1 by definition)

        $totals = $this->getJson('/api/portal/accounting/sales-invoices?from=2026-01-01&to=2026-12-31')
            ->assertOk()
            ->json('data.totals');

        $this->assertEqualsWithDelta(2710.00, $totals['base_net'], 0.02);
        // The face-value sum is still reported, and is still meaningless.
        $this->assertSame(3000.0, (float) $totals['net']);
    }

    /**
     * An unconvertible document has to be visible. Dropped silently, an
     * understated total reads exactly like a complete one.
     */
    public function test_a_document_with_no_rate_is_named_not_just_omitted(): void
    {
        $this->staff();

        $this->invoice('SGD', 1000, null);
        $unrated = $this->invoice('EUR', 2634.40, null);

        $totals = $this->getJson('/api/portal/accounting/sales-invoices?from=2026-01-01&to=2026-12-31')
            ->assertOk()
            ->json('data.totals');

        // Only the SGD invoice could be converted.
        $this->assertEqualsWithDelta(1000.00, $totals['base_net'], 0.02);

        $this->assertCount(1, $totals['unrated']);
        $this->assertSame($unrated->invoice_number, $totals['unrated'][0]['number']);
        $this->assertSame('EUR', $totals['unrated'][0]['currency']);
    }

    /**
     * A credit note has no rate of its own to decide.
     *
     * It undoes a sale, so it undoes it at the figure that sale was booked at.
     * It also means there is nowhere on the credit memo screen to type a rate —
     * setting the invoice's fixes both, which is what someone staring at an
     * unrated credit note with no rate box actually needs.
     */
    public function test_a_credit_note_follows_the_invoice_it_credits(): void
    {
        $this->staff();
        $invoice = $this->invoice('USD', 1000, 1.35);

        $memo = \App\Models\CreditMemo::create([
            'cm_number' => 'MMS-CM-2026-000101',
            'customer_invoice_id' => $invoice->id,
            'customer_id' => $invoice->customer_id,
            'currency' => 'USD',
            'exchange_rate' => null,          // never set on the memo itself
            'status' => 'issued',
            'memo_date' => '2026-06-20',
            'account_code' => '4100',
        ]);
        $memo->items()->create([
            'description' => 'Discount',
            'qty' => 1,
            'unit_price' => -100,
            'line_total' => -100,
            'account_code' => '4100',
        ]);

        $row = AccountingBooks::sales(
            \Illuminate\Support\Carbon::parse('2026-01-01'),
            \Illuminate\Support\Carbon::parse('2026-12-31'),
        )->firstWhere('number', 'MMS-CM-2026-000101');

        $this->assertNotNull($row, 'the credit note is missing from the sales register');
        $this->assertSame(1.35, $row['exchange_rate']);
        $this->assertNotNull($row['base_net'], 'it was left unconvertible despite the invoice having a rate');
    }

    public function test_setting_the_currency_to_sgd_sets_the_rate_to_one(): void
    {
        $this->staff();
        $invoice = $this->invoice('USD', 500, 1.35);

        $this->patchJson("/api/portal/invoices/{$invoice->id}", ['currency' => 'SGD'])->assertOk();

        $this->assertSame(1.0, (float) $invoice->fresh()->exchange_rate);
    }

    public function test_a_rate_can_be_cleared_back_to_unset(): void
    {
        $this->staff();
        $invoice = $this->invoice('USD', 500, 1.35);

        $this->patchJson("/api/portal/invoices/{$invoice->id}", ['exchange_rate' => null])->assertOk();

        $this->assertNull($invoice->fresh()->exchange_rate);
        $this->assertNull(AccountingBooks::rateOf($invoice->fresh()));
    }
}

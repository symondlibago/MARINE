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

    /**
     * A new foreign invoice takes a rate on its own, so nobody has to go and
     * fetch one for every document that is raised.
     */
    public function test_a_new_foreign_invoice_is_rated_automatically_on_save(): void
    {
        $this->staff();

        \Illuminate\Support\Facades\Cache::flush();
        \Illuminate\Support\Facades\Http::fake([
            'www.dbs.com.sg/*' => \Illuminate\Support\Facades\Http::response([], 500),
            // Quoted against the currency asked for, so USD -> SGD is read
            // straight off — the way the posting rate is taken.
            '*' => \Illuminate\Support\Facades\Http::response([
                'result' => 'success',
                'base_code' => 'USD',
                'rates' => ['USD' => 1, 'SGD' => 1.2691],
                'time_last_update_utc' => 'Tue, 13 Oct 2026 00:00:01 +0000',
            ], 200),
        ]);

        $invoice = $this->invoice('USD', 1000, null);
        $this->assertNull($invoice->exchange_rate);

        // An ordinary save, with no rate sent.
        $this->patchJson("/api/portal/invoices/{$invoice->id}", ['customer_reference' => 'PO-123'])->assertOk();

        $rate = $invoice->fresh()->exchange_rate;
        $this->assertNotNull($rate, 'the invoice was saved still unrated');
        $this->assertEqualsWithDelta(1.2691, (float) $rate, 0.0001);
    }

    /**
     * The rate is written onto the document, not worked out afresh each time a
     * report is drawn. A figure filed with IRAS has to still be there next
     * quarter when somebody asks where it came from.
     */
    public function test_a_stamped_rate_does_not_move_when_the_market_does(): void
    {
        $this->staff();
        $invoice = $this->invoice('USD', 1000, 1.35);

        \Illuminate\Support\Facades\Cache::flush();
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response([
            'result' => 'success', 'base_code' => 'SGD',
            'rates' => ['SGD' => 1, 'USD' => 0.5],   // the market has moved a long way
            'time_last_update_utc' => 'Tue, 13 Oct 2026 00:00:01 +0000',
        ], 200)]);

        $totals = $this->getJson('/api/portal/accounting/sales-invoices?from=2026-01-01&to=2026-12-31')
            ->assertOk()->json('data.totals');

        // Still 1,000 x 1.35, the rate on the invoice — not 1,000 x 2.
        $this->assertEqualsWithDelta(1350.00, $totals['base_net'], 0.02);
    }

    public function test_fill_rates_clears_the_backlog_in_one_call(): void
    {
        $this->staff();

        \Illuminate\Support\Facades\Cache::flush();
        \Illuminate\Support\Facades\Http::fake([
            'www.dbs.com.sg/*' => \Illuminate\Support\Facades\Http::response([], 500),
            '*' => \Illuminate\Support\Facades\Http::response([
                'result' => 'success', 'base_code' => 'SGD',
                'rates' => ['SGD' => 1, 'USD' => 0.74, 'EUR' => 0.69],
                'time_last_update_utc' => 'Tue, 13 Oct 2026 00:00:01 +0000',
            ], 200),
        ]);

        $a = $this->invoice('USD', 1000, null);
        $b = $this->invoice('EUR', 2000, null);

        $res = $this->postJson('/api/portal/accounting/fill-rates')->assertOk()->json('data');

        $this->assertGreaterThanOrEqual(2, $res['filled_count']);
        $this->assertNotNull($a->fresh()->exchange_rate);
        $this->assertNotNull($b->fresh()->exchange_rate);

        $totals = $this->getJson('/api/portal/accounting/sales-invoices?from=2026-01-01&to=2026-12-31')
            ->assertOk()->json('data.totals');

        $this->assertSame([], $totals['unrated'], 'documents are still missing from the totals');
    }

    /**
     * There is one posting rate, however it was arrived at.
     *
     * The rate button on a document used to work one out from the rate table
     * while the automatic stamp used another route, and the two landed on
     * opposite sides of the bank's buy/sell spread. Two USD invoices raised on
     * the same day went into the books at 1.292222 and 1.269084 — a shade under
     * two percent apart, decided by which button somebody pressed. Both paths
     * now ask the server, and this is what keeps them that way.
     */
    public function test_the_button_and_the_automatic_stamp_give_the_same_rate(): void
    {
        $this->staff();

        \Illuminate\Support\Facades\Cache::flush();
        \Illuminate\Support\Facades\Http::fake([
            'www.dbs.com.sg/*' => \Illuminate\Support\Facades\Http::response([], 500),
            '*' => \Illuminate\Support\Facades\Http::response([
                'result' => 'success', 'base_code' => 'SGD',
                'rates' => ['SGD' => 1, 'USD' => 0.74],
                'time_last_update_utc' => 'Tue, 13 Oct 2026 00:00:01 +0000',
            ], 200),
        ]);

        // What the rate button on a document is handed.
        $fromButton = (float) $this->getJson('/api/portal/fx-rates/to-base?currency=USD')
            ->assertOk()->json('data.rate');

        // What a save stamps on by itself.
        $invoice = $this->invoice('USD', 1000, null);
        $this->patchJson("/api/portal/invoices/{$invoice->id}", ['customer_reference' => 'x'])->assertOk();
        $fromStamp = (float) $invoice->fresh()->exchange_rate;

        // And what "Convert them all" writes.
        $second = $this->invoice('USD', 500, null);
        $this->postJson('/api/portal/accounting/fill-rates')->assertOk();
        $fromBulk = (float) $second->fresh()->exchange_rate;

        $this->assertSame($fromButton, $fromStamp, 'the button and the automatic stamp disagree');
        $this->assertSame($fromButton, $fromBulk, 'the bulk fill disagrees with the button');
    }

    /**
     * The posting rate is the number the DBS converter shows.
     *
     * It is what anyone checking these books will type into dbs.com.sg, and it
     * has to agree. FxRates::rateToBase() does NOT: it crosses the bank's
     * spread in both directions to answer what settling a vendor's foreign
     * invoice costs, which put USD into the books at 1.2922 where the
     * converter said 1.2691. Right for pricing a purchase, wrong for stating
     * what a sale was worth, and the two must not be confused again.
     */
    public function test_the_posting_rate_is_the_converter_rate_not_the_spread_crossed_one(): void
    {
        \Illuminate\Support\Facades\Cache::flush();
        \Illuminate\Support\Facades\Http::fake([
            'www.dbs.com.sg/*' => \Illuminate\Support\Facades\Http::response([], 500),
            // The mid-market fallback quotes against the base it is asked for,
            // so USD -> SGD reads straight off and needs no inversion.
            '*' => \Illuminate\Support\Facades\Http::response([
                'result' => 'success', 'base_code' => 'USD',
                'rates' => ['USD' => 1, 'SGD' => 1.2691],
                'time_last_update_utc' => 'Tue, 13 Oct 2026 00:00:01 +0000',
            ], 200),
        ]);

        $this->assertEqualsWithDelta(
            1.2691,
            AccountingBooks::stampRate('USD'),
            0.0001,
            'the posting rate no longer matches what the DBS converter shows'
        );
    }

    public function test_an_unpriceable_currency_is_refused_rather_than_guessed(): void
    {
        $this->staff();

        \Illuminate\Support\Facades\Cache::flush();
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response([], 500)]);

        $this->getJson('/api/portal/fx-rates/to-base?currency=XYZ')->assertStatus(502);
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

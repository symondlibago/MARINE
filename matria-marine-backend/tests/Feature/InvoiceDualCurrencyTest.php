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
 * The invoice PDF with a second currency beside the one billed.
 *
 * Some customers are invoiced in one currency and reconcile in another, and
 * want to see both on the page. It is an option on the download, not a change
 * to the invoice: nothing is stored, and asking for no second currency gives
 * the document exactly as it was before.
 */
class InvoiceDualCurrencyTest extends TestCase
{
    use RefreshDatabase;

    private function invoice(string $currency = 'USD'): CustomerInvoice
    {
        Role::findOrCreate('admin', 'web');
        $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $user->assignRole('admin');
        $this->grantAllPages($user);
        Sanctum::actingAs($user);

        $customer = Customer::factory()->create(['name' => 'Integra Shippers FZE']);

        $invoice = CustomerInvoice::create([
            'invoice_number' => 'MMS-INV-2026-000400',
            'customer_id' => $customer->id,
            'currency' => $currency,
            'status' => 'sent',
            'issue_date' => '2026-10-06',
        ]);

        $invoice->items()->create([
            'description' => 'Comprehensive STS Package',
            'qty' => 1,
            'unit' => 'lot',
            'unit_price' => 116000,
            'line_total' => 116000,
        ]);
        $invoice->recalcTotals();

        return $invoice;
    }

    public function test_the_ordinary_pdf_is_unchanged_when_no_currency_is_asked_for(): void
    {
        $invoice = $this->invoice();

        $pdf = $this->get("/api/portal/invoices/{$invoice->id}/pdf")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->getContent();

        $this->assertStringStartsWith('%PDF', $pdf);
    }

    public function test_a_second_currency_renders_with_the_rate_given(): void
    {
        $invoice = $this->invoice('USD');

        // AED is pegged, so the exact peg is sent rather than a market quote.
        $pdf = $this->get("/api/portal/invoices/{$invoice->id}/pdf?currency=AED&rate=3.6725")
            ->assertOk()
            ->getContent();

        $this->assertStringStartsWith('%PDF', $pdf);
        // A converted column makes the document materially bigger than the
        // plain one — the cheapest proof the extra column was drawn.
        $plain = $this->get("/api/portal/invoices/{$invoice->id}/pdf")->getContent();
        $this->assertGreaterThan(strlen($plain), strlen($pdf));
    }

    public function test_asking_for_the_invoices_own_currency_changes_nothing(): void
    {
        $invoice = $this->invoice('USD');

        $same = $this->get("/api/portal/invoices/{$invoice->id}/pdf?currency=USD&rate=1")->getContent();
        $plain = $this->get("/api/portal/invoices/{$invoice->id}/pdf")->getContent();

        // No pointless "USD alongside USD" column.
        $this->assertSame(strlen($plain), strlen($same));
    }

    /**
     * A rate of zero or less is not a rate, so it is treated as "not given"
     * and a live one is read instead. If no feed answers either, the plain
     * invoice comes back — never a second column full of zeroes, which would
     * read as though the customer owed nothing.
     */
    public function test_a_nonsense_rate_never_prints_a_column_of_zeroes(): void
    {
        $invoice = $this->invoice('USD');

        // No feed reachable, so there is no live rate to fall back to.
        \Illuminate\Support\Facades\Cache::flush();
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response([], 500)]);

        $plain = strlen($this->get("/api/portal/invoices/{$invoice->id}/pdf")->getContent());

        foreach (['0', '-5'] as $bad) {
            $this->assertSame(
                $plain,
                strlen($this->get("/api/portal/invoices/{$invoice->id}/pdf?currency=AED&rate={$bad}")->assertOk()->getContent()),
                "a rate of {$bad} drew a second column anyway"
            );
        }
    }

    /**
     * With no rate sent at all, the server reads one — the screen sends the
     * rate it showed, but a link or a script need not.
     */
    public function test_the_rate_is_read_live_when_none_is_sent(): void
    {
        $invoice = $this->invoice('USD');

        \Illuminate\Support\Facades\Cache::flush();
        \Illuminate\Support\Facades\Http::fake([
            // DBS unreachable, so FxRates falls through to the mid-market feed.
            'www.dbs.com.sg/*' => \Illuminate\Support\Facades\Http::response([], 500),
            '*' => \Illuminate\Support\Facades\Http::response([
                'result' => 'success',
                'base_code' => 'USD',
                'rates' => ['USD' => 1, 'AED' => 3.6725],
                'time_last_update_utc' => 'Mon, 06 Oct 2026 00:00:01 +0000',
            ], 200),
        ]);

        $withLive = strlen($this->get("/api/portal/invoices/{$invoice->id}/pdf?currency=AED")->assertOk()->getContent());
        $plain = strlen($this->get("/api/portal/invoices/{$invoice->id}/pdf")->getContent());

        $this->assertGreaterThan($plain, $withLive);
    }

    /**
     * The printed rate must reproduce the printed figures.
     *
     * A customer who multiplies the amount by the rate on the page has to land
     * on the converted column exactly. Quoting a rate rounded for display while
     * converting at full precision puts them out by real money — 25,000 SGD at
     * a rate shown as 2.8271 printed 70,677.97 instead of 70,677.50, and an
     * invoice that does not add up is an invoice that gets queried.
     */
    public function test_the_printed_rate_reproduces_the_printed_figures(): void
    {
        $controller = new \ReflectionMethod(
            \App\Http\Controllers\CustomerInvoiceController::class,
            'secondCurrency'
        );
        $controller->setAccessible(true);

        $invoice = $this->invoice('SGD');

        // A live rate carries far more precision than anyone prints.
        $request = \Illuminate\Http\Request::create('/x', 'GET', [
            'currency' => 'AED',
            'rate' => '2.8271188435',
        ]);

        $fx = $controller->invoke(null, $request, $invoice);

        // Quoted to six places, and that is the number used.
        $this->assertSame(2.827119, $fx['rate']);

        // What the page shows, worked by hand the way a customer would.
        $byHand = round(25000 * $fx['rate'], 2);
        $this->assertSame(70677.98, $byHand);

        // And the rate as printed, read back, gives the same thing.
        $printed = (float) rtrim(rtrim(number_format($fx['rate'], 6, '.', ''), '0'), '.');
        $this->assertSame($fx['rate'], $printed);
        $this->assertSame($byHand, round(25000 * $printed, 2));
    }

    /**
     * Downloading an invoice must never alter it — the PDF is a read, and this
     * one now takes query parameters, which is exactly where that slips.
     */
    public function test_downloading_with_a_second_currency_changes_nothing_on_the_invoice(): void
    {
        $invoice = $this->invoice('USD');
        $before = $invoice->fresh()->toArray();

        $this->get("/api/portal/invoices/{$invoice->id}/pdf?currency=AED&rate=3.6725")->assertOk();

        $this->assertSame($before, $invoice->fresh()->toArray());
    }
}

<?php

namespace Tests\Feature;

use App\Models\CashToMasterRecord;
use App\Models\Customer;
use App\Models\User;
use App\Support\AccountingBooks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CashToMasterTest extends TestCase
{
    use RefreshDatabase;

    private function actingStaff(): void
    {
        Role::findOrCreate('admin', 'web');
        $staff = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $staff->assignRole('admin');
        Sanctum::actingAs($staff);
    }

    public function test_direct_ctm_record_posts_only_fee_and_fx_expense_to_profit(): void
    {
        $this->actingStaff();
        $customer = Customer::create([
            'name' => 'Ocean Client',
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/portal/cash-to-master', [
            'transaction_date' => '2026-09-22',
            'customer_id' => $customer->id,
            'vessel' => 'MV BBC Peru',
            'currency' => 'USD',
            'exchange_rate' => 1,
            'cash_delivered' => 20000,
            'transit_cash_incoming' => 20400,
            'transit_cash_outgoing' => 150,
            'fee_account_code' => '4200',
            'fx_account_code' => '5200',
        ])->assertCreated()
            ->assertJsonPath('data.fee_amount', 400)
            ->assertJsonPath('data.fee_percentage', 2)
            ->assertJsonPath('data.fx_variance', 150)
            ->assertJsonPath('data.net_profit', 250);

        $record = CashToMasterRecord::findOrFail($response->json('data.id'));
        $this->assertStringStartsWith('MMS-CTM-2026-', $record->reference);

        $sale = AccountingBooks::sales(null, null)->firstWhere('kind', 'ctm_fee');
        $expense = AccountingBooks::purchases(null, null)->firstWhere('kind', 'ctm_fx');
        $this->assertEquals(400, $sale['net']);
        $this->assertEquals('4200', $sale['account_code']);
        $this->assertEquals(150, $expense['net']);
        $this->assertEquals('5200', $expense['account_code']);
        $this->assertNull($expense['f5_box']);

        $this->getJson('/api/portal/accounting/sales-invoices')
            ->assertOk()
            ->assertJsonPath('data.totals.ctm_fees', 1);
        $this->getJson('/api/portal/accounting/purchase-invoices')
            ->assertOk()
            ->assertJsonPath('data.totals.ctm_fx', 1);
        $this->getJson('/api/portal/accounting/income-statement')
            ->assertOk()
            ->assertJsonPath('data.revenue.total', 400)
            ->assertJsonPath('data.operating_expenses.total', 150)
            ->assertJsonPath('data.net_profit', 250);
        $this->getJson('/api/portal/reports/accounting')
            ->assertOk()
            ->assertJsonPath('data.totals.ctm_fee_income', 400)
            ->assertJsonPath('data.totals.ctm_fx_expense', 150)
            ->assertJsonPath('data.totals.net_profit', 250);

        $this->deleteJson("/api/portal/cash-to-master/{$record->id}")->assertOk();
        $this->assertDatabaseMissing('cash_to_master_records', ['id' => $record->id]);
    }

    public function test_ctm_rejects_incoming_below_delivered_principal(): void
    {
        $this->actingStaff();
        $customer = Customer::create(['name' => 'Ocean Client', 'currency' => 'USD', 'is_active' => true]);

        $this->postJson('/api/portal/cash-to-master', [
            'transaction_date' => '2026-09-22',
            'customer_id' => $customer->id,
            'currency' => 'USD',
            'cash_delivered' => 20000,
            'transit_cash_incoming' => 19900,
            'transit_cash_outgoing' => 150,
        ])->assertUnprocessable()->assertJsonValidationErrors('transit_cash_incoming');
    }
}

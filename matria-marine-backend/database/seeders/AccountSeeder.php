<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Support\GstCodes;
use Illuminate\Database\Seeder;

class AccountSeeder extends Seeder
{
    private const ACCOUNTS = [
        ['1000', 'Cash - SGD', Account::ASSET, GstCodes::OS, 'Bank and cash held in Singapore dollars.'],
        ['1010', 'Cash - USD', Account::ASSET, GstCodes::OS, 'Bank and cash held in US dollars.'],
        ['1020', 'Cash - EUR', Account::ASSET, GstCodes::OS, 'Bank and cash held in euros.'],
        ['1100', 'Trade Receivables', Account::ASSET, GstCodes::SR, 'Invoiced to customers and not yet collected.'],
        ['2000', 'Trade Payables', Account::LIABILITY, GstCodes::SR, 'Owed to vendors and not yet paid.'],
        ['2100', 'CTM Client Funds Held', Account::LIABILITY, GstCodes::OS, 'Client cash held temporarily for delivery to a vessel master.'],
        ['3000', "Owner's Equity", Account::EQUITY, GstCodes::OS, 'Capital introduced and retained earnings.'],
        ['4000', 'Sales - Local', Account::INCOME, GstCodes::SR, 'Standard-rated sales made within Singapore.'],
        ['4100', 'Sales - Export/International Services', Account::INCOME, GstCodes::ZI, 'Zero-rated sales — exports and international services. Most marine supply falls here.'],
        ['4200', 'CTM Service Fee Income', Account::INCOME, GstCodes::ZI, 'Fee earned for arranging Cash to Master services.'],
        ['5000', 'Cost of Sales', Account::EXPENSE, GstCodes::SR, 'Goods and services bought to fulfil a customer job.'],
        ['5100', 'Operating Expenses', Account::EXPENSE, GstCodes::SR, 'Running the business — overheads not tied to one job.'],
        // The staff-cost breakdown beneath 5100. Payroll posts to these three
        // rather than to 5100 itself, so the income statement shows salaries,
        // CPF and the levy the way a set of accounts is expected to.
        ['5100-01', 'Staff costs — Salaries', Account::EXPENSE, GstCodes::OS, 'Gross wages earned by staff before deductions.', '5100'],
        ['5100-02', 'Staff costs — CPF (employer)', Account::EXPENSE, GstCodes::OS, "The employer's CPF contribution — a cost to the business, not a deduction from the employee.", '5100'],
        ['5100-03', 'Staff costs — SDL & other contributions', Account::EXPENSE, GstCodes::OS, 'Skills Development Levy and other statutory employer contributions.', '5100'],
        ['5200', 'CTM FX Loss & Transfer Costs', Account::EXPENSE, GstCodes::OS, 'Foreign-exchange variance and transfer costs on CTM transactions.'],
    ];

    public function run(): void
    {
        $created = 0;

        foreach (self::ACCOUNTS as $i => $row) {
            // The sixth element is the parent account, present only on the
            // sub-accounts that break a bigger one down.
            [$code, $name, $type, $gst, $description] = $row;

            $account = Account::firstOrCreate(
                ['code' => $code],
                [
                    'parent_code' => $row[5] ?? null,
                    'name' => $name,
                    'type' => $type,
                    'gst_code' => $gst,
                    'description' => $description,
                    'is_active' => true,
                    // Ten-step gaps so an account can be slotted between two
                    // existing ones later without renumbering the chart.
                    'sort' => ($i + 1) * 10,
                ]
            );

            if ($account->wasRecentlyCreated) {
                $created++;
            }
        }

        $this->command?->info(sprintf(
            'Chart of accounts: %d created, %d already present (%d total).',
            $created,
            count(self::ACCOUNTS) - $created,
            Account::count()
        ));
    }
}

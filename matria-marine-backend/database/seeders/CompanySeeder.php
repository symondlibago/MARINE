<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
class CompanySeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('seeders/data/companies.csv');

        if (! is_file($path)) {
            $this->command->error("companies.csv not found at {$path}");

            return;
        }

        if (DB::table('vendors')->count() > 50 || DB::table('customers')->count() > 50) {
            $this->command->warn('vendors/customers already populated — skipping CompanySeeder to avoid duplicates.');

            return;
        }

        $fh = fopen($path, 'r');
        $header = array_map('trim', fgetcsv($fh));
        $idx = array_flip($header);

        $col = function (array $row, string $name) use ($idx): string {
            $i = $idx[$name] ?? null;

            return $i === null ? '' : trim((string) ($row[$i] ?? ''));
        };

        $now = Carbon::now();
        $vendors = [];
        $customers = [];
        $vCount = 0;
        $cCount = 0;

        $flush = function () use (&$vendors, &$customers) {
            if ($vendors) {
                DB::table('vendors')->insert($vendors);
                $vendors = [];
            }
            if ($customers) {
                DB::table('customers')->insert($customers);
                $customers = [];
            }
        };

        while (($row = fgetcsv($fh)) !== false) {
            $name = $col($row, 'Company Name');
            if ($name === '') {
                continue;                       // skip rows without a company name
            }

            $group = strtolower($col($row, 'Group'));
            $isVendor = str_contains($group, 'vendor');
            $isCustomer = str_contains($group, 'customer');
            if (! $isVendor && ! $isCustomer) {
                continue;                       // only vendors & customers
            }

            // Assemble a readable address block from the available parts.
            $address = implode("\n", array_filter([
                $col($row, 'Address 1'),
                $col($row, 'Address 2'),
                trim($col($row, 'City').' '.$col($row, 'Postal Code')),
                $col($row, 'Country'),
            ], fn ($p) => $p !== '')) ?: null;

            $email = $col($row, 'Email') ?: null;
            $phone = $col($row, 'Phone') ?: null;
            $nav = $col($row, 'Dynamics ID');
            $notes = $nav !== '' ? "NAV: {$nav}" : null;   // keep the Navision link

            if ($isVendor) {
                $vendors[] = [
                    'name' => $name,
                    'contact_name' => $col($row, 'Short Name') ?: null,
                    'email' => $email,
                    'phone' => $phone,
                    'address' => $address,
                    'currency' => 'USD',
                    'notes' => $notes,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $vCount++;
            }

            if ($isCustomer) {
                $customers[] = [
                    'name' => $name,
                    'address' => $address,
                    'email' => $email,
                    'phone' => $phone,
                    'currency' => 'USD',
                    'notes' => $notes,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $cCount++;
            }

            if (count($vendors) >= 500 || count($customers) >= 500) {
                $flush();
            }
        }

        $flush();
        fclose($fh);

        $this->command->info("Imported {$vCount} vendors and {$cCount} customers from companies.csv.");
    }
}

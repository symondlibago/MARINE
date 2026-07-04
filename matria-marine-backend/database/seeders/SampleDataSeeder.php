<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\Rfq;
use App\Models\RfqItem;
use App\Models\RfqVendor;
use App\Models\Vendor;
use App\Support\DocNumber;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Ready-to-test sample data (LOCAL ONLY) — 2 customers, 3 vendors, and 3 enquiries
 * already sent to all three vendors with priced quotes, so Compare & Award works out
 * of the box. No emails are sent. Safe to re-run: keyed on the aliased emails.
 */
class SampleDataSeeder extends Seeder
{
    public function run(): void
    {
        $customer1 = Customer::firstOrCreate(
            ['email' => 'libago.symond1@gmail.com'],
            ['name' => 'Customer 1', 'address' => "12 Marina Boulevard\nSingapore 018982", 'phone' => '(65) 6000 0001', 'currency' => 'USD', 'is_active' => true]
        );
        $customer2 = Customer::firstOrCreate(
            ['email' => 'libago.symond1+customer2@gmail.com'],
            ['name' => 'Customer 2', 'address' => "1 Harbour Drive\nSingapore 117440", 'phone' => '(65) 6000 0002', 'currency' => 'USD', 'is_active' => true]
        );

        $vendors = collect([1, 2, 3])->map(fn ($n) => Vendor::firstOrCreate(
            ['email' => "libago.symond1+vendor{$n}@gmail.com"],
            ['name' => "Vendor {$n}", 'contact_name' => "Sales Desk {$n}", 'phone' => "(65) 6100 100{$n}", 'currency' => 'USD', 'is_active' => true]
        ))->values();

        // Enquiry blueprints: vessel, port, and items [description, qty, unit, base price].
        $blueprints = [
            ['customer' => $customer1, 'vessel' => 'MV Test One', 'port' => 'Singapore', 'items' => [
                ['Cylinder head gasket set', 4, 'set', 320],
                ['Fuel injection pump, Bosch type P7100', 2, 'pcs', 1140],
                ['Air filter element, spin-on', 10, 'pcs', 48.5],
            ]],
            ['customer' => $customer1, 'vessel' => 'MV Test Two', 'port' => 'Jurong', 'items' => [
                ['Main engine lube oil filter', 12, 'pcs', 22],
                ['Turbocharger cartridge', 1, 'pcs', 4200],
            ]],
            ['customer' => $customer2, 'vessel' => 'MV Ocean Star', 'port' => 'Tuas', 'items' => [
                ['Mooring rope, 64mm x 220m', 2, 'coil', 1850],
                ['Safety helmet, white', 20, 'pcs', 9.5],
                ['Cotton waste rags', 50, 'kg', 1.2],
            ]],
        ];

        $factors = [1.00, 1.08, 0.95]; // per-vendor price spread

        foreach ($blueprints as $bp) {
            // Skip if a sample enquiry for this vessel already exists (idempotent).
            if (Rfq::where('ship_name', $bp['vessel'])->where('customer_id', $bp['customer']->id)->exists()) {
                continue;
            }

            $rfq = Rfq::create([
                'reference' => DocNumber::next('QTN'),
                'customer_id' => $bp['customer']->id,
                'ship_name' => $bp['vessel'],
                'delivery_port' => $bp['port'],
                'base_currency' => 'USD',
                'status' => 'sent',
                'received_date' => now()->toDateString(),
            ]);

            $lines = [];
            foreach ($bp['items'] as $sort => [$desc, $qty, $unit, $price]) {
                $lines[] = [
                    'item' => RfqItem::create(['rfq_id' => $rfq->id, 'description' => $desc, 'qty' => $qty, 'unit' => $unit, 'sort' => $sort]),
                    'price' => $price,
                ];
            }

            foreach ($vendors as $i => $vendor) {
                RfqVendor::create([
                    'rfq_id' => $rfq->id,
                    'vendor_id' => $vendor->id,
                    'token' => Str::random(48),
                    'seq' => $i + 1,
                    'status' => 'submitted',
                    'sent_at' => now(),
                    'responded_at' => now(),
                ]);

                $quote = Quote::create([
                    'rfq_id' => $rfq->id,
                    'vendor_id' => $vendor->id,
                    'quotation_number' => 'V'.($i + 1).'-Q'.$rfq->id,
                    'currency' => 'USD',
                    'exchange_rate' => 1,
                    'status' => 'submitted',
                    'submitted_at' => now(),
                ]);

                foreach ($lines as $k => $line) {
                    QuoteItem::create([
                        'quote_id' => $quote->id,
                        'rfq_item_id' => $line['item']->id,
                        'unit_cost' => round($line['price'] * $factors[$i], 2),
                        'qty_quoted' => $line['item']->qty,
                        'remarks' => ($i === 2 && $k === 0) ? 'Genuine OEM, 2-week lead time' : null,
                    ]);
                }
            }
        }
    }
}

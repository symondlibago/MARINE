<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\Rfq;
use App\Models\RfqItem;
use App\Models\RfqVendor;
use App\Models\User;
use App\Models\Vendor;
use App\Support\DocNumber;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class SampleDataSeeder extends Seeder
{
    public function run(): void
    {
        $staffId = User::orderBy('id')->value('id');

        // --- Customers ---
        $customer1 = Customer::firstOrCreate(
            ['name' => 'Customer 1'],
            ['email' => 'libago.symond1@gmail.com', 'address' => "12 Marina Boulevard\nSingapore 018982", 'phone' => '(65) 6000 1111', 'currency' => 'USD', 'is_active' => true]
        );
        $customer2 = Customer::firstOrCreate(
            ['name' => 'Customer 2'],
            ['email' => 'libago.symond1+customer2@gmail.com', 'address' => "88 Ocean Drive\nSingapore 098765", 'phone' => '(65) 6000 2222', 'currency' => 'USD', 'is_active' => true]
        );

        // --- Vendors 1–3 (used on every enquiry) ---
        $vendors = collect([1, 2, 3])->map(fn ($n) => Vendor::firstOrCreate(
            ['name' => "Vendor $n"],
            [
                'contact_name' => "Sales Desk $n",
                'email' => "libago.symond1+vendor$n@gmail.com",
                'phone' => "(65) 6100 100$n",
                'address' => "Block $n Pandan Loop\nSingapore 12801$n",
                'currency' => 'USD',
                'is_active' => true,
            ]
        ))->values();

        // --- Enquiries: [customer, vessel, their ref, items[desc, qty, unit, base unit price]] ---
        $blueprints = [
            ['customer' => $customer1, 'ship' => 'MV Test One', 'ref' => 'CE-REQ-1001', 'items' => [
                ['Fuel injection pump, Bosch type P7100', 2, 'pcs', 1200],
                ['Air filter element, spin-on', 10, 'pcs', 45],
                ['Cylinder head gasket set', 4, 'set', 320],
            ]],
            ['customer' => $customer2, 'ship' => 'MV Test Two', 'ref' => 'PO-2200-XYZ', 'items' => [
                ['Marine lube oil 15W40, drum 208L', 6, 'drum', 850],
                ['Impeller, sea-water pump', 8, 'pcs', 120],
                ['Zinc anode, hull mount', 20, 'pcs', 35],
                ['Gasket, exhaust manifold', 5, 'set', 90],
            ]],
            ['customer' => $customer1, 'ship' => 'MV Test Three', 'ref' => 'CE-REQ-1042', 'items' => [
                ['Turbocharger cartridge, ABB TPL', 1, 'pcs', 8500],
                ['O-ring kit, hydraulic', 12, 'kit', 25],
                ['Fuel filter, primary', 15, 'pcs', 38],
            ]],
        ];

        // Per-vendor price factors → Vendor 1 = list, Vendor 2 = pricier, Vendor 3 = cheapest.
        $factors = [1.00, 1.08, 0.95];

        foreach ($blueprints as $bp) {
            $rfq = Rfq::create([
                'reference' => DocNumber::next('QTN'),
                'customer_id' => $bp['customer']->id,
                'customer_reference' => $bp['ref'],
                'priority' => 'normal',
                'requirements' => ['Genuine spares only', 'IHM relevant'],
                'ship_name' => $bp['ship'],
                'requested_by' => 'Chief Engineer',
                'delivery_port' => 'Singapore',
                'received_date' => now()->toDateString(),
                'base_currency' => 'USD',
                'status' => 'sent',
                'created_by' => $staffId,
            ]);

            $rfqItems = [];
            foreach ($bp['items'] as $i => [$desc, $qty, $unit, $base]) {
                $rfqItems[] = [
                    'model' => RfqItem::create(['rfq_id' => $rfq->id, 'description' => $desc, 'qty' => $qty, 'unit' => $unit, 'sort' => $i]),
                    'base' => $base,
                ];
            }

            foreach ($vendors as $vi => $vendor) {
                $seq = $vi + 1;
                RfqVendor::create([
                    'rfq_id' => $rfq->id,
                    'vendor_id' => $vendor->id,
                    'token' => Str::random(48),
                    'seq' => $seq,
                    'sent_at' => now(),
                    'status' => 'submitted',
                ]);

                $quote = Quote::create([
                    'rfq_id' => $rfq->id,
                    'vendor_id' => $vendor->id,
                    'quotation_number' => 'VQ-'.$seq.'-'.$rfq->id,
                    'currency' => 'USD',
                    'exchange_rate' => 1,
                    'status' => 'submitted',
                    'submitted_at' => now(),
                ]);

                foreach ($rfqItems as $k => $ri) {
                    QuoteItem::create([
                        'quote_id' => $quote->id,
                        'rfq_item_id' => $ri['model']->id,
                        'unit_cost' => round($ri['base'] * $factors[$vi], 2),
                        'qty_quoted' => $ri['model']->qty,
                        'remarks' => ($k === 0 && $seq === 3) ? 'Genuine OEM · 2-week lead time' : null,
                    ]);
                }
            }
        }

        $this->command?->info('Sample data seeded: 2 customers, 3 vendors, '.count($blueprints).' enquiries with priced vendor quotes (local only).');
    }
}

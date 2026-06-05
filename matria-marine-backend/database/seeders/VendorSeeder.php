<?php

namespace Database\Seeders;

use App\Models\Vendor;
use Illuminate\Database\Seeder;

class VendorSeeder extends Seeder
{
    public function run(): void
    {
        // Stable, realistic rows (idempotent on email).
        $samples = [
            ['name' => 'Marine Provisions Co.', 'contact_name' => 'J. Cruz', 'email' => 'sales@marineprovisions.com', 'phone' => '+63 32 234 5678', 'currency' => 'USD'],
            ['name' => 'Harbor Fresh Supplies', 'contact_name' => 'L. Reyes', 'email' => 'orders@harborfresh.ph', 'phone' => '+63 32 876 5432', 'currency' => 'PHP'],
            ['name' => 'Gulf Ship Stores', 'contact_name' => 'M. Haddad', 'email' => 'info@gulfshipstores.ae', 'phone' => '+971 4 555 1234', 'currency' => 'AED'],
        ];

        foreach ($samples as $sample) {
            Vendor::firstOrCreate(['email' => $sample['email']], $sample);
        }

        // A few random fillers for testing the list/CRUD.
        Vendor::factory(6)->create();
    }
}

<?php

namespace Database\Seeders\Inventory;

use Illuminate\Database\Seeder;

class InventorySeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            VesselSeeder::class,
            ProvisionCategorySeeder::class,
            ProvisionItemSeeder::class,
        ]);
    }
}

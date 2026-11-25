<?php

namespace Database\Seeders\Inventory;

use App\Models\Inventory\Vessel;
use Illuminate\Database\Seeder;

class VesselSeeder extends Seeder
{
    public function run(): void
    {
        $vessel = Vessel::firstOrCreate(
            ['name' => 'OEL VARUN'],
            [
                'crew_size' => 20,
                'supply_period_days' => 30,
                'safety_margin_pct' => 10,
                'budget' => 0,
                'currency' => 'USD',
                'exchange_rate' => 1,
                'is_active' => true,
            ]
        );

        $this->command?->info("Vessel ready: {$vessel->name} (#{$vessel->id})");
    }
}

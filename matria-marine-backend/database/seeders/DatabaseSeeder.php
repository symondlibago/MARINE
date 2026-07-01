<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            AdminUserSeeder::class,
            CompanySeeder::class,
        ]);

        // Ready-to-test sample data — LOCAL ONLY, so `migrate:fresh --seed` on
        // production stays clean (super admin + company config only).
        if (app()->environment('local')) {
            $this->call(SampleDataSeeder::class);
        }
    }
}
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

        // Ready-to-test sample data — LOCAL ONLY, so production stays clean.
        if (app()->environment('local')) {
            $this->call(SampleDataSeeder::class);
        }
    }
}
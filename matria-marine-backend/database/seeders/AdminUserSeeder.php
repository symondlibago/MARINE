<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        // Check if admin exists to avoid duplicates
        if (!User::where('email', 'sales@matriamarine.com')->exists()) {
            User::create([
                'name' => 'Matria Admin',
                'email' => 'sales@matriamarine.com', // Login Email
                'password' => Hash::make('M@tria_2025'), // Login Password
                'role' => 'admin'
            ]);
        }
    }
}
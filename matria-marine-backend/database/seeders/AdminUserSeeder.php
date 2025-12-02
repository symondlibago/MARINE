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
        if (!User::where('email', 'admin@matriamarine.com')->exists()) {
            User::create([
                'name' => 'Matria Admin',
                'email' => 'admin@matriamarine.com', // Login Email
                'password' => Hash::make('Matria@2025'), // Login Password
                'role' => 'admin'
            ]);
        }
    }
}
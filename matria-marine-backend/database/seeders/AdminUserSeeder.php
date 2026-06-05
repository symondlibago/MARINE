<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        // Ensure the portal staff roles exist (spatie, 'web' guard).
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('staff', 'web');

        // Primary admin account. Created once with its seeded password; on
        // re-seed the password is left untouched.
        $admin = User::firstOrCreate(
            ['email' => 'sales@matriamarine.com'],   // Login Email
            [
                'name' => 'Matria Admin',
                'password' => Hash::make('M@tria_2025'), // Login Password (only set on first create)
                'role' => 'admin',
            ]
        );

        // Make sure it is a fully-enabled portal admin: legacy column (read by
        // the SPA) + spatie 'admin' role (checked by the API gate) + active.
        $admin->forceFill(['role' => 'admin', 'is_active' => true])->save();
        $admin->syncRoles(['admin']);

        $this->command?->info(
            "Admin ready: {$admin->email} (spatie: {$admin->getRoleNames()->implode(',')}, active: ".(int) $admin->is_active.')'
        );
    }
}

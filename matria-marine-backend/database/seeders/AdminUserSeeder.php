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
        // Portal staff roles (spatie, 'web' guard).
        Role::findOrCreate('super_admin', 'web');
        Role::findOrCreate('admin', 'web');

        // Primary account — the Super Admin (can do everything, incl. Media + MMS).
        $admin = User::firstOrCreate(
            ['username' => 'dyu'],                    // Super admin login handle
            [
                'name' => 'Matria Admin',
                'email' => 'sales@matriamarine.com',  // Login email (shared mailbox)
                'password' => Hash::make('M@tria_2025'), // only set on first create
                'role' => 'super_admin',
                'phone' => '(65) 82277151',
            ]
        );
        $admin->forceFill(['role' => 'super_admin', 'is_active' => true])->save();
        $admin->syncRoles(['super_admin']);

        // Only the primary Super Admin is seeded — all other staff are added
        // from the portal's "Manage Staff" page.

        $this->command?->info(
            "Super admin ready: {$admin->email} (spatie: {$admin->getRoleNames()->implode(',')})"
        );
    }
}

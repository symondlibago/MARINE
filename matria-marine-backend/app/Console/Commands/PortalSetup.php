<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds the procurement portal's staff roles and creates (or promotes) the
 * first admin user. Idempotent — safe to run repeatedly.
 *
 *   php artisan portal:setup --email=admin@matriamarine.com --name="Admin" --password=secret
 *   php artisan portal:setup        (interactive prompts)
 */
class PortalSetup extends Command
{
    protected $signature = 'portal:setup
                            {--email= : Email for the first admin user}
                            {--name= : Display name for the first admin user}
                            {--password= : Password for the first admin user}';

    protected $description = 'Seed procurement portal roles (admin, staff) and create/promote the first admin user.';

    /** Staff-facing roles for the procurement portal. */
    private const ROLES = ['admin', 'staff'];

    public function handle(): int
    {
        // Clear spatie's permission cache so freshly seeded roles are visible immediately.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // 1) Seed roles (idempotent) on the default 'web' guard.
        foreach (self::ROLES as $roleName) {
            Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
            $this->line("Role ensured: <info>{$roleName}</info>");
        }

        // 2) Gather admin details from options, falling back to prompts.
        $email = $this->option('email') ?: $this->ask('Admin email');
        $name = $this->option('name') ?: $this->ask('Admin name', 'Administrator');
        $password = $this->option('password') ?: $this->secret('Admin password (blank to keep existing)');

        if (! $email) {
            $this->error('An email is required.');

            return self::FAILURE;
        }

        // 3) Create or promote the admin user (idempotent).
        $user = User::where('email', $email)->first();

        if ($user) {
            if ($password) {
                $user->password = Hash::make($password);
            }
            $user->name = $name ?: $user->name;
            $user->role = 'admin';   // legacy string column the SPA reads
            $user->is_active = true;
            $user->save();
            $this->info("Existing user promoted to admin: {$email}");
        } else {
            if (! $password) {
                $this->error('A password is required to create a new admin user.');

                return self::FAILURE;
            }
            $user = User::create([
                'name' => $name ?: 'Administrator',
                'email' => $email,
                'password' => Hash::make($password),
                'role' => 'admin',   // legacy string column the SPA reads
                'is_active' => true,
            ]);
            $this->info("Admin user created: {$email}");
        }

        // 4) Assign the spatie 'admin' role (idempotent).
        $user->syncRoles(['admin']);
        $this->info("Spatie 'admin' role assigned to {$email}.");

        $this->newLine();
        $this->info('Portal setup complete.');

        return self::SUCCESS;
    }
}

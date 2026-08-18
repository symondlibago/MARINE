<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * The payroll module keeps its migrations in their own folder so it is obvious
 * at a glance which tables belong to it. The migrator only globs the top level
 * of database/migrations, so that folder is registered here.
 *
 * A provider of its own rather than a line in AppServiceProvider: payroll can
 * then be added or removed as one self-contained set of files.
 */
class PayrollServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(database_path('migrations/payroll'));
    }
}

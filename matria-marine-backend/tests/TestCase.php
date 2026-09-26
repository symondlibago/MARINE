<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /**
     * Refuse to run against anything but a throwaway database.
     *
     * phpunit.xml points the suite at sqlite :memory:, and two things quietly
     * override it:
     *
     *   1. A CACHED CONFIG (`bootstrap/cache/config.php`). A cached config is
     *      read instead of the environment entirely, so APP_ENV=testing never
     *      switches the connection.
     *   2. A `.env` pointing at a real server — a production database being
     *      inspected, credentials pasted in to run a migration.
     *
     * In either case `RefreshDatabase` drops every table in whatever database
     * it finds. Neither is hypothetical: the first wiped the local development
     * database on 1 September 2026, and the second aimed the suite at the
     * PRODUCTION database on 10 September.
     *
     * One comparison per test against losing the database.
     */
    protected function setUpTraits()
    {
        // Checked HERE, not in setUp(), and the difference matters.
        //
        // Laravel's setUp() does refreshApplication() and then setUpTraits(),
        // and it is setUpTraits() that fires RefreshDatabase — which drops and
        // re-migrates the whole schema. A check placed after parent::setUp()
        // therefore runs when the damage is already done. This point is the
        // last one where the config is loaded but the database has not been
        // touched.
        $this->guardTestDatabase();

        return parent::setUpTraits();
    }

    private function guardTestDatabase(): void
    {
        $connection = config('database.default');
        $driver = config("database.connections.{$connection}.driver");
        $database = config("database.connections.{$connection}.database");

        if ($driver !== 'sqlite' || ! in_array($database, [':memory:', ''], true)) {
            $this->fail(
                "Refusing to run tests against '{$driver}' database '{$database}'.\n\n".
                "Tests must run on sqlite :memory:. Nothing has been touched. Either:\n".
                "  - a stale config cache is overriding phpunit.xml  ->  php artisan config:clear\n".
                "  - or .env points at a real database  ->  check DB_HOST / DB_DATABASE\n"
            );
        }
    }

    /**
     * Give an admin every portal page.
     *
     * Admins only reach the screens they were given (App\Support\PortalPages),
     * so a test about documents rather than access needs its admin to have the
     * lot — otherwise it is testing the access check by accident.
     */
    protected function grantAllPages(\App\Models\User $user): void
    {
        foreach (\App\Support\PortalPages::keys() as $key) {
            \Spatie\Permission\Models\Permission::findOrCreate(\App\Support\PortalPages::permission($key), 'web');
        }

        $user->syncPermissions(array_map(
            fn ($key) => \App\Support\PortalPages::permission($key),
            \App\Support\PortalPages::keys()
        ));
    }
}

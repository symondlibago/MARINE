<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /**
     * Refuse to run against anything but a throwaway database.
     *
     * phpunit.xml points the suite at sqlite :memory:, but a CACHED CONFIG
     * (`bootstrap/cache/config.php`) silently overrides it — a cached config is
     * read instead of the environment entirely, so APP_ENV=testing never
     * switches the connection. `RefreshDatabase` then drops every table in
     * whatever database the cache names.
     *
     * That is not hypothetical: it wiped the local development database on
     * 1 September 2026. The cost of the check is one comparison per test; the
     * cost of not having it is the whole database.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $connection = config('database.default');
        $driver = config("database.connections.{$connection}.driver");
        $database = config("database.connections.{$connection}.database");

        if ($driver !== 'sqlite' || ! in_array($database, [':memory:', ''], true)) {
            $this->fail(
                "Refusing to run tests against '{$driver}' database '{$database}'.\n".
                "Tests must run on sqlite :memory:. This almost always means a stale\n".
                "config cache is overriding phpunit.xml — run:\n\n".
                "    php artisan config:clear\n"
            );
        }
    }
}

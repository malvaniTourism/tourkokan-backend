<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;

trait CreatesApplication
{
    /**
     * Creates the application.
     *
     * @return \Illuminate\Foundation\Application
     */
    public function createApplication()
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        $this->guardAgainstNonTestDatabase($app);

        return $app;
    }

    /**
     * Refuse to run against anything but a dedicated test schema.
     *
     * `RefreshDatabase` runs `migrate:fresh`, so a missing or reverted DB_DATABASE override
     * in phpunit.xml would silently wipe the development database instead of the test one.
     *
     * This has to happen here rather than in TestCase::setUp(): Laravel boots the traits —
     * RefreshDatabase included — inside its own setUp(), so a check placed after
     * parent::setUp() would fire only once the damage was already done.
     */
    private function guardAgainstNonTestDatabase($app): void
    {
        $config   = $app->make('config');
        $default  = $config->get('database.default');
        $database = $config->get("database.connections.{$default}.database");

        if (str_ends_with((string) $database, '_test') || $database === ':memory:') {
            return;
        }

        fwrite(STDERR, PHP_EOL . "Refusing to run tests against database '{$database}'. "
            . 'Tests must use a dedicated schema — check the DB_DATABASE override in '
            . 'phpunit.xml.' . PHP_EOL);

        exit(1);
    }
}

<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        if (file_exists(dirname(__DIR__).'/bootstrap/cache/config.php')) {
            throw new \RuntimeException(
                'Refusing to run tests while Laravel configuration is cached. Run `php artisan config:clear` first.'
            );
        }

        parent::setUp();

        if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:') {
            throw new \RuntimeException('Tests must use the in-memory SQLite database.');
        }

        // Every external request in automated tests must be explicitly faked.
        Http::preventStrayRequests();
    }
}

<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Tests\TestCase;

class ProductionConfigurationTest extends TestCase
{
    public function test_production_seeder_refuses_to_create_demo_accounts(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Demo accounts must not be seeded in production.');

        (new DatabaseSeeder)->run();
    }

    public function test_health_endpoint_boots_successfully(): void
    {
        $this->get('/up')->assertOk();
    }
}

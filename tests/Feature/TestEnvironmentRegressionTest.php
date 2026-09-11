<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TestEnvironmentRegressionTest extends TestCase
{
    public function test_test_runtime_cannot_use_the_shop_database_or_production_configuration(): void
    {
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame(base_path('tests'), app()->environmentPath());
        $this->assertSame('.env.testing', app()->environmentFile());
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->assertNull(config('database.connections.sqlite.url'));
        $this->assertSame('array', config('cache.default'));
        $this->assertSame('array', config('session.driver'));
        $this->assertSame('array', config('mail.driver'));
        $this->assertFalse((bool) config('installer.enabled'));
        $this->assertStringContainsString('tests', app()->getCachedConfigPath());
        $this->assertStringContainsString('tests', app()->getCachedRoutesPath());
        $this->assertStringContainsString('tests', app()->getCachedPackagesPath());
        $this->assertStringContainsString('tests', app()->getCachedServicesPath());
    }
}

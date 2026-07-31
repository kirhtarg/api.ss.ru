<?php

namespace Tests\Unit\Partner;

use RuntimeException;
use Tests\Support\IsolatedTestEnvironmentGuard;
use Tests\TestCase;

class IsolatedTestEnvironmentGuardTest extends TestCase
{
    public function test_guard_fails_closed_when_any_database_fact_is_not_isolated(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(IsolatedTestEnvironmentGuard::BLOCKED_MESSAGE);

        IsolatedTestEnvironmentGuard::assertFacts([
            'raw_app_env' => 'testing',
            'raw_db_connection' => 'mysql',
            'raw_db_database' => 'shop_production',
            'application_env' => 'testing',
            'default_connection' => 'mysql',
            'configured_database' => 'shop_production',
            'active_connection' => 'mysql',
            'active_database' => 'shop_production',
            'configuration_cached' => true,
        ]);
    }

    public function test_guard_accepts_only_uncached_sqlite_memory_configuration(): void
    {
        IsolatedTestEnvironmentGuard::assertFacts([
            'raw_app_env' => 'testing',
            'raw_db_connection' => 'sqlite',
            'raw_db_database' => ':memory:',
            'application_env' => 'testing',
            'default_connection' => 'sqlite',
            'configured_database' => ':memory:',
            'active_connection' => 'sqlite',
            'active_database' => ':memory:',
            'configuration_cached' => false,
        ]);

        $this->addToAssertionCount(1);
    }
}

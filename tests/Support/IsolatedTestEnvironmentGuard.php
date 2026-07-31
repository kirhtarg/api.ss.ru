<?php

namespace Tests\Support;

use Illuminate\Foundation\Application;
use RuntimeException;

final class IsolatedTestEnvironmentGuard
{
    public const BLOCKED_MESSAGE = 'Tests are blocked because the database is not isolated from production';

    public static function assertBeforeBootstrap(string $basePath): void
    {
        $configuredCachePath = self::environmentValue('APP_CONFIG_CACHE');
        $cachePath = $configuredCachePath !== null && $configuredCachePath !== ''
            ? $configuredCachePath
            : $basePath.'/bootstrap/cache/config.php';

        $safe = self::environmentValue('APP_ENV') === 'testing'
            && self::environmentValue('DB_CONNECTION') === 'sqlite'
            && self::environmentValue('DB_DATABASE') === ':memory:'
            && ! is_file($cachePath);

        if (! $safe) {
            throw new RuntimeException(self::BLOCKED_MESSAGE);
        }
    }

    public static function assertSafe(Application $application): void
    {
        $facts = [
            'raw_app_env' => self::environmentValue('APP_ENV'),
            'raw_db_connection' => self::environmentValue('DB_CONNECTION'),
            'raw_db_database' => self::environmentValue('DB_DATABASE'),
            'application_env' => $application->environment(),
            'default_connection' => $application->make('config')->get('database.default'),
            'configured_database' => $application->make('config')->get('database.connections.sqlite.database'),
            'configuration_cached' => $application->configurationIsCached(),
        ];
        self::assertPreConnectionFacts($facts);

        $connection = $application->make('db')->connection('sqlite');
        self::assertFacts($facts + [
            'active_connection' => $connection->getName(),
            'active_database' => $connection->getConfig('database'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    public static function assertFacts(array $facts): void
    {
        $safe = ($facts['raw_app_env'] ?? null) === 'testing'
            && ($facts['raw_db_connection'] ?? null) === 'sqlite'
            && ($facts['raw_db_database'] ?? null) === ':memory:'
            && ($facts['application_env'] ?? null) === 'testing'
            && ($facts['default_connection'] ?? null) === 'sqlite'
            && ($facts['configured_database'] ?? null) === ':memory:'
            && ($facts['active_connection'] ?? null) === 'sqlite'
            && ($facts['active_database'] ?? null) === ':memory:'
            && ($facts['configuration_cached'] ?? true) === false;

        if (! $safe) {
            throw new RuntimeException(self::BLOCKED_MESSAGE);
        }
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    private static function assertPreConnectionFacts(array $facts): void
    {
        $safe = ($facts['raw_app_env'] ?? null) === 'testing'
            && ($facts['raw_db_connection'] ?? null) === 'sqlite'
            && ($facts['raw_db_database'] ?? null) === ':memory:'
            && ($facts['application_env'] ?? null) === 'testing'
            && ($facts['default_connection'] ?? null) === 'sqlite'
            && ($facts['configured_database'] ?? null) === ':memory:'
            && ($facts['configuration_cached'] ?? true) === false;

        if (! $safe) {
            throw new RuntimeException(self::BLOCKED_MESSAGE);
        }
    }

    private static function environmentValue(string $key): ?string
    {
        $value = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key);

        return is_string($value) ? $value : null;
    }
}

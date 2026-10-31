<?php

namespace App\Shell\Integrations;

use Illuminate\Support\Facades\DB;

/** One-time flash needs server-side shared sessions and an atomic cross-worker lock. */
final class SecretSessionStore
{
    public const MESSAGE = 'Показ секретов временно недоступен: требуется безопасное хранилище сессий и блокировок.';

    public static function ready(): bool
    {
        $session = config('session.driver');
        $store = config('session.block_store') ?? config('cache.default');
        $lock = is_string($store) ? config('cache.stores.'.$store.'.driver') : null;
        if ($session === 'cookie') {
            return false;
        }
        // The in-memory driver has no cross-process semantics; only in-process PHPUnit fixtures may use it.
        if (self::unitFixture()) {
            return true;
        }
        $shared = ['database', 'redis', 'memcached', 'dynamodb'];
        if (in_array($session, $shared, true) && in_array($lock, $shared, true)
            && self::databaseIsShared($session, config('session.connection'))
            && self::databaseIsShared($lock, config('cache.stores.'.$store.'.lock_connection') ?? config('cache.stores.'.$store.'.connection'))) {
            return true;
        }

        // File locks are process-shared only on this explicitly single-node local installation.
        return app()->environment('local') && config('features.E1-10.defaults.single_node_locks') === true
            && in_array($session, ['file', ...$shared], true) && in_array($lock, ['file', ...$shared], true);
    }

    public static function assertReady(): void
    {
        abort_unless(self::ready(), 503, self::MESSAGE);
    }

    public static function assertIndependentCommit(): void
    {
        // A secret must never leave a transaction that an outer caller can still roll back.
        abort_unless(DB::transactionLevel() === 0 || self::unitFixture(), 503, self::MESSAGE);
    }

    private static function unitFixture(): bool
    {
        $store = config('session.block_store') ?? config('cache.default');

        return app()->environment('testing') && defined('PHPUNIT_COMPOSER_INSTALL')
            && config('session.driver') === 'array' && config('cache.stores.'.$store.'.driver') === 'array';
    }

    private static function databaseIsShared(mixed $driver, mixed $connection): bool
    {
        if ($driver !== 'database') {
            return true;
        }
        $name = $connection ?: config('database.default');

        return is_string($name) && in_array(config('database.connections.'.$name.'.driver'), ['pgsql', 'mysql', 'mariadb', 'sqlsrv'], true);
    }
}

<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use LogicException;

/** Fail closed before fixtures or migration refreshes can touch a non-test DB. */
final class MfaTestDatabase
{
    public static function assert(bool $localFixture = false): void
    {
        $allowedEnvironment = $localFixture
            ? app()->environment('local') && getenv('MFA_LOCAL_FIXTURE') === '1'
            : app()->environment('testing');
        if (! $allowedEnvironment || config('database.default') !== 'pgsql') {
            throw new LogicException('Isolated PostgreSQL test database required.');
        }
        $connection = DB::connection('pgsql');
        $database = $connection->getDatabaseName();
        $expected = config('runtime.test_database');
        $preview = config('runtime.preview_database');
        $username = $connection->getConfig('username');
        if (! is_string($database) || ! preg_match('/\Asouzpir_test_[a-z0-9_]+\z/D', $database)
            || ! is_string($expected) || $database !== $expected || ! is_string($preview) || $preview === '' || $database === $preview
            || ! is_string($username) || $username === '') {
            throw new LogicException('Isolated PostgreSQL test database required.');
        }
        $server = $connection->selectOne('select current_database() as db, current_user as role');
        if (! $server || $server->db !== $database || $server->role !== $username) {
            throw new LogicException('Test database identity does not match configuration.');
        }
    }
}

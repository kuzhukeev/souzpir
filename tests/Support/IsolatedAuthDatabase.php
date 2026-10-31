<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use LogicException;

final class IsolatedAuthDatabase
{
    public static function assert(): void
    {
        $connection = DB::connection('pgsql');
        $database = $connection->getDatabaseName();
        $expected = config('runtime.test_database');
        $preview = config('runtime.preview_database');
        $username = $connection->getConfig('username');
        if (! app()->environment('testing') || config('database.default') !== 'pgsql'
            || ! is_string($database) || ! preg_match('/\Asouzpir_test_[a-z0-9_]+\z/D', $database)
            || ! is_string($expected) || $database !== $expected || ! is_string($preview) || $preview === '' || $database === $preview
            || ! is_string($username) || $username === '') {
            throw new LogicException('Isolated PostgreSQL test database required.');
        }
        $identity = $connection->selectOne('select current_database() as db, current_user as role');
        if (! $identity || $identity->db !== $database || $identity->role !== $username) {
            throw new LogicException('Test database identity does not match configuration.');
        }
    }
}

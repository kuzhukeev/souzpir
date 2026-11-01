<?php

namespace Tests\Feature\Vision\Measurements\Fixtures;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Fail before migrations or fixture writes unless the explicitly named test database matches the live connection. */
final class IsolatedDatabaseGuard
{
    public static function check(): void
    {
        $name = (string) getenv('E804_RACE_TEST_DB');
        $role = (string) getenv('E804_RACE_TEST_ROLE');
        $host = (string) getenv('E804_RACE_TEST_HOST');
        $port = (string) getenv('E804_RACE_TEST_PORT');
        $root = realpath(base_path());
        $preview = config('runtime.preview_database');
        if (! app()->environment('testing') || ! $root || realpath(getcwd()) !== $root
            || realpath(Application::inferBasePath()) !== $root || config('database.default') !== 'pgsql'
            || ! preg_match('/^souzpir_test_[a-z0-9_]+$/D', $name)
            || ! preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $role)
            || filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false || ! str_starts_with($host, '127.')
            || ! ctype_digit($port) || (int) $port < 1 || (int) $port > 65535
            || ! is_string($preview) || $preview === '' || $preview === $name
            || config('runtime.test_database') !== $name) {
            throw new LogicException('Refusing race test: explicit isolated test database settings are invalid.');
        }

        $db = DB::connection();
        if ($db->getDriverName() !== 'pgsql' || $db->getDatabaseName() !== $name || $db->getConfig('username') !== $role
            || $db->getConfig('host') !== $host || (string) $db->getConfig('port') !== $port) {
            throw new LogicException('Refusing race test: configured connection does not match explicit test settings.');
        }
        $actual = $db->selectOne('select current_database() as name, current_user as role, host(inet_server_addr()) as host, inet_server_port() as port, rolsuper, rolcreatedb, rolcreaterole, rolreplication, rolbypassrls from pg_roles where rolname=current_user');
        if (! $actual || $actual->name !== $name || $actual->role !== $role || $actual->host !== $host || (string) $actual->port !== $port
            || $actual->rolsuper || $actual->rolcreatedb || $actual->rolcreaterole || $actual->rolreplication || $actual->rolbypassrls) {
            throw new LogicException('Refusing race test: live database or role does not meet isolation requirements.');
        }
    }
}

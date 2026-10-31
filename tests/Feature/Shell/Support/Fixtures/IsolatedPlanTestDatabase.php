<?php

namespace Tests\Feature\Shell\Support\Fixtures;

use Illuminate\Foundation\Application;
use LogicException;

/** Fail before any migration or fixture write if the selected database is not the configured local test database. */
final class IsolatedPlanTestDatabase
{
    public static function assert(Application $app, ?string $workerDatabase = null): void
    {
        $config = $app['config'];
        if (! $app->environment('testing') || $config->get('database.default') !== 'pgsql') {
            throw new LogicException('Isolated PostgreSQL test database required.');
        }
        $connection = $app['db']->connection('pgsql');
        $database = $connection->getDatabaseName();
        $expected = $config->get('runtime.test_database');
        $preview = $config->get('runtime.preview_database');
        $user = $connection->getConfig('username');
        $port = $connection->getConfig('port');
        if (! is_string($database) || ! preg_match('/\Asouzpir_test_[a-z0-9_]+\z/D', $database)
            || ! is_string($expected) || $expected === '' || $database !== $expected
            || ! is_string($preview) || $preview === '' || $database === $preview
            || ($workerDatabase !== null && $workerDatabase !== $database)
            || ! is_string($user) || $user === '' || ! is_numeric($port)) {
            throw new LogicException('Isolated PostgreSQL test database required.');
        }
        $server = $connection->selectOne('select current_database() as db, current_user as role, inet_server_addr() as host, inet_server_port() as port');
        if (! $server || $server->db !== $database || $server->role !== $user
            || ! self::loopback($server->host) || (int) $server->port !== (int) $port) {
            throw new LogicException('Test database server or role does not match the local configuration.');
        }
    }

    private static function loopback(mixed $address): bool
    {
        if (! is_string($address)) {
            return false;
        }
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return str_starts_with($address, '127.');
        }

        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
            && inet_pton($address) === inet_pton('::1');
    }
}

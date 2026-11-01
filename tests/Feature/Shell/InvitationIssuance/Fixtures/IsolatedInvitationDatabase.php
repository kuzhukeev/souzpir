<?php

namespace Tests\Feature\Shell\InvitationIssuance\Fixtures;

use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\IsolatedAuthDatabase;

/** Shared by the parent and child before any migrations or fixture writes. */
final class IsolatedInvitationDatabase
{
    /** @return array{database: string, role: string, server: string, port: int, client: string} */
    public static function assert(?array $expectedTarget = null): array
    {
        IsolatedAuthDatabase::assert();
        $base = realpath(app()->basePath());
        $cwd = realpath(getcwd());
        if (! is_string($base) || ! is_string($cwd) || (DIRECTORY_SEPARATOR === '\\' ? strcasecmp($base, $cwd) !== 0 : $base !== $cwd)) {
            throw new LogicException('Test application root must match the current worktree.');
        }

        $row = DB::connection('pgsql')->selectOne(<<<'SQL'
            SELECT current_database() AS database, current_user AS role, session_user = current_user AS unchanged_role,
                   host(inet_server_addr()) AS server, inet_server_port() AS port, host(inet_client_addr()) AS client,
                   r.rolsuper, r.rolcreatedb, r.rolcreaterole, r.rolreplication, r.rolbypassrls,
                   d.datdba = r.oid AS owns_database,
                   EXISTS (SELECT 1 FROM pg_roles elevated
                           WHERE elevated.oid <> r.oid
                             AND (elevated.rolsuper OR elevated.rolcreatedb OR elevated.rolcreaterole
                                  OR elevated.rolreplication OR elevated.rolbypassrls)
                             AND pg_has_role(r.oid, elevated.oid, 'MEMBER')) AS member_of_privileged_role
            FROM pg_roles r
            JOIN pg_database d ON d.datname = current_database()
            WHERE r.rolname = current_user
            SQL);
        if (! $row || ! self::loopback($row->server) || ! self::loopback($row->client) || ! is_numeric($row->port)
            || $row->rolsuper || $row->rolcreatedb || $row->rolcreaterole || $row->rolreplication || $row->rolbypassrls
            || $row->member_of_privileged_role || ! $row->owns_database || ! $row->unchanged_role) {
            throw new LogicException('A loopback PostgreSQL connection with a dedicated, nonprivileged database owner is required.');
        }
        $target = ['database' => $row->database, 'role' => $row->role, 'server' => $row->server, 'port' => (int) $row->port, 'client' => $row->client];
        if ($expectedTarget !== null && $target !== $expectedTarget) {
            throw new LogicException('Child test database identity differs from its parent.');
        }

        return $target;
    }

    private static function loopback(mixed $address): bool
    {
        if (! is_string($address)) {
            return false;
        }
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return str_starts_with($address, '127.');
        }

        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false && inet_pton($address) === inet_pton('::1');
    }
}

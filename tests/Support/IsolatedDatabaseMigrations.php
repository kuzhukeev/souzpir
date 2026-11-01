<?php

namespace Tests\Support;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Migrate and clear only an explicitly configured, least-privilege test database. */
trait IsolatedDatabaseMigrations
{
    use DatabaseMigrations;

    public function runDatabaseMigrations(): void
    {
        $this->assertIsolatedMigrationDatabase();
        $this->beforeRefreshingDatabase();
        $this->assertIsolatedMigrationDatabase();
        $this->refreshTestDatabase();
        $this->afterRefreshingDatabase();

        $this->beforeApplicationDestroyed(function (): void {
            try {
                $this->assertIsolatedMigrationDatabase();
                $this->artisan('db:wipe', [
                    '--database' => 'pgsql',
                    '--drop-views' => true,
                    '--drop-types' => true,
                ])->assertSuccessful();
            } finally {
                RefreshDatabaseState::$migrated = false;
            }
        });
    }

    private function assertIsolatedMigrationDatabase(): void
    {
        IsolatedAuthDatabase::assert();

        $role = DB::connection('pgsql')->selectOne(<<<'SQL'
            SELECT r.rolsuper, r.rolcreatedb, r.rolcreaterole, r.rolreplication,
                   r.rolbypassrls, d.datdba = r.oid AS owns_database,
                   session_user = current_user AS unchanged_role
            FROM pg_roles r
            JOIN pg_database d ON d.datname = current_database()
            WHERE r.rolname = current_user
            SQL);

        $this->assertDedicatedRole($role);
    }

    private function assertDedicatedRole(?object $role): void
    {
        if (! $role || $role->rolsuper || $role->rolcreatedb || $role->rolcreaterole
            || $role->rolreplication || $role->rolbypassrls || ! $role->owns_database || ! $role->unchanged_role) {
            throw new LogicException('A dedicated, nonprivileged test database owner is required.');
        }
    }
}

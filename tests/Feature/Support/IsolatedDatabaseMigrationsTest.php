<?php

namespace Tests\Feature\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\Support\IsolatedDatabaseMigrations;
use Tests\TestCase;

final class IsolatedDatabaseMigrationsTest extends TestCase
{
    use IsolatedDatabaseMigrations;

    public function test_guard_accepts_the_dedicated_test_database(): void
    {
        $this->assertIsolatedMigrationDatabase();
        self::assertSame(config('runtime.test_database'), DB::connection('pgsql')->getDatabaseName());
    }

    public function test_guard_refuses_a_mismatched_or_preview_database_before_cleanup(): void
    {
        Schema::create('test_cleanup_sentinel', static function (Blueprint $table): void {
            $table->id();
        });
        $expected = config('runtime.test_database');
        $preview = config('runtime.preview_database');

        try {
            foreach ([
                ['runtime.test_database' => 'souzpir_test_other'],
                ['runtime.preview_database' => $expected],
            ] as $unsafe) {
                config($unsafe);
                try {
                    $this->assertIsolatedMigrationDatabase();
                    self::fail('The unsafe database configuration was accepted.');
                } catch (LogicException) {
                    self::assertTrue(Schema::hasTable('test_cleanup_sentinel'));
                } finally {
                    config(['runtime.test_database' => $expected, 'runtime.preview_database' => $preview]);
                }
            }
        } finally {
            config(['runtime.test_database' => $expected, 'runtime.preview_database' => $preview]);
        }
    }

    public function test_guard_rejects_privileged_or_unowned_database_roles(): void
    {
        $safe = (object) [
            'rolsuper' => false, 'rolcreatedb' => false, 'rolcreaterole' => false,
            'rolreplication' => false, 'rolbypassrls' => false,
            'owns_database' => true, 'unchanged_role' => true,
        ];
        $this->assertDedicatedRole($safe);

        foreach (['rolsuper', 'rolcreatedb', 'rolcreaterole', 'rolreplication', 'rolbypassrls', 'owns_database', 'unchanged_role'] as $field) {
            $unsafe = clone $safe;
            $unsafe->{$field} = ! $unsafe->{$field};
            try {
                $this->assertDedicatedRole($unsafe);
                self::fail('An unsafe test database role was accepted.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
        }
    }
}

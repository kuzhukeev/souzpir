<?php

namespace Tests\Feature\ProtectedOperations;

use App\Contracts\ProtectedOperations\IdempotencyConflict;
use App\Contracts\ProtectedOperations\IdempotencyFailure;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\User;
use App\ProtectedOperations\PostgresTransactionalIdempotency;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class PostgresIdempotencyTest extends TestCase
{
    use DatabaseMigrations;

    public function createApplication()
    {
        $app = parent::createApplication();
        $expected = env('DB_TEST_DATABASE');
        if (! $app->environment('testing') || ! is_string($expected) || ! str_contains($expected, '_test_')
            || DB::selectOne('select current_database() as name')->name !== $expected
            || config('database.connections.pgsql.database') !== $expected || env('APP_PREVIEW_DATABASE') === $expected) {
            throw new RuntimeException('An isolated test database is required.');
        }

        return $app;
    }

    public function runDatabaseMigrations(): void
    {
        $this->beforeRefreshingDatabase();
        $this->refreshTestDatabase();
        $this->afterRefreshingDatabase();
        $this->beforeApplicationDestroyed(static function (): void {
            RefreshDatabaseState::$migrated = false;
        });
    }

    public function test_replay_returns_persisted_result_and_changed_body_conflicts(): void
    {
        [$org, $brand] = $this->fixture();
        $service = app(PostgresTransactionalIdempotency::class);
        $callback = fn (): array => $this->write($brand->id, 'result-private-synthetic');
        $result = $service->execute($org->id, $brand->id, 'test.write', 'key-private-synthetic', 'body-private-synthetic', $callback);
        self::assertSame(['value' => 'result-private-synthetic'], $result);
        self::assertSame($result, $service->execute($org->id, $brand->id, 'test.write', 'key-private-synthetic', 'body-private-synthetic', $callback));
        self::assertSame('Syntheticx', DB::table('brands')->where('id', $brand->id)->value('name'));
        $receipt = DB::table('protected_operation_receipts')->first();
        self::assertStringNotContainsString('private-synthetic', json_encode((array) $receipt, JSON_THROW_ON_ERROR));
        try {
            $service->execute($org->id, $brand->id, 'test.write', 'key-private-synthetic', 'different', $callback);
            self::fail('Different request was accepted.');
        } catch (IdempotencyConflict $error) {
            self::assertSame('Idempotency key was used with different content.', $error->getMessage());
            self::assertNull($error->getPrevious());
            self::assertSame('Syntheticx', DB::table('brands')->where('id', $brand->id)->value('name'));
        }
    }

    public function test_scope_is_separate_and_mismatched_brand_organization_is_rejected(): void
    {
        [$org, $brand] = $this->fixture();
        [$otherOrg, $otherBrand] = $this->fixture();
        $sibling = app(TenantContextRunner::class)->run(new TenantContext($org->id, source: 'job'), fn () => Brand::create(['name' => 'Synthetic', 'theme' => 'pyro']));
        $service = app(PostgresTransactionalIdempotency::class);
        $call = fn ($organization, $b) => $service->execute($organization, $b, 'test.write', 'key', 'body', fn () => $this->write($b, 'ok'));
        self::assertSame(['value' => 'ok'], $call($org->id, $brand->id));
        self::assertSame(['value' => 'ok'], $call($otherOrg->id, $otherBrand->id));
        self::assertSame(['value' => 'ok'], $call($org->id, $sibling->id));
        self::assertSame(3, DB::table('protected_operation_receipts')->count());
        try {
            $call($org->id, $otherBrand->id);
            self::fail('Cross-organization brand was accepted.');
        } catch (IdempotencyFailure) {
            self::assertSame(3, DB::table('protected_operation_receipts')->count());
        }
    }

    public function test_database_blocks_rewriting_history_and_incomplete_commits(): void
    {
        [$org, $brand] = $this->fixture();
        $service = app(PostgresTransactionalIdempotency::class);
        $service->execute($org->id, $brand->id, 'test.write', 'key', 'body', fn () => $this->write($brand->id, 'ok'));
        foreach ([
            fn () => DB::table('protected_operation_receipts')->update(['result_ciphertext' => 'forged']),
            fn () => DB::table('protected_operation_receipts')->delete(),
            fn () => DB::statement('TRUNCATE protected_operation_receipts'),
            fn () => DB::table('protected_operation_key_guard')->update(['fingerprint' => str_repeat('a', 64)]),
            fn () => DB::table('protected_operation_key_guard')->delete(),
            fn () => DB::statement('TRUNCATE protected_operation_key_guard'),
        ] as $forbidden) {
            try {
                $forbidden();
                self::fail('Immutable history was changed.');
            } catch (QueryException) {
                self::assertSame(1, DB::table('protected_operation_receipts')->count());
            }
        }
        try {
            DB::transaction(fn () => DB::table('protected_operation_receipts')->insert([
                'id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id,
                'operation' => 'test.incomplete', 'key_digest' => str_repeat('a', 64), 'request_digest' => str_repeat('b', 64),
            ]));
            self::fail('An incomplete receipt committed.');
        } catch (\PDOException|QueryException) {
            self::assertSame(1, DB::table('protected_operation_receipts')->count());
        }
    }

    public function test_same_key_reentrancy_fails_and_rolls_back(): void
    {
        [$org, $brand] = $this->fixture();
        $service = app(PostgresTransactionalIdempotency::class);
        try {
            $service->execute($org->id, $brand->id, 'test.write', 'key', 'body', function () use ($service, $org, $brand): array {
                $this->write($brand->id, 'first');

                return $service->execute($org->id, $brand->id, 'test.write', 'key', 'body', fn () => $this->write($brand->id, 'second'));
            });
            self::fail('A recursive operation was treated as completed.');
        } catch (IdempotencyFailure $e) {
            self::assertNull($e->getPrevious());
        }
        self::assertSame('Synthetic', DB::table('brands')->where('id', $brand->id)->value('name'));
        self::assertSame(0, DB::table('protected_operation_receipts')->count());
    }

    public function test_request_and_key_do_not_appear_in_exception_trace(): void
    {
        [$org, $brand] = $this->fixture();
        try {
            app(PostgresTransactionalIdempotency::class)->execute($org->id, $brand->id, 'test.write', 'sensitive-synthetic-key', 'sensitive-synthetic-body', function (): array {
                throw new RuntimeException('synthetic failure');
            });
            self::fail('Callback failure was ignored.');
        } catch (RuntimeException $error) {
            self::assertStringNotContainsString('sensitive-synthetic-key', (string) $error);
            self::assertStringNotContainsString('sensitive-synthetic-body', (string) $error);
        }
    }

    public static function sensitiveFailures(): array
    {
        return ['callback exception' => ['callback'], 'SQL exception' => ['sql'], 'forged conflict' => ['conflict']];
    }

    #[DataProvider('sensitiveFailures')]
    public function test_callback_and_sql_failures_are_sanitized_and_rolled_back(string $failure): void
    {
        [$org, $brand] = $this->fixture();
        $secret = 'private-synthetic-callback-secret';
        $caught = null;
        try {
            app(PostgresTransactionalIdempotency::class)->execute($org->id, $brand->id, 'test.write', 'key', 'body', function () use ($brand, $failure, $secret): array {
                $this->write($brand->id, 'before failure');
                if ($failure === 'sql') {
                    DB::statement('SELECT ?::integer', [$secret]);
                }
                if ($failure === 'conflict') {
                    throw new IdempotencyConflict($secret);
                }
                throw new RuntimeException($secret, 0, new RuntimeException($secret));
            });
        } catch (\Throwable $error) {
            $caught = $error;
        }
        self::assertNotNull($caught);
        self::assertInstanceOf(IdempotencyFailure::class, $caught);
        self::assertStringNotContainsString($secret, $caught->getMessage());
        self::assertStringNotContainsString($secret, $caught->getTraceAsString());
        self::assertStringNotContainsString($secret, (string) $caught);
        self::assertNull($caught->getPrevious());
        self::assertSame('Synthetic', DB::table('brands')->where('id', $brand->id)->value('name'));
        self::assertSame(0, DB::table('protected_operation_receipts')->count());
        self::assertSame(['value' => 'retry'], app(PostgresTransactionalIdempotency::class)->execute($org->id, $brand->id, 'test.write', 'key', 'body', fn () => $this->write($brand->id, 'retry')));
        self::assertSame('Syntheticx', DB::table('brands')->where('id', $brand->id)->value('name'));
    }

    public function test_encryption_failure_is_sanitized_and_rolls_back_the_database_write(): void
    {
        [$org, $brand] = $this->fixture();
        $secret = 'private-synthetic-encryption-secret';
        $encrypter = app('encrypter');
        Crypt::shouldReceive('encryptString')->once()->andThrow(new RuntimeException($secret, 0, new RuntimeException($secret)));
        $caught = null;
        try {
            app(PostgresTransactionalIdempotency::class)->execute($org->id, $brand->id, 'test.write', 'key', 'body', fn () => $this->write($brand->id, 'result'));
        } catch (\Throwable $error) {
            $caught = $error;
        } finally {
            Crypt::swap($encrypter);
        }
        self::assertNotNull($caught);
        self::assertInstanceOf(IdempotencyFailure::class, $caught);
        self::assertStringNotContainsString($secret, $caught->getMessage());
        self::assertStringNotContainsString($secret, $caught->getTraceAsString());
        self::assertStringNotContainsString($secret, (string) $caught);
        self::assertNull($caught->getPrevious());
        self::assertSame('Synthetic', DB::table('brands')->where('id', $brand->id)->value('name'));
        self::assertSame(0, DB::table('protected_operation_receipts')->count());
        self::assertSame(['value' => 'retry'], app(PostgresTransactionalIdempotency::class)->execute($org->id, $brand->id, 'test.write', 'key', 'body', fn () => $this->write($brand->id, 'retry')));
        self::assertSame('Syntheticx', DB::table('brands')->where('id', $brand->id)->value('name'));
    }

    public function test_history_guards_apply_to_a_restricted_database_role(): void
    {
        $privileges = DB::selectOne('SELECT rolsuper OR rolcreaterole AS can_create_role FROM pg_roles WHERE rolname = current_user');
        if (! $privileges || ! $privileges->can_create_role) {
            self::markTestSkipped('Additional non-owner role check requires CREATEROLE; ordinary-role DML guards are tested separately.');
        }
        [$org, $brand] = $this->fixture();
        $role = 'sp_c02_'.strtolower(Str::random(12));
        DB::statement('CREATE ROLE '.$role.' NOLOGIN');
        try {
            DB::statement('GRANT USAGE ON SCHEMA public TO '.$role);
            DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE, TRUNCATE ON protected_operation_key_guard, protected_operation_receipts TO '.$role);
            DB::transaction(function () use ($role, $org, $brand): void {
                DB::statement('SET LOCAL ROLE '.$role);
                self::assertSame(['value' => 'ok'], app(PostgresTransactionalIdempotency::class)->execute($org->id, $brand->id, 'test.write', 'key', 'body', fn () => ['value' => 'ok']));
            });
            foreach (['DELETE FROM protected_operation_receipts', 'TRUNCATE protected_operation_receipts', 'DELETE FROM protected_operation_key_guard'] as $sql) {
                try {
                    DB::transaction(function () use ($role, $sql): void {
                        DB::statement('SET LOCAL ROLE '.$role);
                        DB::statement($sql);
                    });
                    self::fail('Restricted role changed protected history.');
                } catch (QueryException) {
                    self::assertSame(1, DB::table('protected_operation_receipts')->count());
                }
            }
        } finally {
            DB::statement('REVOKE ALL ON protected_operation_key_guard, protected_operation_receipts FROM '.$role);
            DB::statement('REVOKE USAGE ON SCHEMA public FROM '.$role);
            DB::statement('DROP ROLE '.$role);
        }
    }

    public function test_failure_and_outer_rollback_allow_a_fresh_execution(): void
    {
        [$org, $brand] = $this->fixture();
        $service = app(PostgresTransactionalIdempotency::class);
        try {
            $service->execute($org->id, $brand->id, 'test.write', 'key', 'body', function () use ($brand): array {
                $this->write($brand->id, 'temporary');
                throw new RuntimeException('synthetic failure');
            });
            self::fail('Failure was ignored.');
        } catch (IdempotencyFailure $e) {
            self::assertNull($e->getPrevious());
        }
        self::assertSame('Synthetic', DB::table('brands')->where('id', $brand->id)->value('name'));
        self::assertSame(0, DB::table('protected_operation_receipts')->count());
        DB::beginTransaction();
        try {
            self::assertSame(['value' => 'first'], $service->execute($org->id, $brand->id, 'test.write', 'key', 'body', fn () => $this->write($brand->id, 'first')));
        } finally {
            DB::rollBack();
        }
        self::assertSame('Synthetic', DB::table('brands')->where('id', $brand->id)->value('name'));
        self::assertSame(0, DB::table('protected_operation_receipts')->count());
        self::assertSame(['value' => 'second'], $service->execute($org->id, $brand->id, 'test.write', 'key', 'body', fn () => $this->write($brand->id, 'second')));
        self::assertSame('Syntheticx', DB::table('brands')->where('id', $brand->id)->value('name'));
    }

    public function test_key_rotation_fails_closed_before_a_second_write(): void
    {
        [$org, $brand] = $this->fixture();
        $service = app(PostgresTransactionalIdempotency::class);
        $callback = fn () => $this->write($brand->id, 'ok');
        $service->execute($org->id, $brand->id, 'test.write', 'key', 'body', $callback);
        $original = config('app.key');
        config(['app.key' => 'synthetic-different-key']);
        try {
            $service->execute($org->id, $brand->id, 'test.write', 'key', 'body', $callback);
            self::fail('Unexpected key rotation was accepted.');
        } catch (IdempotencyFailure $e) {
            self::assertNull($e->getPrevious());
        } finally {
            config(['app.key' => $original]);
        }
        self::assertSame('Syntheticx', DB::table('brands')->where('id', $brand->id)->value('name'));
    }

    public static function concurrentBodies(): array
    {
        return [
            'same body after commit' => ['body', false, '{"value":"from-parent"}'],
            'different body after commit' => ['other', false, 'conflict'],
            'same body after rollback' => ['body', true, '{"answer":"from-worker"}'],
        ];
    }

    #[DataProvider('concurrentBodies')]
    public function test_concurrent_requests_serialize_on_the_receipt(string $body, bool $rollback, string $expected): void
    {
        [$org, $brand] = $this->fixture();
        $service = app(PostgresTransactionalIdempotency::class);
        $service->execute($org->id, $brand->id, 'seed', 'seed', 'seed', fn () => []);
        $marker = base_path('.local/tmp/c02-idempotency-'.Str::random(16));
        $process = new Process([PHP_BINARY, '-d', 'sys_temp_dir='.base_path('.local/tmp'), base_path('tests/Feature/ProtectedOperations/Fixtures/idempotency-worker.php'), $org->id, $brand->id, $body, $marker, '--env=testing'], base_path());
        $process->setTimeout(15);
        DB::beginTransaction();
        try {
            self::assertSame(['value' => 'from-parent'], $service->execute($org->id, $brand->id, 'test.write', 'synthetic-key', 'body', fn () => $this->write($brand->id, 'from-parent')));
            $process->start();
            $deadline = microtime(true) + 10;
            while (! is_file($marker) && $process->isRunning() && microtime(true) < $deadline) {
                usleep(10000);
            }
            self::assertFileExists($marker);
            $pid = (int) file_get_contents($marker);
            do {
                DB::select('select pg_stat_clear_snapshot()');
                $waiting = DB::table('pg_stat_activity')->where('pid', $pid)->where('wait_event_type', 'Lock')->exists();
                if (! $waiting) {
                    usleep(10000);
                }
            } while (! $waiting && $process->isRunning() && microtime(true) < $deadline);
            self::assertTrue($waiting, 'Concurrent call must wait for the first transaction.');
            if ($rollback) {
                DB::rollBack();
            } else {
                DB::commit();
            }
            $process->wait();
            self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
            self::assertSame($expected, trim($process->getOutput()));
            self::assertSame('Syntheticx', DB::table('brands')->where('id', $brand->id)->value('name'));
            self::assertSame(1, DB::table('protected_operation_receipts')->where('operation', 'test.write')->count());
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($process->isRunning()) {
                $process->stop();
            }
            if (is_file($marker)) {
                unlink($marker);
            }
        }
    }

    private function fixture(): array
    {
        $user = User::create(['name' => 'Synthetic owner', 'email' => Str::random(20).'@example.test', 'password' => 'test-only']);
        $org = Organization::create(['name' => 'Synthetic organization', 'owner_user_id' => $user->id]);
        $brand = app(TenantContextRunner::class)->run(new TenantContext($org->id, source: 'job'), fn () => Brand::create(['name' => 'Synthetic', 'theme' => 'pyro']));

        return [$org, $brand];
    }

    private function write(string $brand, string $result): array
    {
        DB::table('brands')->where('id', $brand)->update(['name' => DB::raw("name || 'x'")]);

        return ['value' => $result];
    }
}

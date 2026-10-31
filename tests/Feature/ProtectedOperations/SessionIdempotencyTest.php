<?php

namespace Tests\Feature\ProtectedOperations;

use App\Contracts\ProtectedOperations\IdempotencyConflict;
use App\Contracts\ProtectedOperations\IdempotencyFailure;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\User;
use App\ProtectedOperations\PostgresSessionIdempotency;
use App\ProtectedOperations\PostgresTransactionalIdempotency;
use App\Shell\Auth\AuthSessionRegistry;
use App\Shell\Auth\Retention\SessionRetention;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class SessionIdempotencyTest extends TestCase
{
    use DatabaseMigrations;

    public function createApplication()
    {
        $app = parent::createApplication();
        $expected = env('DB_TEST_DATABASE');
        if (! $app->environment('testing') || ! is_string($expected) || ! str_contains($expected, '_test_')
            || DB::selectOne('select current_database() as name')->name !== $expected
            || config('database.connections.pgsql.database') !== $expected || env('APP_PREVIEW_DATABASE') === $expected) {
            throw new \RuntimeException('An isolated test database is required.');
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

    public function test_real_session_without_brand_replays_the_first_result_and_rejects_a_different_request(): void
    {
        [$user, $request] = $this->fixture();
        $service = app(PostgresSessionIdempotency::class);
        $write = fn (): array => $this->write($user, 'private-result');
        $first = $service->execute($request, 'test.session', 'private-key', 'private-body', $write);
        self::assertSame(['value' => 'private-result'], $first);
        self::assertSame($first, $service->execute($request, 'test.session', 'private-key', 'private-body', $write));
        self::assertSame('Syntheticx', DB::table('users')->where('id', $user->id)->value('name'));
        self::assertSame(0, DB::table('brands')->count());
        $stored = json_encode(DB::table('c02_session_receipts')->first(), JSON_THROW_ON_ERROR);
        foreach (['private-key', 'private-body', 'private-result', $request->session()->getId()] as $secret) {
            self::assertStringNotContainsString($secret, $stored);
        }
        try {
            $service->execute($request, 'test.session', 'private-key', 'different-body', $write);
            self::fail('Changed request was accepted.');
        } catch (IdempotencyConflict $error) {
            self::assertSame('Idempotency key was used with different content.', $error->getMessage());
            self::assertNull($error->getPrevious());
            self::assertStringNotContainsString('different-body', json_encode($error->getTrace(), JSON_THROW_ON_ERROR));
        }
        self::assertSame(1, DB::table('c02_session_receipts')->count());
        self::assertSame('Syntheticx', DB::table('users')->where('id', $user->id)->value('name'));
    }

    public function test_foreign_actor_and_revoked_session_are_denied_before_replay_or_write(): void
    {
        [$first, $request] = $this->fixture();
        [$other, $otherRequest] = $this->fixture();
        $service = app(PostgresSessionIdempotency::class);
        $writeFirst = fn () => $this->write($first, 'first-only');
        self::assertSame(['value' => 'first-only'], $service->execute($request, 'test.session', 'key', 'body', $writeFirst));
        $foreign = $this->requestFor($other, $request->session()->getId());
        try {
            $service->execute($foreign, 'test.session', 'key', 'body', fn () => $this->write($other, 'forged'));
            self::fail('Another actor used the session.');
        } catch (IdempotencyFailure $error) {
            self::assertNull($error->getPrevious());
        }
        $sameUserOtherSession = $this->requestFor($first, Str::random(40));
        app(AuthSessionRegistry::class)->issue($sameUserOtherSession, $first, $first->auth_version);
        self::assertSame(['value' => 'same-user-other-session'], $service->execute($sameUserOtherSession, 'test.session', 'key', 'body', fn () => $this->write($first, 'same-user-other-session')));
        // A genuinely authenticated second session has its own receipt scope.
        self::assertSame(['value' => 'other-only'], $service->execute($otherRequest, 'test.session', 'key', 'body', fn () => $this->write($other, 'other-only')));
        self::assertSame(3, DB::table('c02_session_receipts')->count());
        app(AuthSessionRegistry::class)->withinActive($request, fn () => app(AuthSessionRegistry::class)->revokeCurrent($request));
        try {
            $service->execute($request, 'test.session', 'key', 'body', $writeFirst);
            self::fail('Revoked session replayed a receipt.');
        } catch (IdempotencyFailure $error) {
            self::assertNull($error->getPrevious());
        }
        self::assertSame('Syntheticxx', DB::table('users')->where('id', $first->id)->value('name'));
        self::assertSame('Syntheticx', DB::table('users')->where('id', $other->id)->value('name'));
        self::assertSame(['value' => 'other-only'], $service->execute($otherRequest, 'test.session', 'key', 'body', fn () => $this->write($other, 'unreachable')));
    }

    public function test_a_swapped_encrypted_result_from_another_session_is_not_replayed(): void
    {
        [$first, $request] = $this->fixture();
        [$other, $otherRequest] = $this->fixture();
        $service = app(PostgresSessionIdempotency::class);
        $service->execute($request, 'test.session', 'key', 'body', fn () => $this->write($first, 'first-private'));
        $firstId = DB::table('c02_session_receipts')->value('id');
        $service->execute($otherRequest, 'test.session', 'key', 'body', fn () => $this->write($other, 'other-private'));
        $otherCipher = DB::table('c02_session_receipts')->where('id', '<>', $firstId)->value('result_ciphertext');
        // Simulate storage tampering only in this isolated test database.
        DB::statement('ALTER TABLE c02_session_receipts DISABLE TRIGGER c02_session_receipt_guard');
        try {
            DB::table('c02_session_receipts')->where('id', $firstId)->update(['result_ciphertext' => $otherCipher]);
        } finally {
            DB::statement('ALTER TABLE c02_session_receipts ENABLE TRIGGER c02_session_receipt_guard');
        }
        try {
            $service->execute($request, 'test.session', 'key', 'body', fn () => $this->write($first, 'never'));
            self::fail('A ciphertext from another session was accepted.');
        } catch (IdempotencyFailure $error) {
            self::assertNull($error->getPrevious());
        }
        self::assertSame('Syntheticx', DB::table('users')->where('id', $first->id)->value('name'));
        self::assertSame(['value' => 'other-private'], $service->execute($otherRequest, 'test.session', 'key', 'body', fn () => $this->write($other, 'never')));
    }

    public function test_expired_session_and_obsolete_auth_version_fail_before_creating_a_receipt(): void
    {
        [$expiredUser, $expired] = $this->fixture();
        [$staleUser, $stale] = $this->fixture();
        $hash = hash_hmac('sha256', 'auth-session:'.$expired->session()->getId(), (string) config('app.key'));
        DB::table('auth_session_registry')->where('session_hash', $hash)->update(['expires_at' => now()->subMinute()]);
        DB::table('users')->where('id', $staleUser->id)->increment('auth_version');
        foreach ([[$expiredUser, $expired], [$staleUser, $stale]] as [$user, $request]) {
            try {
                app(PostgresSessionIdempotency::class)->execute($request, 'test.session', 'key', 'body', fn () => $this->write($user, 'never'));
                self::fail('An expired or stale session was accepted.');
            } catch (IdempotencyFailure $error) {
                self::assertNull($error->getPrevious());
            }
            self::assertSame('Synthetic', DB::table('users')->where('id', $user->id)->value('name'));
        }
        self::assertSame(0, DB::table('c02_session_leases')->count());
        self::assertSame(0, DB::table('c02_session_receipts')->count());
    }

    public function test_session_rotation_and_expired_session_retention_remain_compatible(): void
    {
        [$user, $request] = $this->fixture();
        $service = app(PostgresSessionIdempotency::class);
        $first = $service->execute($request, 'test.session', 'key', 'body', fn () => $this->write($user, 'before-rotation'));
        $oldId = $request->session()->getId();
        $oldRequest = $this->requestFor($user, $oldId);
        $leaseId = DB::table('c02_session_leases')->value('id');
        app(AuthSessionRegistry::class)->rotate($request);
        self::assertNotSame($oldId, $request->session()->getId());
        self::assertSame($leaseId, DB::table('c02_session_leases')->value('id'));
        self::assertSame($first, $service->execute($request, 'test.session', 'key', 'body', fn () => $this->write($user, 'must-not-run')));
        try {
            $service->execute($request, 'test.session', 'key', 'changed', fn () => $this->write($user, 'must-not-run'));
            self::fail('Rotation lost the idempotency conflict.');
        } catch (IdempotencyConflict $error) {
            self::assertNull($error->getPrevious());
        }
        try {
            $service->execute($oldRequest, 'test.session', 'key', 'body', fn () => $this->write($user, 'must-not-run'));
            self::fail('The retired session ID was accepted.');
        } catch (IdempotencyFailure $error) {
            self::assertNull($error->getPrevious());
        }
        app(AuthSessionRegistry::class)->withinActive($request, fn () => app(AuthSessionRegistry::class)->revokeCurrent($request));
        DB::table('auth_session_registry')->update(['revoked_at' => now()->subDays(31)]);
        self::assertSame(1, app(SessionRetention::class)->prune(30, 10));
        self::assertSame(0, DB::table('c02_session_leases')->count());
        self::assertSame(1, DB::table('c02_session_receipts')->count());
        try {
            $service->execute($request, 'test.session', 'key', 'body', fn () => $this->write($user, 'must-not-run'));
            self::fail('A pruned session was accepted.');
        } catch (IdempotencyFailure $error) {
            self::assertNull($error->getPrevious());
        }
        // Even if a future lease reuses the same raw session ID, it cannot
        // acquire the retired lease's stored result.
        app(AuthSessionRegistry::class)->issue($request, $user, $user->auth_version);
        $currentHash = hash_hmac('sha256', 'auth-session:'.$request->session()->getId(), (string) config('app.key'));
        try {
            DB::table('c02_session_leases')->insert(['id' => $leaseId, 'session_hash' => $currentHash]);
            self::fail('A historical lease identity was reused.');
        } catch (QueryException $error) {
            self::assertSame('23505', $error->errorInfo[0]);
        }
        self::assertSame(['value' => 'new-lease'], $service->execute($request, 'test.session', 'key', 'body', fn () => $this->write($user, 'new-lease')));
        self::assertNotSame($leaseId, DB::table('c02_session_leases')->value('id'));
        self::assertSame(2, DB::table('c02_session_lease_ids')->count());
        self::assertSame(2, DB::table('c02_session_receipts')->count());
    }

    public function test_rollback_and_untrusted_callback_errors_do_not_commit_or_expose_secrets(): void
    {
        [$user, $request] = $this->fixture();
        $service = app(PostgresSessionIdempotency::class);
        try {
            $service->execute($request, 'test.session', 'private-key', 'private-body', function () use ($user): array {
                $this->write($user, 'discard');
                throw new IdempotencyConflict('private-callback-message');
            });
            self::fail('Callback exception was accepted.');
        } catch (IdempotencyFailure $error) {
            self::assertNull($error->getPrevious());
            $trace = json_encode($error->getTrace(), JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_THROW_ON_ERROR);
            foreach (['private-key', 'private-body', 'private-callback-message', $request->session()->getId()] as $secret) {
                self::assertStringNotContainsString($secret, (string) $error.$trace);
            }
        }
        self::assertSame('Synthetic', DB::table('users')->where('id', $user->id)->value('name'));
        self::assertSame(0, DB::table('c02_session_receipts')->count());
        DB::beginTransaction();
        try {
            self::assertSame(['value' => 'rolled-back'], $service->execute($request, 'test.session', 'key', 'body', fn () => $this->write($user, 'rolled-back')));
        } finally {
            DB::rollBack();
        }
        self::assertSame(0, DB::table('c02_session_receipts')->count());
        self::assertSame(['value' => 'committed'], $service->execute($request, 'test.session', 'key', 'body', fn () => $this->write($user, 'committed')));
        self::assertSame('Syntheticx', DB::table('users')->where('id', $user->id)->value('name'));
    }

    public function test_database_guards_session_receipts_and_fails_closed_on_key_change(): void
    {
        [$user, $request] = $this->fixture();
        $service = app(PostgresSessionIdempotency::class);
        $service->execute($request, 'test.session', 'key', 'body', fn () => $this->write($user, 'original'));
        foreach ([
            fn () => DB::table('c02_session_receipts')->update(['result_ciphertext' => 'forged']),
            fn () => DB::table('c02_session_receipts')->delete(),
            fn () => DB::statement('TRUNCATE c02_session_receipts'),
            fn () => DB::table('c02_session_leases')->delete(),
            fn () => DB::table('c02_session_leases')->update(['session_hash' => str_repeat('a', 64)]),
            fn () => DB::table('c02_session_lease_ids')->delete(),
            fn () => DB::statement('TRUNCATE c02_session_lease_ids'),
        ] as $forbidden) {
            try {
                $forbidden();
                self::fail('Session history was changed.');
            } catch (QueryException) {
                self::assertSame(1, DB::table('c02_session_receipts')->count());
            }
        }
        $oldKey = config('app.key');
        config(['app.key' => 'synthetic-different-key']);
        try {
            $service->execute($request, 'test.session', 'key', 'body', fn () => $this->write($user, 'must-not-run'));
            self::fail('A changed key was accepted.');
        } catch (IdempotencyFailure $error) {
            self::assertNull($error->getPrevious());
        } finally {
            config(['app.key' => $oldKey]);
        }
        self::assertSame('Syntheticx', DB::table('users')->where('id', $user->id)->value('name'));
        self::assertSame(['value' => 'original'], $service->execute($request, 'test.session', 'key', 'body', fn () => $this->write($user, 'must-not-run')));
        $leaseId = DB::table('c02_session_leases')->value('id');
        try {
            DB::transaction(fn () => DB::table('c02_session_receipts')->insert([
                'id' => (string) Str::ulid(), 'lease_id' => $leaseId, 'operation' => 'test.incomplete',
                'key_digest' => str_repeat('a', 64), 'request_digest' => str_repeat('b', 64),
            ]));
            self::fail('An incomplete session receipt committed.');
        } catch (\PDOException|QueryException) {
            self::assertSame(1, DB::table('c02_session_receipts')->count());
        }
    }

    public function test_session_and_existing_brand_receipts_share_key_guard_without_sharing_scope(): void
    {
        [$user, $request] = $this->fixture();
        $sessionService = app(PostgresSessionIdempotency::class);
        $session = $sessionService->execute($request, 'test.shared', 'key', 'body', fn () => $this->write($user, 'session'));
        $organization = Organization::create(['name' => 'Synthetic organization']);
        $brand = app(TenantContextRunner::class)->run(new TenantContext($organization->id, source: 'job'), fn () => Brand::create(['name' => 'Synthetic brand']));
        $brandService = app(PostgresTransactionalIdempotency::class);
        $brandResult = $brandService->execute($organization->id, $brand->id, 'test.shared', 'key', 'body', fn () => ['value' => 'brand']);
        self::assertSame(['value' => 'session'], $session);
        self::assertSame(['value' => 'brand'], $brandResult);
        self::assertSame($session, $sessionService->execute($request, 'test.shared', 'key', 'body', fn () => $this->write($user, 'never')));
        self::assertSame($brandResult, $brandService->execute($organization->id, $brand->id, 'test.shared', 'key', 'body', fn () => ['value' => 'never']));
        self::assertSame(1, DB::table('protected_operation_key_guard')->count());
        self::assertSame(1, DB::table('protected_operation_receipts')->count());
        self::assertSame(1, DB::table('c02_session_receipts')->count());
        self::assertSame('Syntheticx', DB::table('users')->where('id', $user->id)->value('name'));
    }

    public function test_new_session_after_application_key_change_cannot_start_a_second_operation(): void
    {
        [$user, $request] = $this->fixture();
        $service = app(PostgresSessionIdempotency::class);
        self::assertSame(['value' => 'original'], $service->execute($request, 'test.session', 'key', 'body', fn () => $this->write($user, 'original')));
        $originalKey = config('app.key');
        config(['app.key' => 'synthetic-different-key']);
        try {
            $newRequest = $this->requestFor($user, Str::random(40));
            app(AuthSessionRegistry::class)->issue($newRequest, $user, $user->auth_version);
            try {
                $service->execute($newRequest, 'test.session', 'key', 'body', fn () => $this->write($user, 'never'));
                self::fail('A new session bypassed the application key guard.');
            } catch (IdempotencyFailure $error) {
                self::assertNull($error->getPrevious());
            }
        } finally {
            config(['app.key' => $originalKey]);
        }
        self::assertSame(1, DB::table('c02_session_receipts')->count());
        self::assertSame(1, DB::table('c02_session_lease_ids')->count());
        self::assertSame('Syntheticx', DB::table('users')->where('id', $user->id)->value('name'));
        self::assertSame(['value' => 'original'], $service->execute($request, 'test.session', 'key', 'body', fn () => $this->write($user, 'never')));
    }

    public function test_reentrant_same_key_rolls_back_without_a_write(): void
    {
        [$user, $request] = $this->fixture();
        $service = app(PostgresSessionIdempotency::class);
        try {
            $service->execute($request, 'test.session', 'key', 'body', function () use ($request, $service, $user): array {
                $this->write($user, 'discard');

                return $service->execute($request, 'test.session', 'key', 'body', fn () => $this->write($user, 'never'));
            });
            self::fail('Reentrant receipt was treated as complete.');
        } catch (IdempotencyFailure $error) {
            self::assertNull($error->getPrevious());
        }
        self::assertSame('Synthetic', DB::table('users')->where('id', $user->id)->value('name'));
        self::assertSame(0, DB::table('c02_session_receipts')->count());
    }

    public function test_session_revocation_serializes_with_the_receipt_transaction(): void
    {
        [$user, $request] = $this->fixture();
        $service = app(PostgresSessionIdempotency::class);
        config(['database.connections.c02_session_revoker' => config('database.connections.pgsql')]);
        $revoker = DB::connection('c02_session_revoker');
        DB::beginTransaction();
        try {
            $service->execute($request, 'test.session', 'key', 'body', fn () => $this->write($user, 'first'));
            $revoker->beginTransaction();
            try {
                $revoker->statement("SET LOCAL lock_timeout = '250ms'");
                $hash = hash_hmac('sha256', 'auth-session:'.$request->session()->getId(), (string) config('app.key'));
                $revoker->table('auth_session_registry')->where('session_hash', $hash)->update(['revoked_at' => now()]);
                self::fail('Revocation crossed an active receipt transaction.');
            } catch (QueryException $error) {
                self::assertSame('55P03', $error->errorInfo[0]);
            } finally {
                $revoker->rollBack();
            }
            DB::commit();
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            DB::purge('c02_session_revoker');
        }
        app(AuthSessionRegistry::class)->withinActive($request, fn () => app(AuthSessionRegistry::class)->revokeCurrent($request));
        try {
            $service->execute($request, 'test.session', 'key', 'body', fn () => $this->write($user, 'never'));
            self::fail('Revoked session replayed after revocation committed.');
        } catch (IdempotencyFailure $error) {
            self::assertNull($error->getPrevious());
        }
        self::assertSame('Syntheticx', DB::table('users')->where('id', $user->id)->value('name'));
    }

    public static function concurrentCommitOrRollback(): array
    {
        return [
            'commit' => [false, 'body', ['value' => 'parent']],
            'rollback' => [true, 'body', ['value' => 'child']],
            'conflicting request' => [false, 'different', ['conflict' => true]],
        ];
    }

    #[DataProvider('concurrentCommitOrRollback')]
    public function test_two_processes_serialize_the_same_session_operation(bool $rollback, string $body, array $expected): void
    {
        [$user, $request] = $this->fixture();
        $service = app(PostgresSessionIdempotency::class);
        $payload = json_encode(['user' => $user->id, 'session' => $request->session()->getId(), 'version' => $user->auth_version, 'body' => $body], JSON_THROW_ON_ERROR);
        $command = [PHP_BINARY, '-d', 'opcache.enable_cli=0', '-d', 'allow_url_fopen=0', '-d', 'disable_functions=fsockopen,pfsockopen,stream_socket_client,curl_exec,curl_multi_exec', base_path('tests/Feature/ProtectedOperations/Fixtures/session-idempotency-worker.php')];
        $worker = new Process($command, base_path(), ['APP_ENV' => 'testing']);
        $worker->setInput($payload);
        $worker->setTimeout(20);
        config(['database.connections.c02_session_observer' => config('database.connections.pgsql')]);
        $observer = DB::connection('c02_session_observer');
        DB::beginTransaction();
        try {
            self::assertSame(['value' => 'parent'], $service->execute($request, 'test.session', 'synthetic-key', 'body', fn () => $this->write($user, 'parent')));
            $worker->start();
            $deadline = microtime(true) + 10;
            do {
                if (! $worker->isRunning()) {
                    self::fail('Session worker exited before acquiring the lock (exit '.($worker->getExitCode() ?? 'unknown').').');
                }
                $blocked = (int) $observer->selectOne("select count(*) as n from pg_stat_activity where datname = current_database() and application_name = 'c02_session_idempotency_worker' and wait_event_type = 'Lock'")->n;
                if ($blocked > 0) {
                    break;
                }
                usleep(50000);
            } while (microtime(true) < $deadline);
            self::assertGreaterThan(0, $blocked, 'Competing process did not wait for the session lock.');
            $rollback ? DB::rollBack() : DB::commit();
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
        $worker->wait();
        self::assertTrue($worker->isSuccessful(), 'Session worker did not complete.');
        self::assertSame($expected, json_decode($worker->getOutput(), true, 512, JSON_THROW_ON_ERROR));
        self::assertSame('Syntheticx', DB::table('users')->where('id', $user->id)->value('name'));
        self::assertSame(1, DB::table('c02_session_receipts')->count());
        DB::purge('c02_session_observer');
    }

    private function fixture(): array
    {
        $user = User::create(['name' => 'Synthetic', 'email' => 'c02-session-'.Str::lower(Str::random(12)).'@example.test', 'password' => 'synthetic-test-password']);
        $user->refresh();
        $request = $this->requestFor($user, Str::random(40));
        app(AuthSessionRegistry::class)->issue($request, $user, $user->auth_version);

        return [$user, $request];
    }

    private function requestFor(User $user, string $sessionId): Request
    {
        $session = new Store('c02-test-session', new ArraySessionHandler(120));
        $session->setId($sessionId);
        $session->start();
        $session->put('auth.version', $user->auth_version);
        $request = Request::create('/', 'POST');
        $request->setLaravelSession($session);
        $request->setUserResolver(fn () => $user);

        return $request;
    }

    private function write(User $user, string $value): array
    {
        DB::table('users')->where('id', $user->id)->update(['name' => DB::raw("name || 'x'")]);

        return ['value' => $value];
    }
}

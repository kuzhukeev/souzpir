<?php

namespace Tests\Feature\Ai;

use App\Ai\CanonicalJson;
use App\Ai\ContactMasker;
use App\Ai\Gateway;
use App\Ai\Protected\SellerSummaryStages;
use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Seller\Conversations\Contracts\ConversationRepository;
use App\Seller\Conversations\Data\TurnClaim;
use App\Seller\Conversations\Data\TurnCommand;
use App\Seller\Conversations\Models\Conversation;
use App\Seller\Conversations\Models\Message;
use App\Shell\Auth\AuthSessionRegistry;
use App\Shell\Auth\SessionBrandSelection;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Database\Seeders\Ai\PromptSetsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use LogicException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class ProtectedSummaryStageTest extends TestCase
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

    public function test_server_owned_summary_is_bound_to_real_fake_gateway_and_replay_does_not_send_again(): void
    {
        [$tenant, $http, $claim] = $this->fixture();
        $this->within($tenant, function () use ($http, $claim): void {
            $stages = app(SellerSummaryStages::class);
            $stage = $stages->prepare($http, $claim);
            self::assertSame($stage, $stages->prepare($http, $claim));
            [$request] = $stages->load($http, $claim, $stage);
            self::assertSame('Клиент: Synthetic question', $request->messages[1]['content']);
            self::assertSame(hash_file('sha256', resource_path('views/prompts/summary.blade.php')), $request->promptChecksum);
            $stored = DB::table('c02_ai_stages')->where('id', $stage)->first();
            self::assertStringNotContainsString('Synthetic question', json_encode($stored, JSON_THROW_ON_ERROR));
            self::assertSame($claim->turnId, $stored->turn_id);
            self::assertSame(1, (int) $stored->history_revision);
            $file = $this->fakeFixture($request->toArray(), 7);
            $gateway = app(Gateway::class);
            $first = $gateway->completeProtectedSummary($http, $claim, $stage);
            self::assertSame('completed', $first);
            unlink($file);
            $repeat = $gateway->completeProtectedSummary($http, $claim, $stage);
            self::assertSame($first, $repeat);
            self::assertSame(1, DB::table('ai_calls')->count());
            try {
                $gateway->readProtectedSummaryLocked($http, $claim, $stage);
                self::fail('Protected result escaped a transaction.');
            } catch (LogicException $error) {
                self::assertSame('Protected summary result unavailable.', $error->getMessage());
            }
            $read = DB::transaction(fn () => $gateway->readProtectedSummaryLocked($http, $claim, $stage));
            self::assertSame('Synthetic summary', $read->content);
            self::assertSame($read->aiCallId, DB::table('c02_ai_stages')->where('id', $stage)->value('ai_call_id'));
            Http::assertNothingSent();
        });
    }

    public function test_wrong_tenant_claim_stage_and_revoked_session_cannot_dispatch(): void
    {
        [$tenant, $http, $claim] = $this->fixture();
        [$otherTenant, $otherHttp, $otherClaim] = $this->fixture();
        $stage = $this->within($tenant, fn () => app(SellerSummaryStages::class)->prepare($http, $claim));
        $otherStage = $this->within($otherTenant, fn () => app(SellerSummaryStages::class)->prepare($otherHttp, $otherClaim));
        $fakeClaim = new TurnClaim('claimed', $claim->turnId, $claim->conversationId, $claim->organizationId, $claim->brandId, $claim->baseVersion, str_repeat('a', 64));
        $this->within($tenant, function () use ($http, $claim, $fakeClaim, $stage, $otherStage): void {
            foreach ([[$http, $fakeClaim, $stage], [$http, $claim, $otherStage], [$http, $claim, (string) Str::ulid()]] as [$actor, $turn, $id]) {
                try {
                    app(Gateway::class)->completeProtectedSummary($actor, $turn, $id);
                    self::fail('Forged stage was accepted.');
                } catch (LogicException $error) {
                    self::assertSame('Protected summary stage could not be completed.', $error->getMessage());
                    self::assertNull($error->getPrevious());
                    $trace = json_encode($error->getTrace(), JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_THROW_ON_ERROR);
                    self::assertStringNotContainsString($turn->token(), $trace);
                    self::assertStringNotContainsString($actor->session()->getId(), $trace);
                }
            }
            app(AuthSessionRegistry::class)->withinActive($http, fn () => app(AuthSessionRegistry::class)->revokeCurrent($http));
            try {
                app(Gateway::class)->completeProtectedSummary($http, $claim, $stage);
                self::fail('Revoked session was accepted.');
            } catch (LogicException $error) {
                self::assertNull($error->getPrevious());
            }
            self::assertSame(0, DB::table('ai_calls')->count());
        });
    }

    public function test_copied_request_and_context_cannot_replay_protected_result_through_public_gateway(): void
    {
        [$tenant, $http, $claim] = $this->fixture();
        $this->within($tenant, function () use ($http, $claim): void {
            $stage = app(SellerSummaryStages::class)->prepare($http, $claim);
            [$request, $context] = app(SellerSummaryStages::class)->load($http, $claim, $stage);
            $this->fakeFixture($request->toArray(), 4);
            $gateway = app(Gateway::class);
            self::assertSame('completed', $gateway->completeProtectedSummary($http, $claim, $stage));
            self::assertSame('Synthetic summary', DB::transaction(fn () => $gateway->readProtectedSummaryLocked($http, $claim, $stage))->content);
            app(AuthSessionRegistry::class)->withinActive($http, fn () => app(AuthSessionRegistry::class)->revokeCurrent($http));
            try {
                $gateway->complete($request, $context);
                self::fail('Copied DTOs replayed a protected result without session authorization.');
            } catch (LogicException $error) {
                self::assertSame('AI request could not be safely completed.', $error->getMessage());
                self::assertNull($error->getPrevious());
            }
            try {
                DB::transaction(fn () => $gateway->readProtectedSummaryLocked($http, $claim, $stage));
                self::fail('Revoked session read a protected result.');
            } catch (LogicException $error) {
                self::assertSame('Protected summary result unavailable.', $error->getMessage());
            }
            self::assertSame(1, DB::table('ai_calls')->count());
        });
    }

    public function test_a_stage_is_not_authority_for_another_session_or_after_brand_selection_changes(): void
    {
        [$tenant, $http, $claim] = $this->fixture();
        $this->within($tenant, function () use ($tenant, $http, $claim): void {
            $stage = app(SellerSummaryStages::class)->prepare($http, $claim);
            $otherSession = new Store('c02-second-session', new ArraySessionHandler(120));
            $otherSession->setId(Str::random(40));
            $otherSession->start();
            $user = $http->user();
            $otherSession->put('auth.version', $user->auth_version);
            $otherHttp = Request::create('/', 'POST');
            $otherHttp->setLaravelSession($otherSession);
            $otherHttp->setUserResolver(fn () => $user);
            app(AuthSessionRegistry::class)->issue($otherHttp, $user, $user->auth_version);
            app(AuthSessionRegistry::class)->withinActive($otherHttp, fn () => app(SessionBrandSelection::class)->initialize($otherHttp, $tenant->organizationId, $tenant->brandId));
            try {
                app(Gateway::class)->completeProtectedSummary($otherHttp, $claim, $stage);
                self::fail('Another session used this stage.');
            } catch (LogicException $error) {
                self::assertSame('Protected summary stage could not be completed.', $error->getMessage());
            }
            app(AuthSessionRegistry::class)->withinActive($http, function () use ($http, $tenant): void {
                $selection = app(SessionBrandSelection::class)->current($http);
                app(SessionBrandSelection::class)->select($http, $tenant->organizationId, $tenant->brandId, (string) $selection->brand_revision);
            });
            try {
                app(Gateway::class)->completeProtectedSummary($http, $claim, $stage);
                self::fail('A stage survived a changed session selection revision.');
            } catch (LogicException $error) {
                self::assertSame('Protected summary stage could not be completed.', $error->getMessage());
            }
            self::assertSame(0, DB::table('ai_calls')->count());
        });
    }

    public function test_unknown_cost_is_not_retried_and_cannot_be_read_as_completed(): void
    {
        [$tenant, $http, $claim] = $this->fixture();
        $this->within($tenant, function () use ($http, $claim): void {
            $stage = app(SellerSummaryStages::class)->prepare($http, $claim);
            [$request] = app(SellerSummaryStages::class)->load($http, $claim, $stage);
            $file = $this->fakeFixture($request->toArray(), null);
            $gateway = app(Gateway::class);
            $first = $gateway->completeProtectedSummary($http, $claim, $stage);
            self::assertSame('uncertain', $first);
            unlink($file);
            $again = $gateway->completeProtectedSummary($http, $claim, $stage);
            self::assertSame('uncertain', $again);
            self::assertSame(1, DB::table('ai_calls')->count());
            try {
                DB::transaction(fn () => $gateway->readProtectedSummaryLocked($http, $claim, $stage));
                self::fail('Uncertain result was accepted.');
            } catch (LogicException $error) {
                self::assertSame('Protected summary result unavailable.', $error->getMessage());
            }
        });
    }

    public function test_a_preexisting_unprotected_call_cannot_be_bound_after_it_was_sent(): void
    {
        [$tenant, $http, $claim] = $this->fixture();
        $this->within($tenant, function () use ($http, $claim): void {
            $stage = app(SellerSummaryStages::class)->prepare($http, $claim);
            [$request, $context] = app(SellerSummaryStages::class)->load($http, $claim, $stage);
            $this->fakeFixture($request->toArray(), 4);
            $gateway = app(Gateway::class);
            $unprotected = $gateway->complete($request, $context);
            self::assertNotNull($unprotected->aiCallId);
            $nonceAfterCommit = DB::selectOne("select current_setting('c02.reservation_nonce', true) as nonce")->nonce;
            self::assertContains($nonceAfterCommit, [null, '']);
            try {
                $gateway->completeProtectedSummary($http, $claim, $stage);
                self::fail('A preexisting call became protected evidence.');
            } catch (LogicException $error) {
                self::assertSame('Protected summary stage could not be completed.', $error->getMessage());
            }
            self::assertNull(DB::table('c02_ai_stages')->where('id', $stage)->value('ai_call_id'));
            self::assertSame(1, DB::table('ai_calls')->count());
            try {
                DB::table('ai_calls')->where('id', $unprotected->aiCallId)->update(['c02_creation_xid' => DB::raw('pg_current_xact_id()')]);
                self::fail('The call creation transaction was changed.');
            } catch (QueryException $error) {
                self::assertSame('23514', $error->errorInfo[0]);
            }
        });
    }

    public function test_real_restore_with_reused_xid_cannot_bind_a_previous_call(): void
    {
        $restorePort = getenv('C02_RESTORE_PORT');
        $restoreDb = getenv('C02_RESTORE_DATABASE');
        $pgBin = getenv('C02_PG_BIN');
        if (! $restorePort || ! $restoreDb || ! $pgBin) {
            self::markTestSkipped('Set a dedicated empty restore database and PostgreSQL bin to run the restore test.');
        }
        if (! preg_match('/\A[0-9]{1,5}\z/D', $restorePort) || ! preg_match('/\Asouzpir_test_sp_c02_restore[a-z0-9_]*\z/D', $restoreDb)
            || $restoreDb === DB::connection()->getDatabaseName() || config('database.connections.pgsql.host') !== '127.0.0.1') {
            throw new LogicException('A separate isolated restore target is required.');
        }
        $source = config('database.connections.pgsql');
        $restore = [...$source, 'host' => '127.0.0.1', 'port' => (int) $restorePort, 'database' => $restoreDb];
        config(['database.connections.c02_restore' => $restore]);
        $target = DB::connection('c02_restore');
        $targetInfo = $target->selectOne("select current_database() as db, current_setting('data_directory') as data_dir, (select count(*) from pg_class c join pg_namespace n on n.oid=c.relnamespace where n.nspname='public' and c.relkind in ('r','p','v','m','S')) as relations");
        $localRoot = realpath(base_path('.local'));
        $dataDir = realpath($targetInfo->data_dir);
        $sourceDir = realpath(DB::selectOne("select current_setting('data_directory') as data_dir")->data_dir);
        if ($targetInfo->db !== $restoreDb || (int) $targetInfo->relations !== 0 || ! is_string($localRoot) || ! is_string($dataDir)
            || ! is_string($sourceDir) || $sourceDir === $dataDir
            || ! str_starts_with(strtolower($dataDir), strtolower($localRoot).DIRECTORY_SEPARATOR)
            || ! str_starts_with(strtolower($sourceDir), strtolower($localRoot).DIRECTORY_SEPARATOR)) {
            throw new LogicException('Restore source and empty target must be separate clusters inside this worktree.');
        }

        // Reserve enough space to make exact XID reuse reproducible even when
        // this isolated target cluster has already run another restore test.
        $targetXid = (int) $target->selectOne('select pg_current_xact_id()::text as xid')->xid;
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE PROCEDURE c02_test_advance_source(IN last_xid bigint) LANGUAGE plpgsql AS $$
DECLARE value bigint;
BEGIN
  LOOP
    value := pg_current_xact_id()::text::bigint;
    IF value >= last_xid THEN EXIT; END IF;
    COMMIT;
  END LOOP;
END;
$$;
SQL);
        DB::statement('CALL c02_test_advance_source(?)', [$targetXid + 1500]);

        [$tenant, $http, $claim] = $this->fixture();
        [$stage, $call] = $this->within($tenant, function () use ($http, $claim): array {
            $stage = app(SellerSummaryStages::class)->prepare($http, $claim);
            [$request, $context] = app(SellerSummaryStages::class)->load($http, $claim, $stage);
            $this->fakeFixture($request->toArray(), 4);
            $result = app(Gateway::class)->complete($request, $context);

            return [$stage, DB::table('ai_calls')->where('id', $result->aiCallId)->first()];
        });
        self::assertNotNull($call->c02_reservation_digest);
        $oldXid = (int) $call->c02_creation_xid;
        $dumpFile = base_path('.local/tmp/c02-restore-'.Str::random(12).'.dump');
        $suffix = PHP_OS_FAMILY === 'Windows' ? '.exe' : '';
        $bin = rtrim($pgBin, '/\\').DIRECTORY_SEPARATOR;
        $sourcePort = (string) config('database.connections.pgsql.port');
        $sourceDb = DB::connection()->getDatabaseName();
        $username = config('database.connections.pgsql.username');
        try {
            $dump = new Process([$bin.'pg_dump'.$suffix, '-h', '127.0.0.1', '-p', $sourcePort, '-U', $username, '-Fc', '--no-owner', '--no-acl', '-f', $dumpFile, $sourceDb], base_path());
            $dump->mustRun();
            $restoreProcess = new Process([$bin.'pg_restore'.$suffix, '-h', '127.0.0.1', '-p', $restorePort, '-U', $username, '--no-owner', '--no-acl', '-d', $restoreDb, $dumpFile], base_path());
            $restoreProcess->mustRun();
            $copied = $target->table('ai_calls')->where('id', $call->id)->first();
            self::assertSame((string) $oldXid, (string) $copied->c02_creation_xid);
            self::assertSame($call->c02_reservation_digest, $copied->c02_reservation_digest);
            $forgedNonce = str_repeat('a', 64);
            self::assertNotSame(hash('sha256', 'c02-reservation:'.$forgedNonce), $copied->c02_reservation_digest);
            $target->select("select set_config('c02.reservation_nonce', ?, false)", [$forgedNonce]);
            $target->unprepared(<<<'SQL'
CREATE PROCEDURE c02_test_advance_xid(IN last_xid bigint) LANGUAGE plpgsql AS $$
DECLARE value bigint;
BEGIN
  LOOP
    value := pg_current_xact_id()::text::bigint;
    IF value >= last_xid THEN EXIT; END IF;
    COMMIT;
  END LOOP;
END;
$$;
SQL);
            $next = (int) $target->selectOne('select pg_current_xact_id()::text as xid')->xid;
            self::assertLessThan($oldXid - 1, $next, 'Restore target must have an older XID sequence.');
            $target->statement('CALL c02_test_advance_xid(?)', [$oldXid - 1]);
            $original = DB::getDefaultConnection();
            DB::setDefaultConnection('c02_restore');
            try {
                $this->within($tenant, function () use ($http, $claim, $stage, $call, $oldXid): void {
                    try {
                        DB::transaction(function () use ($http, $claim, $stage, $call, $oldXid): void {
                            self::assertSame($oldXid, (int) DB::selectOne('select pg_current_xact_id()::text as xid')->xid);
                            $restoredCall = DB::table('ai_calls')->where('id', $call->id)->first();
                            $proof = DB::selectOne("select c02_creation_xid = pg_current_xact_id() as xid_matches, c02_reservation_digest = encode(sha256(convert_to('c02-reservation:' || current_setting('c02.reservation_nonce', true), 'UTF8')), 'hex') as nonce_matches from ai_calls where id = ?", [$call->id]);
                            self::assertTrue($proof->xid_matches);
                            self::assertFalse($proof->nonce_matches);
                            app(SellerSummaryStages::class)->bindCall($http, $claim, $stage, $restoredCall, $restoredCall->request_hash);
                        });
                        self::fail('A restored call was attached after XID reuse.');
                    } catch (QueryException $error) {
                        self::assertSame('23514', $error->errorInfo[0]);
                    }
                });
                self::assertNull(DB::table('c02_ai_stages')->where('id', $stage)->value('ai_call_id'));
            } finally {
                DB::setDefaultConnection($original);
            }
        } finally {
            if (is_file($dumpFile)) {
                unlink($dumpFile);
            }
            DB::purge('c02_restore');
        }
    }

    public function test_the_incomplete_port_refuses_live_drivers_before_reservation(): void
    {
        [$tenant, $http, $claim] = $this->fixture();
        $this->within($tenant, function () use ($http, $claim): void {
            $stage = app(SellerSummaryStages::class)->prepare($http, $claim);
            config(['runtime.ai_driver' => 'openrouter']);
            try {
                app(Gateway::class)->completeProtectedSummary($http, $claim, $stage);
                self::fail('Incomplete port allowed a live driver.');
            } catch (LogicException $error) {
                self::assertSame('Protected summary stage could not be completed.', $error->getMessage());
            }
            self::assertSame(0, DB::table('ai_calls')->count());
            Http::assertNothingSent();
        });
    }

    public function test_swapped_encrypted_result_and_stage_request_are_rejected(): void
    {
        [$tenant, $http, $claim] = $this->fixture();
        [$otherTenant, $otherHttp, $otherClaim] = $this->fixture();
        [$firstStage, $firstCall] = $this->within($tenant, function () use ($http, $claim): array {
            $stage = app(SellerSummaryStages::class)->prepare($http, $claim);
            [$request] = app(SellerSummaryStages::class)->load($http, $claim, $stage);
            $this->fakeFixture($request->toArray(), 2);
            app(Gateway::class)->completeProtectedSummary($http, $claim, $stage);

            return [$stage, DB::table('c02_ai_stages')->where('id', $stage)->value('ai_call_id')];
        });
        $otherCall = $this->within($otherTenant, function () use ($otherHttp, $otherClaim): string {
            $stage = app(SellerSummaryStages::class)->prepare($otherHttp, $otherClaim);
            [$request] = app(SellerSummaryStages::class)->load($otherHttp, $otherClaim, $stage);
            $this->fakeFixture($request->toArray(), 2);

            app(Gateway::class)->completeProtectedSummary($otherHttp, $otherClaim, $stage);

            return DB::table('c02_ai_stages')->where('id', $stage)->value('ai_call_id');
        });
        $ciphertext = DB::table('ai_calls')->where('id', $otherCall)->value('result_encrypted');
        DB::table('ai_calls')->where('id', $firstCall)->update(['result_encrypted' => $ciphertext]);
        $this->within($tenant, function () use ($http, $claim, $firstStage): void {
            try {
                DB::transaction(fn () => app(Gateway::class)->readProtectedSummaryLocked($http, $claim, $firstStage));
                self::fail('Ciphertext from a different call was accepted.');
            } catch (LogicException $error) {
                self::assertSame('Protected summary result unavailable.', $error->getMessage());
                self::assertNull($error->getPrevious());
            }
        });
        try {
            DB::table('c02_ai_stages')->where('id', $firstStage)->update(['request_digest' => str_repeat('a', 64)]);
            self::fail('Stage request was rewritten.');
        } catch (QueryException $error) {
            self::assertSame('23514', $error->errorInfo[0]);
        }
    }

    public function test_two_processes_racing_the_same_stage_reserve_only_one_call(): void
    {
        [$tenant, $http, $claim] = $this->fixture();
        $stage = $this->within($tenant, fn () => app(SellerSummaryStages::class)->prepare($http, $claim));
        [$request] = $this->within($tenant, fn () => app(SellerSummaryStages::class)->load($http, $claim, $stage));
        $file = $this->fakeFixture($request->toArray(), 5);
        $payload = json_encode([
            'org' => $tenant->organizationId, 'brand' => $tenant->brandId, 'user' => $tenant->actorId,
            'member' => $tenant->membershipId, 'session' => $http->session()->getId(),
            'turn' => $claim->turnId, 'conversation' => $claim->conversationId, 'version' => $claim->baseVersion,
            'token' => $claim->token(), 'stage' => $stage, 'fixtures' => dirname($file),
        ], JSON_THROW_ON_ERROR);
        $command = [PHP_BINARY, '-d', 'allow_url_fopen=0', '-d', 'disable_functions=fsockopen,pfsockopen,stream_socket_client,curl_exec,curl_multi_exec', base_path('tests/Feature/Ai/Fixtures/protected-summary-worker.php')];
        $first = new Process($command, base_path(), ['APP_ENV' => 'testing']);
        $second = new Process($command, base_path(), ['APP_ENV' => 'testing']);
        $first->setInput($payload);
        $second->setInput($payload);
        config(['database.connections.c02_advisory' => config('database.connections.pgsql')]);
        $blocker = DB::connection('c02_advisory');
        $blocker->beginTransaction();
        $keyHash = hash_hmac('sha256', 'c02-summary-'.$stage, (string) config('app.key'));
        $blocker->select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$tenant->organizationId.':'.$tenant->brandId.':'.$keyHash]);
        try {
            $first->start();
            $this->waitForBlockedProcesses(1);
            config(['database.connections.c02_revoker' => config('database.connections.pgsql')]);
            $revoker = DB::connection('c02_revoker');
            $revoker->beginTransaction();
            try {
                $revoker->statement("SET LOCAL lock_timeout = '250ms'");
                $sessionHash = hash_hmac('sha256', 'auth-session:'.$http->session()->getId(), (string) config('app.key'));
                $revoker->table('auth_session_registry')->where('session_hash', $sessionHash)->update(['revoked_at' => now()]);
                self::fail('Session revocation crossed the protected reservation lock.');
            } catch (QueryException $error) {
                self::assertSame('55P03', $error->errorInfo[0]);
            } finally {
                $revoker->rollBack();
                DB::purge('c02_revoker');
            }
            $second->start();
            $this->waitForBlockedProcesses(2);
        } finally {
            $blocker->rollBack();
        }
        $first->wait();
        $second->wait();
        self::assertTrue($first->isSuccessful(), $first->getErrorOutput());
        self::assertTrue($second->isSuccessful(), $second->getErrorOutput());
        $a = json_decode($first->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        $b = json_decode($second->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        self::assertContains($a['status'], ['completed', 'in_progress']);
        self::assertContains($b['status'], ['completed', 'in_progress']);
        self::assertTrue($a['status'] === 'completed' || $b['status'] === 'completed');
        self::assertSame(1, DB::table('ai_calls')->count());
        self::assertSame(DB::table('ai_calls')->value('id'), DB::table('c02_ai_stages')->where('id', $stage)->value('ai_call_id'));
        DB::purge('c02_advisory');
    }

    public function test_new_input_while_fake_driver_finishes_hides_result_and_only_returns_safe_status(): void
    {
        [$tenant, $http, $claim] = $this->fixture();
        $stage = $this->within($tenant, fn () => app(SellerSummaryStages::class)->prepare($http, $claim));
        [$request] = $this->within($tenant, fn () => app(SellerSummaryStages::class)->load($http, $claim, $stage));
        $file = $this->fakeFixture($request->toArray(), 5);
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION c02_test_delay_result() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF NEW.result_encrypted IS NOT NULL AND OLD.result_encrypted IS NULL THEN
    PERFORM pg_advisory_xact_lock(8620249);
  END IF;
  RETURN NEW;
END;
$$;
CREATE TRIGGER c02_test_delay_result BEFORE UPDATE ON ai_calls FOR EACH ROW EXECUTE FUNCTION c02_test_delay_result();
SQL);
        config(['database.connections.c02_advisory' => config('database.connections.pgsql')]);
        $blocker = DB::connection('c02_advisory');
        $blocker->beginTransaction();
        $blocker->select('SELECT pg_advisory_xact_lock(8620249)');
        $payload = json_encode([
            'org' => $tenant->organizationId, 'brand' => $tenant->brandId, 'user' => $tenant->actorId,
            'member' => $tenant->membershipId, 'session' => $http->session()->getId(),
            'turn' => $claim->turnId, 'conversation' => $claim->conversationId, 'version' => $claim->baseVersion,
            'token' => $claim->token(), 'stage' => $stage, 'fixtures' => dirname($file),
        ], JSON_THROW_ON_ERROR);
        $command = [PHP_BINARY, '-d', 'allow_url_fopen=0', '-d', 'disable_functions=fsockopen,pfsockopen,stream_socket_client,curl_exec,curl_multi_exec', base_path('tests/Feature/Ai/Fixtures/protected-summary-worker.php')];
        $worker = new Process($command, base_path(), ['APP_ENV' => 'testing']);
        $worker->setInput($payload);
        try {
            $worker->start();
            $this->waitForBlockedProcesses(1);
            $this->within($tenant, fn () => Message::create(['conversation_id' => $claim->conversationId, 'role' => 'client', 'text' => 'Input during fake driver', 'meta' => []]));
        } finally {
            $blocker->rollBack();
        }
        $worker->wait();
        self::assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
        self::assertSame(['status' => 'completed'], json_decode($worker->getOutput(), true, 512, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('Synthetic summary', $worker->getOutput());
        $this->within($tenant, function () use ($http, $claim, $stage): void {
            try {
                DB::transaction(fn () => app(Gateway::class)->readProtectedSummaryLocked($http, $claim, $stage));
                self::fail('A stale driver result was exposed.');
            } catch (LogicException $error) {
                self::assertSame('Protected summary result unavailable.', $error->getMessage());
            }
        });
        self::assertSame(2, DB::table('messages')->where('conversation_id', $claim->conversationId)->count());
        self::assertSame('completed', DB::table('ai_calls')->value('status'));
        DB::purge('c02_advisory');
    }

    public function test_interrupted_result_persistence_never_sends_the_stage_again(): void
    {
        [$tenant, $http, $claim] = $this->fixture();
        $stage = $this->within($tenant, fn () => app(SellerSummaryStages::class)->prepare($http, $claim));
        [$request] = $this->within($tenant, fn () => app(SellerSummaryStages::class)->load($http, $claim, $stage));
        $file = $this->fakeFixture($request->toArray(), 5);
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION c02_test_hold_result() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF NEW.result_encrypted IS NOT NULL AND OLD.result_encrypted IS NULL THEN
    PERFORM pg_advisory_xact_lock(8620248);
  END IF;
  RETURN NEW;
END;
$$;
CREATE TRIGGER c02_test_hold_result BEFORE UPDATE ON ai_calls FOR EACH ROW EXECUTE FUNCTION c02_test_hold_result();
SQL);
        config(['database.connections.c02_advisory' => config('database.connections.pgsql')]);
        $blocker = DB::connection('c02_advisory');
        $blocker->beginTransaction();
        $blocker->select('SELECT pg_advisory_xact_lock(8620248)');
        $payload = json_encode([
            'org' => $tenant->organizationId, 'brand' => $tenant->brandId, 'user' => $tenant->actorId,
            'member' => $tenant->membershipId, 'session' => $http->session()->getId(),
            'turn' => $claim->turnId, 'conversation' => $claim->conversationId, 'version' => $claim->baseVersion,
            'token' => $claim->token(), 'stage' => $stage, 'fixtures' => dirname($file),
        ], JSON_THROW_ON_ERROR);
        $command = [PHP_BINARY, '-d', 'allow_url_fopen=0', '-d', 'disable_functions=fsockopen,pfsockopen,stream_socket_client,curl_exec,curl_multi_exec', base_path('tests/Feature/Ai/Fixtures/protected-summary-worker.php')];
        $worker = new Process($command, base_path(), ['APP_ENV' => 'testing']);
        $worker->setInput($payload);
        try {
            $worker->start();
            $this->waitForBlockedProcesses(1);
            self::assertSame('reserved', DB::table('ai_calls')->value('status'));
            self::assertNotNull(DB::table('c02_ai_stages')->where('id', $stage)->value('ai_call_id'));
            $cancelled = DB::selectOne("select pg_cancel_backend(pid) as cancelled from pg_stat_activity where datname = current_database() and wait_event = 'advisory' and query like 'update %ai_calls%' limit 1");
            self::assertTrue($cancelled->cancelled);
            $worker->wait();
            self::assertFalse($worker->isSuccessful());
        } finally {
            $blocker->rollBack();
        }
        unlink($file);
        $result = $this->within($tenant, fn () => app(Gateway::class)->completeProtectedSummary($http, $claim, $stage));
        self::assertSame('uncertain', $result);
        self::assertSame(1, DB::table('ai_calls')->count());
        self::assertSame('uncertain', DB::table('ai_calls')->value('status'));
        DB::purge('c02_advisory');
    }

    private function waitForBlockedProcesses(int $count): void
    {
        $deadline = microtime(true) + 10;
        do {
            $waiting = DB::selectOne("select count(*) as n from pg_stat_activity where datname = current_database() and wait_event_type = 'Lock'")->n;
            if ((int) $waiting >= $count) {
                return;
            }
            usleep(50000);
        } while (microtime(true) < $deadline);
        self::fail('Expected competing workers to wait on PostgreSQL locks.');
    }

    public function test_stale_history_or_changed_gateway_request_cannot_be_consumed(): void
    {
        [$tenant, $http, $claim] = $this->fixture();
        $this->within($tenant, function () use ($http, $claim): void {
            $stage = app(SellerSummaryStages::class)->prepare($http, $claim);
            [$request] = app(SellerSummaryStages::class)->load($http, $claim, $stage);
            $this->fakeFixture($request->toArray(), 3);
            $gateway = app(Gateway::class);
            $gateway->completeProtectedSummary($http, $claim, $stage);
            $callId = DB::table('c02_ai_stages')->where('id', $stage)->value('ai_call_id');
            DB::table('ai_calls')->where('id', $callId)->update(['request_hash' => str_repeat('a', 64)]);
            try {
                DB::transaction(fn () => $gateway->readProtectedSummaryLocked($http, $claim, $stage));
                self::fail('Altered Gateway request accepted.');
            } catch (LogicException $error) {
                self::assertSame('Protected summary result unavailable.', $error->getMessage());
            }
            Message::create(['conversation_id' => $claim->conversationId, 'role' => 'client', 'text' => 'Later synthetic message', 'meta' => []]);
            try {
                $gateway->completeProtectedSummary($http, $claim, $stage);
                self::fail('Stale stage accepted.');
            } catch (LogicException $error) {
                self::assertSame('Protected summary stage could not be completed.', $error->getMessage());
            }
            self::assertSame(2, DB::table('messages')->where('conversation_id', $claim->conversationId)->count());
            self::assertSame(1, DB::table('ai_calls')->count());
        });
    }

    public function test_a_new_input_before_reservation_prevents_any_gateway_call(): void
    {
        [$tenant, $http, $claim] = $this->fixture();
        $this->within($tenant, function () use ($http, $claim): void {
            $stage = app(SellerSummaryStages::class)->prepare($http, $claim);
            Message::create(['conversation_id' => $claim->conversationId, 'role' => 'client', 'text' => 'Synthetic later input', 'meta' => []]);
            try {
                app(Gateway::class)->completeProtectedSummary($http, $claim, $stage);
                self::fail('The stale stage dispatched after new input.');
            } catch (LogicException $error) {
                self::assertSame('Protected summary stage could not be completed.', $error->getMessage());
            }
            self::assertSame(0, DB::table('ai_calls')->count());
            self::assertSame(2, DB::table('messages')->where('conversation_id', $claim->conversationId)->count());
            self::assertNull(DB::table('c02_ai_stages')->where('id', $stage)->value('ai_call_id'));
        });
    }

    private function fixture(): array
    {
        Http::fake();
        config(['runtime.ai_driver' => 'fake']);
        app(PromptSetsSeeder::class)->run();
        $user = User::create(['name' => 'Synthetic', 'email' => 'c02-'.Str::lower(Str::random(12)).'@example.test', 'password' => 'synthetic-test-password']);
        $user->refresh();
        $org = Organization::create(['name' => 'Synthetic', 'owner_user_id' => $user->id]);
        [$member, $brand] = $this->within(new TenantContext($org->id, actorId: $user->id), fn () => [
            Membership::create(['user_id' => $user->id, 'role' => 'owner']), Brand::create(['name' => 'Synthetic brand', 'timezone' => 'Europe/Moscow']),
        ]);
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $tenant = new TenantContext($org->id, $brand->id, $user->id, $member->id);
        $session = new Store('c02-test-session', new ArraySessionHandler(120));
        $session->setId(Str::random(40));
        $session->start();
        $session->put('auth.version', $user->auth_version);
        $http = Request::create('/', 'POST');
        $http->setLaravelSession($session);
        $http->setUserResolver(fn () => $user);
        app(AuthSessionRegistry::class)->issue($http, $user, $user->auth_version);
        app(AuthSessionRegistry::class)->withinActive($http, fn () => app(SessionBrandSelection::class)->initialize($http, $org->id, $brand->id));
        [$conversation, $input] = $this->within($tenant, function (): array {
            $conversation = Conversation::create(['state' => []]);
            $input = Message::create(['conversation_id' => $conversation->id, 'role' => 'client', 'text' => 'Synthetic question', 'meta' => []]);

            return [$conversation, $input];
        });
        $claim = app(ConversationRepository::class)->claimTurn($tenant, new TurnCommand($conversation->id, $input->id, $input->text, null, 'c02-stage-'.Str::random(16), 0));

        return [$tenant, $http, $claim];
    }

    private function fakeFixture(array $request, ?int $cost): string
    {
        $directory = storage_path('app/fixtures-'.Str::random(12));
        mkdir($directory, 0700, true);
        config(['features.E0-05.defaults.fixture_directory' => $directory]);
        $body = app(ContactMasker::class)->request($request);
        $file = $directory.DIRECTORY_SEPARATOR.CanonicalJson::hash($body).'.json';
        file_put_contents($file, json_encode(['result' => ['content' => 'Synthetic summary', 'toolCalls' => [], 'usage' => ['promptTokens' => 1, 'completionTokens' => 1], 'model' => $request['model'], 'latencyMs' => 1, 'costMicros' => $cost]], JSON_THROW_ON_ERROR));

        return $file;
    }

    private function within(TenantContext $tenant, \Closure $action): mixed
    {
        return app(TenantContextRunner::class)->run($tenant, $action);
    }
}

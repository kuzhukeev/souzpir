<?php

namespace Tests\Feature\Ai;

use App\Ai\CanonicalJson;
use App\Ai\ContactMasker;
use App\Ai\Gateway;
use App\Ai\Protected\SellerExtractorStages;
use App\Contracts\Ai\AiContext;
use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\ProtectedOperations\FirstTurnProtectedClaim;
use App\Seller\Conversations\Contracts\ConversationRepository;
use App\Seller\Conversations\Data\TurnClaim;
use App\Seller\Conversations\Data\TurnCommand;
use App\Seller\Conversations\Models\Conversation;
use App\Seller\Conversations\Models\Message;
use App\Seller\Knowledge\Import\YamlKnowledgeImporter;
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
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use LogicException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class ProtectedExtractorStageTest extends TestCase
{
    use DatabaseMigrations;

    public function createApplication()
    {
        $app = parent::createApplication();
        $expected = env('DB_TEST_DATABASE');
        $db = DB::selectOne('select current_database() as name, host(inet_server_addr()) as host, rolsuper, rolcreatedb, rolcreaterole from pg_roles where rolname = current_user');
        if (! $app->environment('testing') || ! is_string($expected) || ! str_contains($expected, '_test_')
            || config('database.connections.pgsql.database') !== $expected || $db->name !== $expected || env('APP_PREVIEW_DATABASE') === $expected
            || (! str_starts_with((string) $db->host, '127.') && $db->host !== '::1') || $db->rolsuper || $db->rolcreatedb || $db->rolcreaterole) {
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

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();
        Mail::fake();
        Queue::fake();
        config(['runtime.ai_driver' => 'fake']);
    }

    public function test_saved_turn_builds_real_request_and_only_bound_gateway_result_can_be_read(): void
    {
        [$tenant, $http, $claim] = $this->fixture('Synthetic question user@example.test');
        $this->within($tenant, function () use ($http, $claim): void {
            $stages = app(SellerExtractorStages::class);
            $stage = $stages->prepare($http, $claim);
            self::assertSame($stage, $stages->prepare($http, $claim));
            [$request, $context] = $stages->load($http, $claim, $stage);
            self::assertCount(2, $request->messages);
            self::assertStringContainsString('Менеджера зовут Антон', $request->messages[0]['content']);
            self::assertStringContainsString('Synthetic question user@example.test', $request->messages[array_key_last($request->messages)]['content']);
            self::assertSame('google/gemini-2.5-flash', $request->model);
            self::assertSame('extractor', $context->purpose);
            $row = DB::table('c02_extractor_stages')->where('id', $stage)->first();
            self::assertSame($claim->turnId, $row->turn_id);
            self::assertStringNotContainsString('user@example.test', json_encode($row, JSON_THROW_ON_ERROR));
            $file = $this->fakeFixture($request->toArray(), 0);
            $gateway = app(Gateway::class);
            self::assertSame('completed', $gateway->completeProtectedExtractor($http, $claim, $stage));
            unlink($file);
            self::assertSame('completed', $gateway->completeProtectedExtractor($http, $claim, $stage));
            self::assertSame(1, DB::table('ai_calls')->where('purpose', 'extractor')->count());
            self::assertSame(0, DB::table('ai_calls')->value('cost_micros'));
            self::assertSame(0, DB::table('ai_budgets')->value('unresolved_count'));
            try {
                $gateway->readProtectedExtractorLocked($http, $claim, $stage);
                self::fail('Protected data must remain within the locked transaction.');
            } catch (LogicException $error) {
                self::assertSame('Protected extractor result unavailable.', $error->getMessage());
            }
            $result = DB::transaction(fn () => $gateway->readProtectedExtractorLocked($http, $claim, $stage));
            self::assertSame('answer_only', $result['client_intent']);
            self::assertSame('user@example.test', $result['slot_updates']['email']);
            try {
                $gateway->complete($request, $context);
                self::fail('Copied public DTOs must not replay the protected call.');
            } catch (LogicException $error) {
                self::assertSame('AI request could not be safely completed.', $error->getMessage());
            }
            $callId = DB::table('c02_extractor_stages')->where('id', $stage)->value('ai_call_id');
            DB::table('ai_calls')->where('id', $callId)->update(['cost_micros' => 1]);
            try {
                DB::transaction(fn () => $gateway->readProtectedExtractorLocked($http, $claim, $stage));
                self::fail('Inconsistent accounting was accepted.');
            } catch (LogicException $error) {
                self::assertSame('Protected extractor result unavailable.', $error->getMessage());
            }
            self::assertSame(1, DB::table('ai_calls')->count());
            self::assertSame(0, DB::table('seller_tasks')->count());
            self::assertSame(0, DB::table('outbox_events')->count());
            Http::assertNothingSent();
            Mail::assertNothingOutgoing();
            Queue::assertNothingPushed();
        });
    }

    public function test_forged_claim_stage_session_and_stale_input_do_not_dispatch(): void
    {
        [$tenant, $http, $claim] = $this->fixture();
        $this->within($tenant, function () use ($tenant, $http, $claim): void {
            $stage = app(SellerExtractorStages::class)->prepare($http, $claim);
            $forged = new TurnClaim('claimed', $claim->turnId, $claim->conversationId, $claim->organizationId, $claim->brandId, $claim->baseVersion, str_repeat('a', 64));
            foreach ([[$forged, $stage], [$claim, (string) Str::ulid()]] as [$turn, $id]) {
                try {
                    app(Gateway::class)->completeProtectedExtractor($http, $turn, $id);
                    self::fail('Forged reference was accepted.');
                } catch (LogicException $error) {
                    self::assertSame('Protected extractor stage could not be completed.', $error->getMessage());
                    self::assertNull($error->getPrevious());
                    $trace = json_encode($error->getTrace(), JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_THROW_ON_ERROR);
                    self::assertStringNotContainsString($turn->token(), $trace);
                    self::assertStringNotContainsString($http->session()->getId(), $trace);
                    self::assertStringNotContainsString('Synthetic question', $trace);
                }
            }
            app(AuthSessionRegistry::class)->withinActive($http, function () use ($http, $tenant): void {
                $selection = app(SessionBrandSelection::class)->current($http);
                app(SessionBrandSelection::class)->select($http, $tenant->organizationId, $tenant->brandId, (string) $selection->brand_revision);
            });
            try {
                app(Gateway::class)->completeProtectedExtractor($http, $claim, $stage);
                self::fail('Changed selection was accepted.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
            Message::create(['conversation_id' => $claim->conversationId, 'role' => 'client', 'text' => 'Later synthetic input', 'meta' => []]);
            try {
                app(SellerExtractorStages::class)->prepare($http, $claim);
                self::fail('Stale turn was accepted.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
            self::assertSame('uncertain', DB::table('seller_turns')->where('id', $claim->turnId)->value('status'));
            self::assertSame(2, DB::table('messages')->where('conversation_id', $claim->conversationId)->count());
            self::assertSame(0, DB::table('ai_calls')->count());
            Http::assertNothingSent();
        });
    }

    public function test_public_and_forged_claims_cannot_prepare_or_dispatch_extractor(): void
    {
        [$tenant, $http, $ordinary] = $this->fixture('Public first input', [], true);
        $this->within($tenant, function () use ($http, $ordinary): void {
            $forged = new TurnClaim('claimed', $ordinary->turnId, $ordinary->conversationId, $ordinary->organizationId, $ordinary->brandId, 0, str_repeat('a', 64));
            foreach ([$ordinary, $forged] as $claim) {
                try {
                    app(SellerExtractorStages::class)->prepare($http, $claim);
                    self::fail('An unprotected claim prepared the extractor.');
                } catch (LogicException $error) {
                    self::assertSame('Protected extractor stage rejected.', $error->getMessage());
                    self::assertNull($error->getPrevious());
                    $trace = json_encode($error->getTrace(), JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_THROW_ON_ERROR);
                    self::assertStringNotContainsString($claim->token(), $trace);
                    self::assertStringNotContainsString($http->session()->getId(), $trace);
                }
                try {
                    app(Gateway::class)->completeProtectedExtractor($http, $claim, (string) Str::ulid());
                    self::fail('An unprotected claim dispatched the extractor.');
                } catch (LogicException) {
                    self::assertTrue(true);
                }
            }
            self::assertSame(0, DB::table('c02_extractor_stages')->count());
            self::assertSame(0, DB::table('ai_calls')->count());
            self::assertSame(0, DB::table('ai_budgets')->count());
        });
        Http::assertNothingSent();
        Mail::assertNothingOutgoing();
        Queue::assertNothingPushed();
    }

    public function test_non_fake_driver_cannot_prepare_first_extractor_stage(): void
    {
        [$tenant, $http, $claim] = $this->fixture();
        $this->within($tenant, function () use ($http, $claim): void {
            config(['runtime.ai_driver' => 'openrouter']);
            try {
                app(SellerExtractorStages::class)->prepare($http, $claim);
                self::fail('The protected extractor stage was prepared for a live driver.');
            } catch (LogicException) {
                self::assertTrue(true);
            } finally {
                config(['runtime.ai_driver' => 'fake']);
            }
            self::assertSame(0, DB::table('c02_extractor_stages')->count());
            self::assertSame(0, DB::table('ai_calls')->count());
        });
        Http::assertNothingSent();
    }

    public function test_revoked_brand_or_session_and_stale_input_cannot_prepare_first_stage(): void
    {
        [$tenant, $http, $claim] = $this->fixture();
        $this->within($tenant, function () use ($tenant, $http, $claim): void {
            DB::table('brands')->where('id', $tenant->brandId)->update(['status' => 'archived']);
            try {
                app(SellerExtractorStages::class)->prepare($http, $claim);
                self::fail('An archived brand prepared an extractor stage.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
            DB::table('brands')->where('id', $tenant->brandId)->update(['status' => 'active']);
            Message::create(['conversation_id' => $claim->conversationId, 'role' => 'client', 'text' => 'Later synthetic input', 'meta' => []]);
            try {
                app(SellerExtractorStages::class)->prepare($http, $claim);
                self::fail('A stale claim prepared an extractor stage.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
            self::assertSame('uncertain', DB::table('seller_turns')->where('id', $claim->turnId)->value('status'));
        });
        [$otherTenant, $otherHttp, $otherClaim] = $this->fixture('Other input');
        $this->within($otherTenant, function () use ($otherHttp, $otherClaim): void {
            app(AuthSessionRegistry::class)->withinActive($otherHttp, fn () => app(AuthSessionRegistry::class)->revokeCurrent($otherHttp));
            try {
                app(SellerExtractorStages::class)->prepare($otherHttp, $otherClaim);
                self::fail('A revoked session prepared an extractor stage.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
        });
        self::assertSame(0, DB::table('c02_extractor_stages')->count());
        self::assertSame(0, DB::table('ai_calls')->count());
        self::assertSame(0, DB::table('ai_budgets')->count());
        Http::assertNothingSent();
        Mail::assertNothingOutgoing();
        Queue::assertNothingPushed();
    }

    public function test_historical_public_turn_cannot_start_the_first_turn_extractor(): void
    {
        $history = [];
        for ($i = 0; $i < 8; $i++) {
            $history[] = ['role' => $i % 2 ? 'agent' : 'client', 'text' => 'Synthetic history '.$i];
        }
        [$tenant, $http, $claim] = $this->fixture('Final synthetic input', $history);
        $this->within($tenant, function () use ($http, $claim): void {
            try {
                app(SellerExtractorStages::class)->prepare($http, $claim);
                self::fail('A historical public claim entered the first-turn extractor.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
            self::assertSame(0, DB::table('c02_extractor_stages')->count());
            self::assertSame(9, DB::table('messages')->where('conversation_id', $claim->conversationId)->count());
            self::assertSame(0, DB::table('ai_calls')->count());
            Http::assertNothingSent();
        });
    }

    public function test_uncertain_cost_cannot_be_retried_or_read_as_result(): void
    {
        [$tenant, $http, $claim] = $this->fixture();
        $this->within($tenant, function () use ($http, $claim): void {
            $stage = app(SellerExtractorStages::class)->prepare($http, $claim);
            [$request] = app(SellerExtractorStages::class)->load($http, $claim, $stage);
            $file = $this->fakeFixture($request->toArray(), null);
            self::assertSame('uncertain', app(Gateway::class)->completeProtectedExtractor($http, $claim, $stage));
            unlink($file);
            self::assertSame('uncertain', app(Gateway::class)->completeProtectedExtractor($http, $claim, $stage));
            try {
                DB::transaction(fn () => app(Gateway::class)->readProtectedExtractorLocked($http, $claim, $stage));
                self::fail('Unknown cost is not a completed result.');
            } catch (LogicException $error) {
                self::assertSame('Protected extractor result unavailable.', $error->getMessage());
            }
            self::assertSame(1, DB::table('ai_calls')->where('status', 'uncertain')->count());
            self::assertSame(1, DB::table('ai_budgets')->value('unresolved_count'));
            self::assertSame(0, DB::table('seller_tasks')->count());
            Http::assertNothingSent();
        });
    }

    public function test_other_tenant_and_revoked_session_or_seller_access_are_rejected(): void
    {
        [$tenant, $http, $claim] = $this->fixture();
        [$otherTenant, $otherHttp, $otherClaim] = $this->fixture();
        $stage = $this->within($tenant, fn () => app(SellerExtractorStages::class)->prepare($http, $claim));
        $otherStage = $this->within($otherTenant, fn () => app(SellerExtractorStages::class)->prepare($otherHttp, $otherClaim));
        $this->within($tenant, function () use ($http, $claim, $otherStage): void {
            try {
                app(Gateway::class)->completeProtectedExtractor($http, $claim, $otherStage);
                self::fail('Another tenant stage was accepted.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
        });
        $this->within($otherTenant, function () use ($otherHttp, $claim, $stage): void {
            try {
                app(Gateway::class)->completeProtectedExtractor($otherHttp, $claim, $stage);
                self::fail('Another tenant claim was accepted.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
        });
        DB::table('brand_modules')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->where('module', 'seller')->update(['enabled' => false]);
        $this->within($tenant, function () use ($http, $claim, $stage): void {
            try {
                app(Gateway::class)->completeProtectedExtractor($http, $claim, $stage);
                self::fail('Revoked seller access was accepted.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
        });
        DB::table('brand_modules')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->where('module', 'seller')->update(['enabled' => true]);
        DB::table('users')->where('id', $tenant->actorId)->update(['email_verified_at' => null]);
        $this->within($tenant, function () use ($http, $claim, $stage): void {
            try {
                app(Gateway::class)->completeProtectedExtractor($http, $claim, $stage);
                self::fail('Unverified user was accepted.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
        });
        DB::table('users')->where('id', $tenant->actorId)->update(['email_verified_at' => now()]);
        $this->within($tenant, function () use ($http, $claim, $stage): void {
            app(AuthSessionRegistry::class)->withinActive($http, fn () => app(AuthSessionRegistry::class)->revokeCurrent($http));
            try {
                app(Gateway::class)->completeProtectedExtractor($http, $claim, $stage);
                self::fail('Revoked session was accepted.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
        });
        self::assertSame(0, DB::table('ai_calls')->count());
        Http::assertNothingSent();
    }

    public function test_new_input_after_gateway_completion_hides_old_result(): void
    {
        [$tenant, $http, $claim] = $this->fixture();
        $this->within($tenant, function () use ($tenant, $http, $claim): void {
            $stage = app(SellerExtractorStages::class)->prepare($http, $claim);
            [$request] = app(SellerExtractorStages::class)->load($http, $claim, $stage);
            $file = $this->fakeFixture($request->toArray(), 2);
            self::assertSame('completed', app(Gateway::class)->completeProtectedExtractor($http, $claim, $stage));
            unlink($file);
            config(['database.connections.c02_extractor_competitor' => config('database.connections.pgsql')]);
            $other = DB::connection('c02_extractor_competitor');
            DB::transaction(function () use ($tenant, $http, $claim, $stage, $other): void {
                $result = app(Gateway::class)->readProtectedExtractorLocked($http, $claim, $stage);
                self::assertSame('answer_only', $result['client_intent']);
                $other->beginTransaction();
                try {
                    $other->statement("SET LOCAL lock_timeout = '250ms'");
                    $other->table('messages')->insert(['id' => (string) Str::ulid(), 'organization_id' => $tenant->organizationId,
                        'brand_id' => $tenant->brandId, 'conversation_id' => $claim->conversationId, 'role' => 'client', 'text' => 'Concurrent synthetic input', 'meta' => '{}']);
                    self::fail('New input crossed the protected read transaction.');
                } catch (QueryException $error) {
                    self::assertSame('55P03', $error->errorInfo[0]);
                } finally {
                    $other->rollBack();
                }
            });
            DB::purge('c02_extractor_competitor');
            Message::create(['conversation_id' => $claim->conversationId, 'role' => 'client', 'text' => 'Later synthetic input', 'meta' => []]);
            try {
                DB::transaction(fn () => app(Gateway::class)->readProtectedExtractorLocked($http, $claim, $stage));
                self::fail('Stale extraction was exposed.');
            } catch (LogicException $error) {
                self::assertSame('Protected extractor result unavailable.', $error->getMessage());
            }
            self::assertSame('uncertain', DB::table('seller_turns')->where('id', $claim->turnId)->value('status'));
            self::assertSame(2, DB::table('messages')->where('conversation_id', $claim->conversationId)->count());
            self::assertSame(1, DB::table('ai_calls')->count());
            self::assertSame(0, DB::table('seller_tasks')->count());
            Http::assertNothingSent();
        });
    }

    public function test_live_driver_and_new_input_before_reservation_fail_before_any_call(): void
    {
        [$tenant, $http, $claim] = $this->fixture();
        $this->within($tenant, function () use ($http, $claim): void {
            $stage = app(SellerExtractorStages::class)->prepare($http, $claim);
            config(['runtime.ai_driver' => 'openrouter', 'features.E0-05.defaults.live_enabled' => true]);
            try {
                app(Gateway::class)->completeProtectedExtractor($http, $claim, $stage);
                self::fail('Live driver was accepted by the protected stage.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
            config(['runtime.ai_driver' => 'fake']);
            Message::create(['conversation_id' => $claim->conversationId, 'role' => 'client', 'text' => 'Later synthetic input', 'meta' => []]);
            try {
                app(Gateway::class)->completeProtectedExtractor($http, $claim, $stage);
                self::fail('Stale input was dispatched.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
            self::assertSame('uncertain', DB::table('seller_turns')->where('id', $claim->turnId)->value('status'));
            self::assertSame(0, DB::table('ai_calls')->count());
            Http::assertNothingSent();
        });
    }

    public function test_new_input_during_fake_result_persistence_preserves_accounting_but_hides_result(): void
    {
        [$tenant, $http, $claim] = $this->fixture();
        $stage = $this->within($tenant, fn () => app(SellerExtractorStages::class)->prepare($http, $claim));
        [$request] = $this->within($tenant, fn () => app(SellerExtractorStages::class)->load($http, $claim, $stage));
        $file = $this->fakeFixture($request->toArray(), 2);
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION c02_test_delay_extractor() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF NEW.result_encrypted IS NOT NULL AND OLD.result_encrypted IS NULL THEN
    PERFORM pg_advisory_xact_lock(8620250);
  END IF;
  RETURN NEW;
END;
$$;
CREATE TRIGGER c02_test_delay_extractor BEFORE UPDATE ON ai_calls FOR EACH ROW EXECUTE FUNCTION c02_test_delay_extractor();
SQL);
        config(['database.connections.c02_extractor_advisory' => config('database.connections.pgsql')]);
        $blocker = DB::connection('c02_extractor_advisory');
        $blocker->beginTransaction();
        $blocker->select('SELECT pg_advisory_xact_lock(8620250)');
        $worker = new Process([PHP_BINARY, '-d', 'opcache.enable_cli=0', '-d', 'allow_url_fopen=0',
            '-d', 'disable_functions=fsockopen,pfsockopen,stream_socket_client,curl_exec,curl_multi_exec',
            base_path('tests/Feature/Ai/Fixtures/protected-extractor-worker.php')], base_path());
        $worker->setInput(json_encode(['org' => $tenant->organizationId, 'brand' => $tenant->brandId,
            'user' => $tenant->actorId, 'member' => $tenant->membershipId, 'session' => $http->session()->getId(),
            'turn' => $claim->turnId, 'conversation' => $claim->conversationId, 'version' => $claim->baseVersion,
            'token' => $claim->token(), 'stage' => $stage, 'fixtures' => dirname($file)], JSON_THROW_ON_ERROR));
        $worker->setTimeout(20);
        $inputRecorded = false;
        try {
            $worker->start();
            $deadline = microtime(true) + 15;
            do {
                if (! $worker->isRunning()) {
                    self::fail('Protected worker exited before the test lock.');
                }
                $waiting = DB::selectOne("select count(*) as n from pg_stat_activity where datname = current_database() and wait_event = 'advisory'")->n;
                if ((int) $waiting > 0) {
                    break;
                }
                usleep(50000);
            } while (microtime(true) < $deadline);
            self::assertGreaterThan(0, (int) $waiting);
            $this->within($tenant, fn () => Message::create(['conversation_id' => $claim->conversationId, 'role' => 'client', 'text' => 'Later synthetic input', 'meta' => []]));
            $inputRecorded = true;
        } finally {
            $blocker->rollBack();
            if (! $inputRecorded) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
                if (is_file($file)) {
                    unlink($file);
                }
                DB::purge('c02_extractor_advisory');
            }
        }
        try {
            $worker->wait();
            self::assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
            self::assertSame(['status' => 'completed'], json_decode($worker->getOutput(), true, 512, JSON_THROW_ON_ERROR));
            $this->within($tenant, function () use ($http, $claim, $stage): void {
                try {
                    DB::transaction(fn () => app(Gateway::class)->readProtectedExtractorLocked($http, $claim, $stage));
                    self::fail('Result of a stale turn was exposed.');
                } catch (LogicException $error) {
                    self::assertSame('Protected extractor result unavailable.', $error->getMessage());
                }
            });
            self::assertSame('uncertain', DB::table('seller_turns')->where('id', $claim->turnId)->value('status'));
            self::assertSame('completed', DB::table('ai_calls')->value('status'));
            self::assertSame(2, DB::table('ai_budgets')->value('spent_micros'));
            self::assertSame(2, DB::table('messages')->where('conversation_id', $claim->conversationId)->count());
            self::assertSame(0, DB::table('seller_tasks')->count());
            Http::assertNothingSent();
        } finally {
            if ($worker->isRunning()) {
                $worker->stop();
            }
            if (is_file($file)) {
                unlink($file);
            }
            DB::purge('c02_extractor_advisory');
        }
    }

    public function test_unsolicited_tool_calls_are_never_exposed_or_executed(): void
    {
        [$tenant, $http, $claim] = $this->fixture();
        $this->within($tenant, function () use ($http, $claim): void {
            $stage = app(SellerExtractorStages::class)->prepare($http, $claim);
            [$request] = app(SellerExtractorStages::class)->load($http, $claim, $stage);
            $file = $this->fakeFixture($request->toArray(), 3);
            file_put_contents($file, json_encode(['result' => ['content' => null,
                'toolCalls' => [['id' => 'synthetic-call', 'name' => 'create_promise', 'arguments' => ['text' => 'Synthetic promise']]],
                'usage' => ['promptTokens' => 1, 'completionTokens' => 1], 'model' => $request->model, 'latencyMs' => 1, 'costMicros' => 3]], JSON_THROW_ON_ERROR));
            self::assertSame('completed', app(Gateway::class)->completeProtectedExtractor($http, $claim, $stage));
            unlink($file);
            try {
                DB::transaction(fn () => app(Gateway::class)->readProtectedExtractorLocked($http, $claim, $stage));
                self::fail('Unexpected tool call was exposed.');
            } catch (LogicException $error) {
                self::assertSame('Protected extractor result unavailable.', $error->getMessage());
                self::assertNull($error->getPrevious());
            }
            self::assertSame(1, DB::table('ai_calls')->count());
            self::assertSame(0, DB::table('seller_tasks')->count());
            self::assertSame(0, DB::table('outbox_events')->count());
            Http::assertNothingSent();
        });
    }

    public function test_swapped_gateway_ciphertext_and_changed_release_are_rejected(): void
    {
        [$tenant, $http, $claim] = $this->fixture('First synthetic input');
        [$otherTenant, $otherHttp, $otherClaim] = $this->fixture('Second synthetic input');
        $stage = $this->completeFixtureStage($tenant, $http, $claim);
        $otherStage = $this->completeFixtureStage($otherTenant, $otherHttp, $otherClaim);
        $call = DB::table('c02_extractor_stages')->where('id', $stage)->value('ai_call_id');
        $otherCall = DB::table('c02_extractor_stages')->where('id', $otherStage)->value('ai_call_id');
        $cipher = DB::table('ai_calls')->where('id', $otherCall)->value('result_encrypted');
        DB::table('ai_calls')->where('id', $call)->update(['result_encrypted' => $cipher]);
        $this->within($tenant, function () use ($http, $claim, $stage): void {
            try {
                DB::transaction(fn () => app(Gateway::class)->readProtectedExtractorLocked($http, $claim, $stage));
                self::fail('A different call result was accepted.');
            } catch (LogicException $error) {
                self::assertSame('Protected extractor result unavailable.', $error->getMessage());
            }
        });
        DB::table('brands')->where('id', $otherTenant->brandId)->update(['active_knowledge_release_id' => null]);
        $this->within($otherTenant, function () use ($otherHttp, $otherClaim, $otherStage): void {
            try {
                DB::transaction(fn () => app(Gateway::class)->readProtectedExtractorLocked($otherHttp, $otherClaim, $otherStage));
                self::fail('A no-longer-active release was accepted.');
            } catch (LogicException $error) {
                self::assertSame('Protected extractor result unavailable.', $error->getMessage());
            }
        });
        self::assertSame(2, DB::table('ai_calls')->count());
        self::assertSame(0, DB::table('seller_tasks')->count());
        Http::assertNothingSent();
    }

    public function test_plan_revocation_and_new_limit_wait_for_the_protected_transaction(): void
    {
        [$tenant, $http, $claim] = $this->fixture();
        $plan = (string) Str::ulid();
        $assignment = (string) Str::ulid();
        DB::table('plans')->insert(['id' => $plan, 'code' => 'synthetic-'.Str::lower(Str::random(10)), 'name' => 'Synthetic']);
        DB::table('organization_plan')->insert(['id' => $assignment, 'organization_id' => $tenant->organizationId,
            'plan_id' => $plan, 'starts_at' => now()->subHour(), 'ends_at' => now()->addHour()]);
        DB::table('plan_limits')->insert(['id' => (string) Str::ulid(), 'plan_id' => $plan, 'key' => 'modules.seller', 'value' => 1]);
        $this->within($tenant, function () use ($tenant, $http, $claim, $plan, $assignment): void {
            $stage = app(SellerExtractorStages::class)->prepare($http, $claim);
            config(['database.connections.c02_plan_competitor' => config('database.connections.pgsql')]);
            $other = DB::connection('c02_plan_competitor');
            DB::transaction(function () use ($tenant, $http, $claim, $stage, $plan, $assignment, $other): void {
                app(SellerExtractorStages::class)->load($http, $claim, $stage);
                foreach ([
                    fn () => $other->table('plan_limits')->where('plan_id', $plan)->where('key', 'modules.seller')->update(['value' => 0]),
                    fn () => $other->table('plan_limits')->insert(['id' => (string) Str::ulid(), 'plan_id' => $plan, 'key' => 'modules.vision', 'value' => 0]),
                    fn () => $other->table('organization_plan')->where('id', $assignment)->update(['ends_at' => now()]),
                    fn () => $other->table('organization_plan')->insert(['id' => (string) Str::ulid(), 'organization_id' => $tenant->organizationId,
                        'plan_id' => $plan, 'starts_at' => now()->addDay()]),
                    fn () => $other->table('prompt_sets')->whereNull('organization_id')->where('name', 'extractor')->update(['status' => 'draft']),
                ] as $operation) {
                    $other->beginTransaction();
                    try {
                        $other->statement("SET LOCAL lock_timeout = '250ms'");
                        $operation();
                        self::fail('Concurrent plan change crossed the protected boundary.');
                    } catch (QueryException $error) {
                        self::assertSame('55P03', $error->errorInfo[0]);
                    } finally {
                        $other->rollBack();
                    }
                }
            });
            DB::purge('c02_plan_competitor');
            DB::table('plan_limits')->where('plan_id', $plan)->where('key', 'modules.seller')->update(['value' => 0]);
            try {
                app(Gateway::class)->completeProtectedExtractor($http, $claim, $stage);
                self::fail('Revoked plan access was accepted.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
        });
        self::assertSame(0, DB::table('ai_calls')->count());
        Http::assertNothingSent();
    }

    private function completeFixtureStage(TenantContext $tenant, Request $http, TurnClaim $claim): string
    {
        return $this->within($tenant, function () use ($http, $claim): string {
            $stage = app(SellerExtractorStages::class)->prepare($http, $claim);
            [$request] = app(SellerExtractorStages::class)->load($http, $claim, $stage);
            $file = $this->fakeFixture($request->toArray(), 1);
            self::assertSame('completed', app(Gateway::class)->completeProtectedExtractor($http, $claim, $stage));
            unlink($file);

            return $stage;
        });
    }

    public function test_stage_database_rows_cannot_be_rewritten_deleted_or_attached_to_an_old_call(): void
    {
        [$tenant, $http, $claim] = $this->fixture();
        $this->within($tenant, function () use ($http, $claim): void {
            $stage = app(SellerExtractorStages::class)->prepare($http, $claim);
            [$request, $context] = app(SellerExtractorStages::class)->load($http, $claim, $stage);
            $file = $this->fakeFixture($request->toArray(), 3);
            try {
                app(Gateway::class)->complete($request, $context);
                self::fail('Prepared protected stage was sent through the public gateway.');
            } catch (LogicException $error) {
                self::assertSame('AI request could not be safely completed.', $error->getMessage());
            }
            self::assertSame(0, DB::table('ai_calls')->count());
            $publicContext = new AiContext($context->organizationId, $context->brandId, 'extractor', 'seller', 'conversation',
                $context->objectId, 'public-prior-call', $context->dataClasses);
            app(Gateway::class)->complete($request, $publicContext);
            unlink($file);
            $oldCall = DB::table('ai_calls')->first();
            try {
                DB::transaction(fn () => DB::table('c02_extractor_stages')->where('id', $stage)->update([
                    'ai_call_id' => $oldCall->id, 'gateway_request_hash' => $oldCall->request_hash,
                ]));
                self::fail('An earlier public call was attached to a stage.');
            } catch (QueryException $error) {
                self::assertSame('23514', $error->errorInfo[0]);
            }
            self::assertNull(DB::table('c02_extractor_stages')->where('id', $stage)->value('ai_call_id'));
            foreach ([fn () => DB::table('c02_extractor_stages')->where('id', $stage)->update(['input_encrypted' => 'forged']),
                fn () => DB::table('c02_extractor_stages')->where('id', $stage)->delete(),
                fn () => DB::statement('TRUNCATE TABLE c02_extractor_stages')] as $operation) {
                try {
                    DB::transaction($operation);
                    self::fail('Protected stage mutation was accepted.');
                } catch (QueryException $error) {
                    self::assertSame('23514', $error->errorInfo[0]);
                }
            }
            $migration = require database_path('migrations/C-02/2026_11_01_050000_c02_create_extractor_stages.php');
            try {
                $migration->down();
                self::fail('Protected history must prevent migration rollback.');
            } catch (\RuntimeException $error) {
                self::assertSame('Cannot discard protected extractor stage history.', $error->getMessage());
            }
            self::assertSame(1, DB::table('ai_calls')->count());
            Http::assertNothingSent();
        });
    }

    private function fixture(string $text = 'Synthetic question', array $history = [], bool $ordinary = false): array
    {
        app(PromptSetsSeeder::class)->run();
        $user = User::create(['name' => 'Synthetic', 'email' => 'c02-'.Str::lower(Str::random(12)).'@example.test', 'password' => 'synthetic-test-password']);
        $user->forceFill(['email_verified_at' => now()])->save();
        $user->refresh();
        $org = Organization::create(['name' => 'Synthetic', 'owner_user_id' => $user->id]);
        [$member, $brand] = $this->within(new TenantContext($org->id, actorId: $user->id), fn () => [
            Membership::create(['user_id' => $user->id, 'role' => 'owner']), Brand::create(['name' => 'Synthetic brand', 'timezone' => 'Europe/Moscow']),
        ]);
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $tenant = new TenantContext($org->id, $brand->id, $user->id, $member->id);
        $this->within($tenant, fn () => app(YamlKnowledgeImporter::class)->importAndPublish($tenant, base_path('tests/Feature/Seller/Knowledge/Fixtures/data')));
        $session = new Store('c02-extractor-session', new ArraySessionHandler(120));
        $session->setId(Str::random(40));
        $session->start();
        $session->put('auth.version', $user->auth_version);
        $http = Request::create('/', 'POST');
        $http->setLaravelSession($session);
        $http->setUserResolver(fn () => $user);
        app(AuthSessionRegistry::class)->issue($http, $user, $user->auth_version);
        app(AuthSessionRegistry::class)->withinActive($http, fn () => app(SessionBrandSelection::class)->initialize($http, $org->id, $brand->id));
        [$conversation, $input] = $this->within($tenant, function () use ($text, $history): array {
            $conversation = Conversation::create(['state' => []]);
            foreach ($history as $message) {
                Message::create(['conversation_id' => $conversation->id, 'role' => $message['role'], 'text' => $message['text'], 'meta' => []]);
            }
            $input = Message::create(['conversation_id' => $conversation->id, 'role' => 'client', 'text' => $text, 'meta' => []]);

            return [$conversation, $input];
        });
        $command = new TurnCommand($conversation->id, $input->id, $input->text, null, 'extractor-stage-'.Str::random(16), 0);
        $claim = $history === [] && ! $ordinary
            ? $this->within($tenant, fn () => app(FirstTurnProtectedClaim::class)->claim($http, $command))
            : app(ConversationRepository::class)->claimTurn($tenant, $command);

        return [$tenant, $http, $claim];
    }

    private function fakeFixture(array $request, ?int $cost): string
    {
        $directory = storage_path('app/extractor-fixtures-'.Str::random(12));
        mkdir($directory, 0700, true);
        config(['features.E0-05.defaults.fixture_directory' => $directory]);
        $body = app(ContactMasker::class)->request($request);
        $file = $directory.DIRECTORY_SEPARATOR.CanonicalJson::hash($body).'.json';
        file_put_contents($file, json_encode(['result' => ['content' => '{"client_intent":"answer_only","slot_updates":{"email":"[EMAIL]"}}', 'toolCalls' => [],
            'usage' => ['promptTokens' => 1, 'completionTokens' => 1], 'model' => $request['model'], 'latencyMs' => 1, 'costMicros' => $cost]], JSON_THROW_ON_ERROR));

        return $file;
    }

    private function within(TenantContext $tenant, \Closure $action): mixed
    {
        return app(TenantContextRunner::class)->run($tenant, $action);
    }
}

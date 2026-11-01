<?php

namespace Tests\Feature\ProtectedOperations;

use App\Ai\CanonicalJson;
use App\Ai\ContactMasker;
use App\Ai\Drivers\Driver;
use App\Ai\Drivers\FakeDriver;
use App\Ai\Gateway;
use App\Ai\Protected\GuardProjection;
use App\Ai\Protected\ResponderProjection;
use App\Ai\Protected\SellerExtractorStages;
use App\Ai\Protected\SellerGuardStages;
use App\Ai\Protected\SellerResponderStages;
use App\Contracts\Ai\AiContext;
use App\Contracts\ProtectedOperations\IdempotencyConflict;
use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\ProtectedOperations\FirstTurnCoordinator;
use App\ProtectedOperations\FirstTurnDeterministicGuard;
use App\ProtectedOperations\FirstTurnGoal;
use App\ProtectedOperations\FirstTurnProtectedClaim;
use App\ProtectedOperations\FirstTurnState;
use App\ProtectedOperations\FirstTurnTextFinalizer;
use App\ProtectedOperations\PostgresTransactionalIdempotency;
use App\Seller\Conversations\Contracts\ConversationRepository;
use App\Seller\Conversations\Data\TurnClaim;
use App\Seller\Conversations\Data\TurnCommand;
use App\Seller\Conversations\Models\Conversation;
use App\Seller\Conversations\Models\Message;
use App\Seller\Engine\LeadState;
use App\Seller\Engine\Policy;
use App\Seller\Knowledge\Contracts\KnowledgeRepository;
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
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use LogicException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class CoordinatorScriptedFakeDriver implements Driver
{
    public int $calls = 0;

    public ?\Closure $onCall = null;

    public function __construct(public array $responses) {}

    public function complete(#[\SensitiveParameter] array $body): array
    {
        $index = $this->calls++;
        if ($this->onCall !== null) {
            ($this->onCall)($index);
        }
        if (! isset($this->responses[$index])) {
            throw new LogicException('Unexpected synthetic fake call.');
        }

        return array_replace(['content' => null, 'toolCalls' => [], 'usage' => ['promptTokens' => 1, 'completionTokens' => 1],
            'model' => $body['model'], 'latencyMs' => 1, 'costMicros' => 0], $this->responses[$index]);
    }
}

final class FirstTurnStateTest extends TestCase
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

    public function test_first_turn_state_is_derived_once_from_bound_result_and_read_only_under_locks(): void
    {
        [$tenant, $http, $claim, $stage] = $this->fixture('Moscow, synthetic.user@example.test');
        $this->within($tenant, function () use ($http, $claim, $stage): void {
            $source = app(FirstTurnState::class);
            $id = $source->prepare($http, $claim, $stage);
            self::assertSame($id, $source->prepare($http, $claim, $stage));
            self::assertSame(1, DB::table('c02_first_turn_states')->count());
            $stored = DB::table('c02_first_turn_states')->where('id', $id)->first();
            self::assertSame($claim->turnId, $stored->turn_id);
            self::assertStringNotContainsString('synthetic.user@example.test', json_encode($stored, JSON_THROW_ON_ERROR));
            try {
                $source->readLocked($http, $claim, $stage, $id);
                self::fail('State was read outside a transaction.');
            } catch (LogicException $error) {
                self::assertSame('Protected first-turn state unavailable.', $error->getMessage());
            }
            $state = DB::transaction(fn () => $source->readLocked($http, $claim, $stage, $id));
            self::assertSame('Москва', $state['slots']['city']);
            self::assertSame('synthetic.user@example.test', $state['slots']['email']);
            self::assertSame(1, $state['counters']['turns']);
            self::assertSame('answer_only', $state['signals']['last_client_intent']);
            $conversation = DB::table('conversations')->where('id', $claim->conversationId)->first();
            self::assertSame([], json_decode($conversation->state, true, 512, JSON_THROW_ON_ERROR));
            self::assertSame(0, $conversation->version);
            self::assertSame(1, DB::table('ai_calls')->count());
            self::assertSame(0, DB::table('seller_tasks')->count());
            self::assertSame(0, DB::table('outbox_events')->count());
            Http::assertNothingSent();
            Mail::assertNothingOutgoing();
            Queue::assertNothingPushed();
        });
    }

    public function test_old_or_nonempty_state_is_rejected_before_a_protected_extractor_stage(): void
    {
        foreach ([['state' => ['slots' => ['city' => 'Forged']]], ['history' => true], ['changed' => true], ['mode' => 'manager']] as $case) {
            [$tenant, $http, $command] = $this->protectedClaimFixture();
            $this->within($tenant, function () use ($http, $command, $case): void {
                if (isset($case['state'])) {
                    DB::table('conversations')->where('id', $command->conversationId)->update(['state' => json_encode($case['state'], JSON_THROW_ON_ERROR)]);
                } elseif (isset($case['mode'])) {
                    DB::table('conversations')->where('id', $command->conversationId)->update(['mode' => $case['mode']]);
                } elseif (isset($case['history'])) {
                    Message::create(['conversation_id' => $command->conversationId, 'role' => 'agent', 'text' => 'Earlier synthetic output', 'meta' => []]);
                } else {
                    DB::table('conversations')->where('id', $command->conversationId)->update(['state' => '{"temporary":true}']);
                    DB::table('conversations')->where('id', $command->conversationId)->update(['state' => '[]']);
                }
                try {
                    app(FirstTurnProtectedClaim::class)->claim($http, $command);
                    self::fail('Unproven initial state was accepted.');
                } catch (LogicException $error) {
                    self::assertSame('Protected first-turn claim unavailable.', $error->getMessage());
                    self::assertNull($error->getPrevious());
                }
                self::assertSame(0, DB::table('c02_extractor_stages')->where('conversation_id', $command->conversationId)->count());
                self::assertSame(0, DB::table('ai_calls')->count());
            });
        }
        Http::assertNothingSent();
    }

    public function test_forged_claim_other_stage_revoked_session_and_changed_input_are_rejected(): void
    {
        [$tenant, $http, $claim, $stage] = $this->fixture();
        [$otherTenant, $otherHttp, $otherClaim, $otherStage] = $this->fixture('Other synthetic input');
        $this->within($tenant, function () use ($http, $claim, $stage, $otherStage): void {
            $source = app(FirstTurnState::class);
            $id = $source->prepare($http, $claim, $stage);
            $forged = new TurnClaim('claimed', $claim->turnId, $claim->conversationId, $claim->organizationId, $claim->brandId, $claim->baseVersion, str_repeat('a', 64));
            foreach ([[$forged, $stage], [$claim, $otherStage]] as [$turn, $stageId]) {
                try {
                    DB::transaction(fn () => $source->readLocked($http, $turn, $stageId, $id));
                    self::fail('Forged provenance was accepted.');
                } catch (LogicException $error) {
                    self::assertSame('Protected first-turn state unavailable.', $error->getMessage());
                    self::assertNull($error->getPrevious());
                    $trace = json_encode($error->getTrace(), JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_THROW_ON_ERROR);
                    self::assertStringNotContainsString($turn->token(), $trace);
                    self::assertStringNotContainsString($http->session()->getId(), $trace);
                }
            }
            Message::create(['conversation_id' => $claim->conversationId, 'role' => 'client', 'text' => 'Later synthetic input', 'meta' => []]);
            try {
                DB::transaction(fn () => $source->readLocked($http, $claim, $stage, $id));
                self::fail('Stale state was returned.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
            self::assertSame('uncertain', DB::table('seller_turns')->where('id', $claim->turnId)->value('status'));
            self::assertSame(2, DB::table('messages')->where('conversation_id', $claim->conversationId)->count());
        });
        $this->within($otherTenant, function () use ($otherHttp, $otherClaim, $otherStage): void {
            $source = app(FirstTurnState::class);
            $id = $source->prepare($otherHttp, $otherClaim, $otherStage);
            app(AuthSessionRegistry::class)->withinActive($otherHttp, fn () => app(AuthSessionRegistry::class)->revokeCurrent($otherHttp));
            try {
                DB::transaction(fn () => $source->readLocked($otherHttp, $otherClaim, $otherStage, $id));
                self::fail('Revoked session read state.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
        });
        self::assertSame(2, DB::table('ai_calls')->count());
        Http::assertNothingSent();
    }

    public function test_tampering_and_old_untrusted_ai_results_cannot_create_provenance(): void
    {
        [$tenant, $http, $claim, $stage] = $this->fixture();
        $this->within($tenant, function () use ($http, $claim, $stage): void {
            $source = app(FirstTurnState::class);
            $id = $source->prepare($http, $claim, $stage);
            foreach ([fn () => DB::table('c02_first_turn_states')->where('id', $id)->update(['state_encrypted' => 'forged']),
                fn () => DB::table('c02_first_turn_states')->where('id', $id)->delete(),
                fn () => DB::statement('TRUNCATE TABLE c02_first_turn_states')] as $operation) {
                try {
                    DB::transaction($operation);
                    self::fail('Protected state history changed.');
                } catch (QueryException $error) {
                    self::assertSame('23514', $error->errorInfo[0]);
                }
            }
            $call = DB::table('c02_extractor_stages')->where('id', $stage)->value('ai_call_id');
            DB::table('ai_calls')->where('id', $call)->update(['cost_micros' => 999]);
            try {
                DB::transaction(fn () => $source->readLocked($http, $claim, $stage, $id));
                self::fail('Altered accounting was accepted.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
        });
        Http::assertNothingSent();
    }

    public function test_unknown_or_fallback_extraction_cannot_become_proven_state(): void
    {
        foreach ([['cost' => null], ['invalid' => true]] as $case) {
            [$tenant, $http, $claim, $stage] = $this->fixture('Synthetic input', $case);
            $this->within($tenant, function () use ($http, $claim, $stage): void {
                try {
                    app(FirstTurnState::class)->prepare($http, $claim, $stage);
                    self::fail('Unknown or fallback extraction was accepted.');
                } catch (LogicException) {
                    self::assertTrue(true);
                }
                self::assertSame(0, DB::table('c02_first_turn_states')->where('turn_id', $claim->turnId)->count());
            });
        }
        self::assertSame(2, DB::table('ai_calls')->count());
        Http::assertNothingSent();
    }

    public function test_forged_database_row_cannot_be_read_as_server_origin(): void
    {
        [$tenant, $http, $claim, $stageId] = $this->fixture();
        $stage = DB::table('c02_extractor_stages')->where('id', $stageId)->first();
        $id = (string) Str::ulid();
        $row = ['id' => $id, 'organization_id' => $tenant->organizationId,
            'brand_id' => $tenant->brandId, 'conversation_id' => $claim->conversationId, 'turn_id' => $claim->turnId,
            'extractor_stage_id' => $stageId, 'ai_call_id' => $stage->ai_call_id, 'release_id' => $stage->release_id,
            'state_encrypted' => 'forged', 'state_digest' => str_repeat('a', 64), 'seal' => str_repeat('b', 64)];
        try {
            DB::transaction(fn () => DB::table('c02_first_turn_states')->insert([...$row, 'extractor_stage_id' => (string) Str::ulid()]));
            self::fail('A missing extractor stage was accepted.');
        } catch (QueryException $error) {
            self::assertSame('23514', $error->errorInfo[0]);
        }
        DB::table('c02_first_turn_states')->insert($row);
        $this->within($tenant, function () use ($http, $claim, $stageId, $id): void {
            try {
                DB::transaction(fn () => app(FirstTurnState::class)->readLocked($http, $claim, $stageId, $id));
                self::fail('An unsigned database row was accepted.');
            } catch (LogicException $error) {
                self::assertSame('Protected first-turn state unavailable.', $error->getMessage());
            }
            try {
                app(FirstTurnState::class)->prepare($http, $claim, $stageId);
                self::fail('An unsigned row was reused.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
        });
        self::assertSame(1, DB::table('ai_calls')->count());
        Http::assertNothingSent();
    }

    public function test_new_input_waits_for_state_transaction_and_then_invalidates_read(): void
    {
        [$tenant, $http, $claim, $stage] = $this->fixture();
        $this->within($tenant, function () use ($tenant, $http, $claim, $stage): void {
            config(['database.connections.c02_state_competitor' => config('database.connections.pgsql')]);
            $other = DB::connection('c02_state_competitor');
            $id = DB::transaction(function () use ($tenant, $http, $claim, $stage, $other): string {
                $id = app(FirstTurnState::class)->prepare($http, $claim, $stage);
                self::assertSame(1, app(FirstTurnState::class)->readLocked($http, $claim, $stage, $id)['counters']['turns']);
                $other->beginTransaction();
                try {
                    $other->statement("SET LOCAL lock_timeout = '250ms'");
                    $other->table('messages')->insert(['id' => (string) Str::ulid(), 'organization_id' => $tenant->organizationId,
                        'brand_id' => $tenant->brandId, 'conversation_id' => $claim->conversationId,
                        'role' => 'client', 'text' => 'Concurrent synthetic input', 'meta' => '{}']);
                    self::fail('Input crossed the state transaction.');
                } catch (QueryException $error) {
                    self::assertSame('55P03', $error->errorInfo[0]);
                } finally {
                    $other->rollBack();
                }

                return $id;
            });
            DB::purge('c02_state_competitor');
            Message::create(['conversation_id' => $claim->conversationId, 'role' => 'client', 'text' => 'Later synthetic input', 'meta' => []]);
            try {
                DB::transaction(fn () => app(FirstTurnState::class)->readLocked($http, $claim, $stage, $id));
                self::fail('Stale state remained available.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
            self::assertSame(2, DB::table('messages')->where('conversation_id', $claim->conversationId)->count());
            self::assertSame('uncertain', DB::table('seller_turns')->where('id', $claim->turnId)->value('status'));
            self::assertSame(1, DB::table('ai_calls')->count());
        });
        Http::assertNothingSent();
    }

    public function test_migration_preserves_existing_data_and_refuses_to_discard_provenance(): void
    {
        $migration = require database_path('migrations/C-02/2026_11_01_060000_c02_create_first_turn_states.php');
        $migration->down();
        [$tenant, $http, $claim, $stage] = $this->fixture();
        $migration->up();
        self::assertSame(1, DB::table('messages')->where('conversation_id', $claim->conversationId)->count());
        self::assertSame(1, DB::table('ai_calls')->count());
        self::assertSame('processing', DB::table('seller_turns')->where('id', $claim->turnId)->value('status'));
        $this->within($tenant, function () use ($http, $claim, $stage): void {
            $id = app(FirstTurnState::class)->prepare($http, $claim, $stage);
            self::assertSame($id, app(FirstTurnState::class)->prepare($http, $claim, $stage));
        });
        try {
            $migration->down();
            self::fail('Migration discarded provenance.');
        } catch (\RuntimeException $error) {
            self::assertSame('Cannot discard protected state history.', $error->getMessage());
        }
        self::assertSame(1, DB::table('c02_first_turn_states')->count());
        Http::assertNothingSent();
    }

    public function test_outer_rollback_discards_new_state_without_repeating_extraction(): void
    {
        [$tenant, $http, $claim, $stage] = $this->fixture();
        $this->within($tenant, function () use ($http, $claim, $stage): void {
            $rolledBackId = null;
            try {
                DB::transaction(function () use ($http, $claim, $stage, &$rolledBackId): void {
                    $rolledBackId = app(FirstTurnState::class)->prepare($http, $claim, $stage);
                    throw new \RuntimeException('Synthetic outer rollback.');
                });
                self::fail('The outer transaction did not roll back.');
            } catch (\RuntimeException $error) {
                self::assertSame('Synthetic outer rollback.', $error->getMessage());
            }
            self::assertSame(0, DB::table('c02_first_turn_states')->count());
            $committedId = app(FirstTurnState::class)->prepare($http, $claim, $stage);
            self::assertNotSame($rolledBackId, $committedId);
            self::assertSame(1, DB::transaction(fn () => app(FirstTurnState::class)->readLocked($http, $claim, $stage, $committedId))['counters']['turns']);
            self::assertSame(1, DB::table('ai_calls')->count());
            self::assertSame(0, DB::table('seller_tasks')->count());
        });
        Http::assertNothingSent();
    }

    public function test_first_client_message_follows_donor_inbound_goal_not_proactive_opening(): void
    {
        $golden = json_decode(file_get_contents(base_path('tests/fixtures/prototype/synthetic/open-turn.json')), true, 512, JSON_THROW_ON_ERROR);
        $inbound = $golden['trace'][1]['meta'];
        self::assertSame('intro_and_ask', $golden['trace'][0]['meta']['goal']['kind']);
        self::assertSame('ask_slot', $inbound['goal']['kind']);
        [$tenant, $http, $claim, $stage] = $this->fixture('Тула, на продажу.', [
            'content' => json_encode($inbound['extraction'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);
        $this->within($tenant, function () use ($http, $claim, $stage, $inbound): void {
            $stateId = app(FirstTurnState::class)->prepare($http, $claim, $stage);
            $computed = DB::transaction(fn () => app(FirstTurnGoal::class)->evaluateLocked($http, $claim, $stage, $stateId));
            $actual = $computed['goal']->toArray();
            self::assertSame($inbound['goal']['kind'], $actual['kind']);
            self::assertSame($inbound['goal']['slot'], $actual['slot']);
            self::assertSame($inbound['goal']['instruction'], $actual['instruction']);
            self::assertSame(CanonicalJson::hash($inbound['goal']), CanonicalJson::hash($actual));
            self::assertFalse($computed['state']['flags']['introduced']);
            self::assertSame(1, $computed['state']['counters']['turns']);
        });
        Http::assertNothingSent();
    }

    public function test_full_goal_is_recomputed_from_proven_sources_and_mutable_outputs_are_not_trusted(): void
    {
        [$tenant, $http, $claim, $stage] = $this->fixture('Synthetic closed topic', [
            'content' => '{"client_intent":"answer_only","closed_topic":"management_names"}',
        ]);
        $this->within($tenant, function () use ($http, $claim, $stage): void {
            $stateId = app(FirstTurnState::class)->prepare($http, $claim, $stage);
            $source = app(FirstTurnGoal::class);
            try {
                $source->evaluateLocked($http, $claim, $stage, $stateId);
                self::fail('Goal was read outside a transaction.');
            } catch (LogicException $error) {
                self::assertSame('Protected first-turn goal unavailable.', $error->getMessage());
            }
            DB::transaction(function () use ($http, $claim, $stage, $stateId, $source): void {
                $first = $source->evaluateLocked($http, $claim, $stage, $stateId);
                self::assertSame('closed_topic', $first['goal']->kind);
                self::assertSame('management_names', $first['goal']->extras['closed_topic']['id']);
                self::assertArrayNotHasKey('closed_topic', $first['goal']->toArray()['extras']);
                self::assertSame(1, $first['state']['counters']['turns']);
                $first['goal']->extras['closed_topic']['id'] = 'forged';
                $first['goal']->allowedTools = ['forged_tool'];
                $first['state']['stage'] = 'done';
                $second = $source->evaluateLocked($http, $claim, $stage, $stateId);
                self::assertSame('management_names', $second['goal']->extras['closed_topic']['id']);
                self::assertSame([], $second['goal']->allowedTools);
                self::assertNotSame('done', $second['state']['stage']);
            });
            self::assertSame(1, DB::table('ai_calls')->count());
            self::assertSame(0, DB::table('seller_tasks')->count());
            self::assertSame(0, DB::table('outbox_events')->count());
        });
        Http::assertNothingSent();
        Mail::assertNothingOutgoing();
        Queue::assertNothingPushed();
    }

    public function test_goal_contains_complete_verified_facts_for_the_first_turn(): void
    {
        [$tenant, $http, $claim, $stage] = $this->fixture('Where are you?', [
            'content' => '{"client_intent":"ask_location"}',
        ]);
        $this->within($tenant, function () use ($http, $claim, $stage): void {
            $stateId = app(FirstTurnState::class)->prepare($http, $claim, $stage);
            $result = DB::transaction(fn () => app(FirstTurnGoal::class)->evaluateLocked($http, $claim, $stage, $stateId));
            self::assertSame('answer_and_ask', $result['goal']->kind);
            self::assertSame('company_location', $result['goal']->facts[0]['id']);
            self::assertNotEmpty($result['goal']->facts[0]['facts']);
            self::assertSame('company_location', $result['goal']->toArray()['facts'][0]);
            self::assertFalse($result['state']['flags']['introduced']);
            self::assertSame('open', $result['state']['stage']);
            self::assertSame(1, DB::table('ai_calls')->count());
            self::assertSame(0, DB::table('seller_tasks')->count());
        });
        Http::assertNothingSent();
    }

    public function test_policy_state_mutation_is_returned_but_neither_persisted_nor_executed(): void
    {
        [$tenant, $http, $claim, $stage] = $this->fixture('Do not contact', [
            'content' => '{"client_intent":"answer_only","do_not_disturb":true}',
        ]);
        $this->within($tenant, function () use ($http, $claim, $stage): void {
            $stateId = app(FirstTurnState::class)->prepare($http, $claim, $stage);
            DB::transaction(function () use ($http, $claim, $stage, $stateId): void {
                $source = app(FirstTurnGoal::class);
                $first = $source->evaluateLocked($http, $claim, $stage, $stateId);
                $second = $source->evaluateLocked($http, $claim, $stage, $stateId);
                self::assertSame('opt_out', $first['goal']->kind);
                self::assertSame(['mark_do_not_disturb'], $first['goal']->allowedTools);
                self::assertSame('lost', $first['state']['stage']);
                self::assertSame($first['state'], $second['state']);
                self::assertEquals($first['goal'], $second['goal']);
                $beforePolicy = app(FirstTurnState::class)->readLocked($http, $claim, $stage, $stateId);
                self::assertSame('open', $beforePolicy['stage']);
            });
            $conversation = DB::table('conversations')->where('id', $claim->conversationId)->first();
            self::assertFalse($conversation->do_not_disturb);
            self::assertSame([], json_decode($conversation->state, true, 512, JSON_THROW_ON_ERROR));
            self::assertSame(1, DB::table('ai_calls')->count());
            self::assertSame(0, DB::table('seller_tasks')->count());
            self::assertSame(0, DB::table('outbox_events')->count());
        });
        Http::assertNothingSent();
        Mail::assertNothingOutgoing();
        Queue::assertNothingPushed();
    }

    public function test_goal_rejects_forged_context_tampered_result_and_revoked_session(): void
    {
        [$tenant, $http, $claim, $stage] = $this->fixture();
        [$otherTenant, $otherHttp, $otherClaim, $otherStage] = $this->fixture('Other synthetic input');
        $otherCall = DB::table('c02_extractor_stages')->where('id', $otherStage)->value('ai_call_id');
        $otherCipher = DB::table('ai_calls')->where('id', $otherCall)->value('result_encrypted');
        $otherStateId = $this->within($otherTenant, fn () => app(FirstTurnState::class)->prepare($otherHttp, $otherClaim, $otherStage));
        $this->within($tenant, function () use ($http, $claim, $stage, $otherStage, $otherCipher, $otherStateId): void {
            $stateId = app(FirstTurnState::class)->prepare($http, $claim, $stage);
            $forged = new TurnClaim('claimed', $claim->turnId, $claim->conversationId, $claim->organizationId, $claim->brandId, $claim->baseVersion, str_repeat('a', 64));
            foreach ([[$forged, $stage, $stateId], [$claim, $otherStage, $stateId], [$claim, $stage, $otherStateId], [$claim, $stage, (string) Str::ulid()]] as [$turn, $stageId, $id]) {
                try {
                    DB::transaction(fn () => app(FirstTurnGoal::class)->evaluateLocked($http, $turn, $stageId, $id));
                    self::fail('Forged goal source was accepted.');
                } catch (LogicException $error) {
                    self::assertSame('Protected first-turn goal unavailable.', $error->getMessage());
                    self::assertNull($error->getPrevious());
                    $trace = json_encode($error->getTrace(), JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_THROW_ON_ERROR);
                    self::assertStringNotContainsString($turn->token(), $trace);
                    self::assertStringNotContainsString($http->session()->getId(), $trace);
                }
            }
            $call = DB::table('c02_extractor_stages')->where('id', $stage)->value('ai_call_id');
            $originalCipher = DB::table('ai_calls')->where('id', $call)->value('result_encrypted');
            DB::table('ai_calls')->where('id', $call)->update(['result_encrypted' => $otherCipher]);
            try {
                DB::transaction(fn () => app(FirstTurnGoal::class)->evaluateLocked($http, $claim, $stage, $stateId));
                self::fail('A different extractor result was accepted.');
            } catch (LogicException $error) {
                self::assertSame('Protected first-turn goal unavailable.', $error->getMessage());
            }
            DB::table('ai_calls')->where('id', $call)->update(['result_encrypted' => $originalCipher]);
            DB::table('ai_calls')->where('id', $call)->update(['cost_micros' => 999]);
            try {
                DB::transaction(fn () => app(FirstTurnGoal::class)->evaluateLocked($http, $claim, $stage, $stateId));
                self::fail('Tampered extractor accounting was accepted.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
        });
        $this->within($otherTenant, function () use ($otherTenant, $otherHttp, $otherClaim, $otherStage): void {
            $stateId = app(FirstTurnState::class)->prepare($otherHttp, $otherClaim, $otherStage);
            DB::table('memberships')->where('id', $otherTenant->membershipId)->update(['status' => 'disabled']);
            try {
                DB::transaction(fn () => app(FirstTurnGoal::class)->evaluateLocked($otherHttp, $otherClaim, $otherStage, $stateId));
                self::fail('Revoked membership computed a goal.');
            } catch (LogicException $error) {
                self::assertSame('Protected first-turn goal unavailable.', $error->getMessage());
            }
            DB::table('memberships')->where('id', $otherTenant->membershipId)->update(['status' => 'active']);
            app(AuthSessionRegistry::class)->withinActive($otherHttp, fn () => app(AuthSessionRegistry::class)->revokeCurrent($otherHttp));
            try {
                DB::transaction(fn () => app(FirstTurnGoal::class)->evaluateLocked($otherHttp, $otherClaim, $otherStage, $stateId));
                self::fail('Revoked session computed a goal.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
        });
        self::assertSame(2, DB::table('ai_calls')->count());
        self::assertSame(0, DB::table('seller_tasks')->count());
        Http::assertNothingSent();
    }

    public function test_goal_locks_new_input_and_becomes_stale_when_the_input_is_saved(): void
    {
        [$tenant, $http, $claim, $stage] = $this->fixture();
        $this->within($tenant, function () use ($tenant, $http, $claim, $stage): void {
            $stateId = app(FirstTurnState::class)->prepare($http, $claim, $stage);
            config(['database.connections.c02_goal_competitor' => config('database.connections.pgsql')]);
            $other = DB::connection('c02_goal_competitor');
            DB::transaction(function () use ($tenant, $http, $claim, $stage, $stateId, $other): void {
                self::assertNotEmpty(app(FirstTurnGoal::class)->evaluateLocked($http, $claim, $stage, $stateId)['goal']->kind);
                $other->beginTransaction();
                try {
                    $other->statement("SET LOCAL lock_timeout = '250ms'");
                    $other->table('messages')->insert(['id' => (string) Str::ulid(), 'organization_id' => $tenant->organizationId,
                        'brand_id' => $tenant->brandId, 'conversation_id' => $claim->conversationId,
                        'role' => 'client', 'text' => 'Concurrent synthetic input', 'meta' => '{}']);
                    self::fail('Input crossed goal evaluation.');
                } catch (QueryException $error) {
                    self::assertSame('55P03', $error->errorInfo[0]);
                } finally {
                    $other->rollBack();
                }
            });
            DB::purge('c02_goal_competitor');
            Message::create(['conversation_id' => $claim->conversationId, 'role' => 'client', 'text' => 'Later synthetic input', 'meta' => []]);
            try {
                DB::transaction(fn () => app(FirstTurnGoal::class)->evaluateLocked($http, $claim, $stage, $stateId));
                self::fail('Goal remained available after new input.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
            self::assertSame(2, DB::table('messages')->where('conversation_id', $claim->conversationId)->count());
            self::assertSame('uncertain', DB::table('seller_turns')->where('id', $claim->turnId)->value('status'));
            self::assertSame(1, DB::table('ai_calls')->count());
            self::assertSame(0, DB::table('seller_tasks')->count());
        });
        Http::assertNothingSent();
    }

    public function test_goal_is_unavailable_when_the_published_release_changes(): void
    {
        [$tenant, $http, $claim, $stage] = $this->fixture();
        $this->within($tenant, function () use ($tenant, $http, $claim, $stage): void {
            $stateId = app(FirstTurnState::class)->prepare($http, $claim, $stage);
            $goal = DB::transaction(fn () => app(FirstTurnGoal::class)->evaluateLocked($http, $claim, $stage, $stateId));
            self::assertNotEmpty($goal['goal']->kind);
            DB::table('brands')->where('id', $tenant->brandId)->update(['active_knowledge_release_id' => null]);
            try {
                DB::transaction(fn () => app(FirstTurnGoal::class)->evaluateLocked($http, $claim, $stage, $stateId));
                self::fail('A goal from an inactive release was accepted.');
            } catch (LogicException $error) {
                self::assertSame('Protected first-turn goal unavailable.', $error->getMessage());
            }
            self::assertSame(1, DB::table('ai_calls')->count());
            self::assertSame(0, DB::table('seller_tasks')->count());
        });
        Http::assertNothingSent();
    }

    public function test_saved_fake_responder_settles_once_after_crash_without_executing_tool(): void
    {
        [$tenant, $http, $claim, $extractor] = $this->fixture();
        [$stage, , , $file] = $this->responderFixture($tenant, $http, $claim, $extractor, [
            'content' => null, 'costMicros' => 7,
            'toolCalls' => [['id' => 'synthetic-tool', 'name' => 'send_price', 'arguments' => ['email' => 'synthetic@example.test']]],
        ]);
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION c02_test_delay_responder_settlement() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF NEW.unresolved_count < OLD.unresolved_count AND NEW.spent_micros > OLD.spent_micros THEN
    PERFORM pg_advisory_xact_lock(8620494);
  END IF;
  RETURN NEW;
END;
$$;
CREATE TRIGGER c02_test_delay_responder_settlement BEFORE UPDATE ON ai_budgets
  FOR EACH ROW EXECUTE FUNCTION c02_test_delay_responder_settlement();
SQL);
        config(['database.connections.c02_responder_settle_blocker' => config('database.connections.pgsql')]);
        $blocker = DB::connection('c02_responder_settle_blocker');
        $markers = array_map(fn () => base_path('.local/tmp/c02-responder-settle-'.Str::random(16)), range(1, 3));
        $workers = [];
        $make = function (string $marker, string $mode) use ($tenant, $http, $claim, $stage, $file): Process {
            $worker = new Process([PHP_BINARY, '-d', 'sys_temp_dir='.base_path('.local/tmp'), '-d', 'allow_url_fopen=0',
                '-d', 'disable_functions=fsockopen,pfsockopen,stream_socket_client,curl_exec,curl_multi_exec',
                base_path('tests/Feature/ProtectedOperations/Fixtures/responder-settlement-worker.php')], base_path());
            $worker->setInput(json_encode(['org' => $tenant->organizationId, 'brand' => $tenant->brandId, 'user' => $tenant->actorId,
                'member' => $tenant->membershipId, 'session' => $http->session()->getId(), 'turn' => $claim->turnId,
                'conversation' => $claim->conversationId, 'version' => $claim->baseVersion, 'token' => $claim->token(),
                'stage' => $stage, 'fixtures' => dirname($file), 'mode' => $mode, 'pid_marker' => $marker], JSON_THROW_ON_ERROR));
            $worker->setTimeout(60);

            return $worker;
        };
        try {
            $blocker->beginTransaction();
            $blocker->select('SELECT pg_advisory_xact_lock(8620494)');
            $workers[] = $make($markers[0], 'complete');
            $workers[0]->start();
            $this->waitForFinalizerLock($workers[0], $markers[0]);
            $call = DB::table('ai_calls')->where('object_id', $claim->conversationId)->where('purpose', 'responder')->first();
            self::assertNotNull($call);
            self::assertSame('reserved', $call->status);
            self::assertNotNull($call->result_encrypted);
            self::assertNotNull(DB::table('c02_responder_stages')->where('id', $stage)->value('binding_seal'));
            $workers[0]->stop(1);
            self::assertFalse($workers[0]->isRunning());
            $blocker->rollBack();
            unlink($file); // Re-executing the fake driver would fail.

            $this->assertSavedResponderSettlementDenials($tenant, $http, $claim, $stage, $call);
            $blocker->beginTransaction();
            $blocker->select('SELECT pg_advisory_xact_lock(8620494)');
            $workers[] = $make($markers[1], 'recover');
            $workers[] = $make($markers[2], 'recover');
            $workers[1]->start();
            $this->waitForFinalizerLock($workers[1], $markers[1]);
            $workers[2]->start();
            $this->waitForFinalizerLock($workers[2], $markers[2]);
            $blocker->rollBack();
            foreach ([$workers[1], $workers[2]] as $worker) {
                $worker->wait();
                self::assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
                self::assertSame('completed', json_decode($worker->getOutput(), true, 512, JSON_THROW_ON_ERROR)['status']);
            }
            $settled = DB::table('ai_calls')->where('id', $call->id)->first();
            $budget = DB::table('ai_budgets')->where('id', $call->budget_id)->first();
            self::assertSame('completed', $settled->status);
            self::assertSame(7, (int) $settled->cost_micros);
            self::assertSame(7, (int) $budget->spent_micros);
            self::assertSame(0, (int) $budget->unresolved_count);
            self::assertSame(7, (int) DB::table('object_costs')->where('object_id', $claim->conversationId)->value('cost_micros'));
            self::assertSame(1, DB::table('ai_calls')->where('object_id', $claim->conversationId)->where('purpose', 'responder')->count());
            self::assertSame(['status' => 'tool_calls', 'text' => null], $this->within($tenant,
                fn () => DB::transaction(fn () => app(Gateway::class)->readProtectedResponderLocked($http, $claim, $stage))));
            self::assertSame(0, DB::table('seller_tasks')->count());
            self::assertSame(0, DB::table('outbox_events')->count());
            self::assertSame(1, DB::table('messages')->where('conversation_id', $claim->conversationId)->count());
        } finally {
            if ($blocker->transactionLevel() > 0) {
                $blocker->rollBack();
            }
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            foreach ($markers as $marker) {
                if (is_file($marker)) {
                    unlink($marker);
                }
            }
            if (is_file($file)) {
                unlink($file);
            }
            DB::purge('c02_responder_settle_blocker');
            DB::unprepared('DROP TRIGGER IF EXISTS c02_test_delay_responder_settlement ON ai_budgets; DROP FUNCTION IF EXISTS c02_test_delay_responder_settlement();');
        }
        Http::assertNothingSent();
        Mail::assertNothingOutgoing();
        Queue::assertNothingPushed();
    }

    public function test_live_fake_responder_losing_settlement_race_keeps_the_completed_result(): void
    {
        [$tenant, $http, $claim, $extractor] = $this->fixture();
        [$stage, , , $file] = $this->responderFixture($tenant, $http, $claim, $extractor, ['costMicros' => 9]);
        $prefix = base_path('.local/tmp/c02-live-responder-'.Str::random(16));
        $saved = $prefix.'-saved';
        $resume = $prefix.'-resume';
        $pid = $prefix.'-pid';
        $worker = new Process([PHP_BINARY, '-d', 'sys_temp_dir='.base_path('.local/tmp'), '-d', 'allow_url_fopen=0',
            '-d', 'disable_functions=fsockopen,pfsockopen,stream_socket_client,curl_exec,curl_multi_exec',
            base_path('tests/Feature/ProtectedOperations/Fixtures/responder-settlement-worker.php')], base_path());
        $worker->setInput(json_encode(['org' => $tenant->organizationId, 'brand' => $tenant->brandId, 'user' => $tenant->actorId,
            'member' => $tenant->membershipId, 'session' => $http->session()->getId(), 'turn' => $claim->turnId,
            'conversation' => $claim->conversationId, 'version' => $claim->baseVersion, 'token' => $claim->token(),
            'stage' => $stage, 'fixtures' => dirname($file), 'mode' => 'complete-race', 'pid_marker' => $pid,
            'saved_marker' => $saved, 'resume_marker' => $resume], JSON_THROW_ON_ERROR));
        $worker->setTimeout(60);
        try {
            $worker->start();
            $deadline = microtime(true) + 30;
            while (! is_file($saved) && $worker->isRunning() && microtime(true) < $deadline) {
                usleep(10000);
            }
            self::assertFileExists($saved);
            self::assertTrue($worker->isRunning());
            $call = DB::table('ai_calls')->where('object_id', $claim->conversationId)->where('purpose', 'responder')->first();
            self::assertNotNull($call);
            self::assertSame('reserved', $call->status);
            self::assertNotNull($call->result_encrypted);
            unlink($file);
            self::assertSame('completed', $this->within($tenant, fn () => app(Gateway::class)->settleSavedFakeResponder($http, $claim, $stage)));
            file_put_contents($resume, 'resume');
            $worker->wait();
            self::assertFalse($worker->isSuccessful());
            self::assertSame('Protected responder settlement worker failed.', $worker->getErrorOutput());
            self::assertSame('completed', DB::table('ai_calls')->where('id', $call->id)->value('status'));
            $before = DB::table('ai_budgets')->where('id', $call->budget_id)->first();
            self::assertSame(9, (int) $before->spent_micros);
            self::assertSame(0, (int) $before->unresolved_count);
            self::assertSame('completed', $this->within($tenant, fn () => app(Gateway::class)->completeProtectedResponder($http, $claim, $stage)));
            self::assertSame('completed', $this->within($tenant, fn () => app(Gateway::class)->settleSavedFakeResponder($http, $claim, $stage)));
            self::assertEquals($before, DB::table('ai_budgets')->where('id', $call->budget_id)->first());
            self::assertSame(9, (int) DB::table('object_costs')->where('object_id', $claim->conversationId)->value('cost_micros'));
            self::assertSame(1, DB::table('ai_calls')->where('object_id', $claim->conversationId)->where('purpose', 'responder')->count());
        } finally {
            if ($worker->isRunning()) {
                $worker->stop();
            }
            foreach ([$saved, $resume, $pid, $file] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
        Http::assertNothingSent();
        Mail::assertNothingOutgoing();
        Queue::assertNothingPushed();
    }

    private function assertSavedResponderSettlementDenials(TenantContext $tenant, Request $http, TurnClaim $claim, string $stage, object $call): void
    {
        $budget = DB::table('ai_budgets')->where('id', $call->budget_id)->first();
        $deny = function (?TurnClaim $candidate = null, ?string $candidateStage = null) use ($tenant, $http, $claim, $stage): void {
            try {
                $this->within($tenant, fn () => app(Gateway::class)->settleSavedFakeResponder($http, $candidate ?? $claim, $candidateStage ?? $stage));
                self::fail('Unverified responder settlement was accepted.');
            } catch (LogicException $error) {
                self::assertSame('Saved fake responder result cannot be settled.', $error->getMessage());
            }
        };
        $stored = json_decode(Crypt::decryptString($call->result_encrypted), true, 512, JSON_THROW_ON_ERROR);
        foreach ([null, 'invalid-ciphertext', Crypt::encryptString(json_encode([...$stored, 'aiCallId' => (string) Str::ulid()], JSON_THROW_ON_ERROR)),
            Crypt::encryptString(json_encode([...$stored, 'costMicros' => null], JSON_THROW_ON_ERROR))] as $invalid) {
            DB::table('ai_calls')->where('id', $call->id)->update(['result_encrypted' => $invalid]);
            $deny();
            DB::table('ai_calls')->where('id', $call->id)->update(['result_encrypted' => $call->result_encrypted]);
            self::assertEquals($budget, DB::table('ai_budgets')->where('id', $call->budget_id)->first());
        }
        $row = DB::table('c02_responder_stages')->where('id', $stage)->first();
        try {
            DB::statement('ALTER TABLE c02_responder_stages DISABLE TRIGGER c02_responder_stage_guard');
            DB::statement('ALTER TABLE c02_responder_stages DISABLE TRIGGER c02_responder_binding_seal_guard');
            DB::table('c02_responder_stages')->where('id', $stage)->update(['binding_seal' => null]);
            DB::statement('ALTER TABLE c02_responder_stages ENABLE TRIGGER c02_responder_binding_seal_guard');
            DB::statement('ALTER TABLE c02_responder_stages ENABLE TRIGGER c02_responder_stage_guard');
            $deny();
        } finally {
            DB::statement('ALTER TABLE c02_responder_stages DISABLE TRIGGER c02_responder_stage_guard');
            DB::statement('ALTER TABLE c02_responder_stages DISABLE TRIGGER c02_responder_binding_seal_guard');
            DB::table('c02_responder_stages')->where('id', $stage)->update(['binding_seal' => $row->binding_seal]);
            DB::statement('ALTER TABLE c02_responder_stages ENABLE TRIGGER c02_responder_binding_seal_guard');
            DB::statement('ALTER TABLE c02_responder_stages ENABLE TRIGGER c02_responder_stage_guard');
        }
        $deny(new TurnClaim('claimed', $claim->turnId, $claim->conversationId, $claim->organizationId, $claim->brandId, $claim->baseVersion, str_repeat('a', 64)));
        $deny(new TurnClaim('claimed', $claim->turnId, $claim->conversationId, $claim->organizationId, (string) Str::ulid(), $claim->baseVersion, $claim->token()));
        $deny(candidateStage: (string) Str::ulid());
        DB::table('ai_calls')->where('id', $call->id)->update(['request_hash' => str_repeat('a', 64)]);
        $deny();
        DB::table('ai_calls')->where('id', $call->id)->update(['request_hash' => $call->request_hash]);
        DB::table('ai_calls')->where('id', $call->id)->update(['status' => 'uncertain', 'error_code' => 'provider_uncertain']);
        $deny();
        DB::table('ai_calls')->where('id', $call->id)->update(['status' => 'reserved', 'error_code' => null]);
        DB::table('ai_budgets')->where('id', $call->budget_id)->update(['unresolved_count' => 0]);
        $deny();
        DB::table('ai_budgets')->where('id', $call->budget_id)->update(['unresolved_count' => $budget->unresolved_count]);
        config(['runtime.ai_driver' => 'record']);
        $deny();
        config(['runtime.ai_driver' => 'fake']);
        $this->within($tenant, function () use ($tenant, $http, $claim, $stage): void {
            DB::table('memberships')->where('id', $tenant->membershipId)->update(['role' => 'viewer']);
            try {
                app(Gateway::class)->settleSavedFakeResponder($http, $claim, $stage);
                self::fail('Revoked responder permission was accepted.');
            } catch (LogicException $error) {
                self::assertSame('Saved fake responder result cannot be settled.', $error->getMessage());
            } finally {
                DB::table('memberships')->where('id', $tenant->membershipId)->update(['role' => 'owner']);
            }
        });
        DB::table('auth_session_registry')->where('user_id', $tenant->actorId)->update(['revoked_at' => now()]);
        $deny();
        DB::table('auth_session_registry')->where('user_id', $tenant->actorId)->update(['revoked_at' => null]);
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION c02_test_reject_responder_settlement() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF OLD.status = 'reserved' AND NEW.status = 'completed' AND NEW.purpose = 'responder' THEN
    RAISE EXCEPTION 'synthetic accounting failure';
  END IF;
  RETURN NEW;
END;
$$;
CREATE TRIGGER c02_test_reject_responder_settlement BEFORE UPDATE ON ai_calls
  FOR EACH ROW EXECUTE FUNCTION c02_test_reject_responder_settlement();
SQL);
        try {
            $deny();
            self::assertEquals($call, DB::table('ai_calls')->where('id', $call->id)->first());
            self::assertEquals($budget, DB::table('ai_budgets')->where('id', $call->budget_id)->first());
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS c02_test_reject_responder_settlement ON ai_calls; DROP FUNCTION IF EXISTS c02_test_reject_responder_settlement();');
        }
        self::assertEquals($call, DB::table('ai_calls')->where('id', $call->id)->first());
        self::assertEquals($budget, DB::table('ai_budgets')->where('id', $call->budget_id)->first());
    }

    public function test_responder_binding_seal_rejects_privileged_replacement_with_another_valid_result(): void
    {
        [$tenant, $http, $claim, $extractor] = $this->fixture();
        [$stage, $request, $context, $file] = $this->responderFixture($tenant, $http, $claim, $extractor, ['content' => 'Original synthetic answer']);
        $this->within($tenant, function () use ($http, $claim, $stage, $request, $context, $file): void {
            self::assertSame('completed', app(Gateway::class)->completeProtectedResponder($http, $claim, $stage));
            $originalCall = DB::table('c02_responder_stages')->where('id', $stage)->value('ai_call_id');
            $originalKey = DB::table('ai_calls')->where('id', $originalCall)->value('idempotency_key_hash');
            self::assertNotNull(DB::table('c02_responder_stages')->where('id', $stage)->value('binding_seal'));
            file_put_contents($file, json_encode(['result' => ['content' => 'Substituted synthetic answer', 'toolCalls' => [],
                'usage' => ['promptTokens' => 1, 'completionTokens' => 1], 'model' => $request->model, 'latencyMs' => 1, 'costMicros' => 0]], JSON_THROW_ON_ERROR));
            $other = new AiContext($context->organizationId, $context->brandId, $context->purpose, $context->family,
                $context->objectType, $context->objectId, 'synthetic-other-'.Str::random(20), $context->dataClasses);
            $otherResult = app(Gateway::class)->complete($request, $other);
            self::assertNotSame($originalCall, $otherResult->aiCallId);
            self::assertSame(DB::table('ai_calls')->where('id', $originalCall)->value('request_hash'), DB::table('ai_calls')->where('id', $otherResult->aiCallId)->value('request_hash'));
            DB::beginTransaction();
            try {
                DB::statement('ALTER TABLE c02_responder_stages DISABLE TRIGGER c02_responder_stage_guard');
                DB::table('ai_calls')->where('id', $originalCall)->update(['idempotency_key_hash' => str_repeat('b', 64)]);
                DB::table('ai_calls')->where('id', $otherResult->aiCallId)->update(['idempotency_key_hash' => $originalKey]);
                DB::table('c02_responder_stages')->where('id', $stage)->update(['ai_call_id' => $otherResult->aiCallId]);
                DB::statement('ALTER TABLE c02_responder_stages ENABLE TRIGGER c02_responder_stage_guard');
                try {
                    app(Gateway::class)->readProtectedResponderLocked($http, $claim, $stage);
                    self::fail('An authenticated result of a different call replaced the bound responder.');
                } catch (LogicException $error) {
                    self::assertSame('Protected responder result unavailable.', $error->getMessage());
                }
                foreach ([null, str_repeat('a', 64)] as $forgedSeal) {
                    DB::statement('ALTER TABLE c02_responder_stages DISABLE TRIGGER c02_responder_stage_guard');
                    DB::statement('ALTER TABLE c02_responder_stages DISABLE TRIGGER c02_responder_binding_seal_guard');
                    DB::table('c02_responder_stages')->where('id', $stage)->update(['binding_seal' => $forgedSeal]);
                    DB::statement('ALTER TABLE c02_responder_stages ENABLE TRIGGER c02_responder_binding_seal_guard');
                    DB::statement('ALTER TABLE c02_responder_stages ENABLE TRIGGER c02_responder_stage_guard');
                    try {
                        app(Gateway::class)->readProtectedResponderLocked($http, $claim, $stage);
                        self::fail('A missing or forged responder binding seal was accepted.');
                    } catch (LogicException $error) {
                        self::assertSame('Protected responder result unavailable.', $error->getMessage());
                    }
                }
            } finally {
                DB::rollBack();
                unlink($file);
            }
        });
    }

    public function test_responder_binding_migration_preserves_unsigned_legacy_history_without_trusting_it(): void
    {
        [$tenant, $http, $claim, $extractor] = $this->fixture();
        [$stage, , , $file] = $this->responderFixture($tenant, $http, $claim, $extractor);
        $this->within($tenant, fn () => app(Gateway::class)->completeProtectedResponder($http, $claim, $stage));
        unlink($file);
        $migration = require database_path('migrations/C-02/2026_11_01_110000_c02_seal_responder_binding.php');
        try {
            $migration->down();
            self::fail('A signed responder binding was silently discarded.');
        } catch (\RuntimeException $error) {
            self::assertSame('Cannot discard responder binding seals.', $error->getMessage());
        }
        // Recreate the pre-migration representation only in this isolated DB.
        DB::transaction(function () use ($stage): void {
            DB::statement('ALTER TABLE c02_responder_stages DISABLE TRIGGER c02_responder_stage_guard');
            DB::statement('ALTER TABLE c02_responder_stages DISABLE TRIGGER c02_responder_binding_seal_guard');
            DB::table('c02_responder_stages')->where('id', $stage)->update(['binding_seal' => null]);
            DB::statement('ALTER TABLE c02_responder_stages ENABLE TRIGGER c02_responder_binding_seal_guard');
            DB::statement('ALTER TABLE c02_responder_stages ENABLE TRIGGER c02_responder_stage_guard');
        });
        $migration->down();
        $old = (array) DB::table('c02_responder_stages')->where('id', $stage)->first();
        $oldCall = DB::table('ai_calls')->where('id', $old['ai_call_id'])->first();
        [$otherTenant, $otherHttp, $otherClaim, $otherExtractor] = $this->fixture('Legacy unbound synthetic input');
        $otherStage = $this->within($otherTenant, function () use ($otherHttp, $otherClaim, $otherExtractor): string {
            $state = app(FirstTurnState::class)->prepare($otherHttp, $otherClaim, $otherExtractor);

            return app(SellerResponderStages::class)->prepare($otherHttp, $otherClaim, $otherExtractor, $state);
        });
        self::assertNull(DB::table('c02_responder_stages')->where('id', $otherStage)->value('ai_call_id'));
        $migration->up();
        $migrated = (array) DB::table('c02_responder_stages')->where('id', $stage)->first();
        self::assertArrayHasKey('binding_seal', $migrated);
        self::assertNull($migrated['binding_seal']);
        unset($migrated['binding_seal']);
        self::assertEquals($old, $migrated);
        self::assertEquals($oldCall, DB::table('ai_calls')->where('id', $old['ai_call_id'])->first());
        $this->within($tenant, function () use ($http, $claim, $stage): void {
            try {
                app(SellerResponderStages::class)->load($http, $claim, $stage);
                self::fail('An unsigned legacy binding was treated as verified.');
            } catch (LogicException $error) {
                self::assertSame('Protected responder stage rejected.', $error->getMessage());
            }
            try {
                app(Gateway::class)->completeProtectedResponder($http, $claim, $stage);
                self::fail('An unsigned legacy responder was replayed as verified.');
            } catch (LogicException $error) {
                self::assertSame('Protected responder stage could not be completed.', $error->getMessage());
            }
        });
        [$reused, , , $otherFile] = $this->responderFixture($otherTenant, $otherHttp, $otherClaim, $otherExtractor);
        self::assertSame($otherStage, $reused);
        self::assertSame('completed', $this->within($otherTenant, fn () => app(Gateway::class)->completeProtectedResponder($otherHttp, $otherClaim, $otherStage)));
        self::assertNotNull(DB::table('c02_responder_stages')->where('id', $otherStage)->value('binding_seal'));
        unlink($otherFile);
        Http::assertNothingSent();
        Mail::assertNothingOutgoing();
        Queue::assertNothingPushed();
    }

    public function test_cleared_responder_binding_cannot_reseal_an_old_call(): void
    {
        [$tenant, $http, $claim, $extractor] = $this->fixture();
        [$stage, $request, $context, $file] = $this->responderFixture($tenant, $http, $claim, $extractor);
        $this->within($tenant, function () use ($http, $claim, $stage, $request, $context, $file): void {
            self::assertSame('completed', app(Gateway::class)->completeProtectedResponder($http, $claim, $stage));
            $original = DB::table('c02_responder_stages')->where('id', $stage)->first();
            $originalKey = DB::table('ai_calls')->where('id', $original->ai_call_id)->value('idempotency_key_hash');
            $other = new AiContext($context->organizationId, $context->brandId, $context->purpose, $context->family,
                $context->objectType, $context->objectId, 'synthetic-rebind-'.Str::random(20), $context->dataClasses);
            $otherResult = app(Gateway::class)->complete($request, $other);
            $otherKey = DB::table('ai_calls')->where('id', $otherResult->aiCallId)->value('idempotency_key_hash');
            DB::transaction(function () use ($stage, $original, $originalKey, $otherResult): void {
                DB::statement('ALTER TABLE c02_responder_stages DISABLE TRIGGER c02_responder_stage_guard');
                DB::statement('ALTER TABLE c02_responder_stages DISABLE TRIGGER c02_responder_binding_seal_guard');
                DB::table('c02_responder_stages')->where('id', $stage)->update(['ai_call_id' => null, 'gateway_request_hash' => null, 'binding_seal' => null]);
                DB::table('ai_calls')->where('id', $original->ai_call_id)->update(['idempotency_key_hash' => str_repeat('b', 64)]);
                DB::table('ai_calls')->where('id', $otherResult->aiCallId)->update(['idempotency_key_hash' => $originalKey]);
                DB::statement('ALTER TABLE c02_responder_stages ENABLE TRIGGER c02_responder_binding_seal_guard');
                // The original stage trigger stays disabled to exercise the PHP
                // XID/nonce check independently of that trigger.
            });
            try {
                try {
                    app(Gateway::class)->completeProtectedResponder($http, $claim, $stage);
                    self::fail('An old AI call was sealed as a new binding.');
                } catch (LogicException $error) {
                    self::assertSame('Protected responder stage could not be completed.', $error->getMessage());
                }
                self::assertNull(DB::table('c02_responder_stages')->where('id', $stage)->value('binding_seal'));
                self::assertSame(2, DB::table('ai_calls')->where('purpose', 'responder')->count());
            } finally {
                DB::transaction(function () use ($stage, $original, $originalKey, $otherResult, $otherKey): void {
                    DB::statement('ALTER TABLE c02_responder_stages DISABLE TRIGGER c02_responder_binding_seal_guard');
                    DB::table('ai_calls')->where('id', $otherResult->aiCallId)->update(['idempotency_key_hash' => $otherKey]);
                    DB::table('ai_calls')->where('id', $original->ai_call_id)->update(['idempotency_key_hash' => $originalKey]);
                    DB::table('c02_responder_stages')->where('id', $stage)->update(['ai_call_id' => $original->ai_call_id,
                        'gateway_request_hash' => $original->gateway_request_hash, 'binding_seal' => $original->binding_seal]);
                    DB::statement('ALTER TABLE c02_responder_stages ENABLE TRIGGER c02_responder_binding_seal_guard');
                    DB::statement('ALTER TABLE c02_responder_stages ENABLE TRIGGER c02_responder_stage_guard');
                });
                unlink($file);
            }
            self::assertSame('completed', app(Gateway::class)->completeProtectedResponder($http, $claim, $stage));
        });
        Http::assertNothingSent();
    }

    public function test_new_responder_binding_cannot_commit_without_its_seal(): void
    {
        [$tenant, $http, $claim, $extractor] = $this->fixture();
        [$stage, , , $file] = $this->responderFixture($tenant, $http, $claim, $extractor);
        $fake = new CoordinatorScriptedFakeDriver([]);
        app()->instance(FakeDriver::class, $fake);
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION c02_test_drop_responder_binding_seal() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN NEW.binding_seal := NULL; RETURN NEW; END;
$$;
CREATE TRIGGER a_c02_test_drop_responder_binding_seal BEFORE UPDATE ON c02_responder_stages
  FOR EACH ROW EXECUTE FUNCTION c02_test_drop_responder_binding_seal();
SQL);
        try {
            $this->within($tenant, function () use ($http, $claim, $stage): void {
                try {
                    app(Gateway::class)->completeProtectedResponder($http, $claim, $stage);
                    self::fail('A responder call was bound without its seal.');
                } catch (LogicException $error) {
                    self::assertSame('Protected responder stage could not be completed.', $error->getMessage());
                }
            });
            self::assertSame(0, $fake->calls);
            self::assertSame(0, DB::table('ai_calls')->where('purpose', 'responder')->count());
            self::assertNull(DB::table('c02_responder_stages')->where('id', $stage)->value('ai_call_id'));
            self::assertNull(DB::table('c02_responder_stages')->where('id', $stage)->value('binding_seal'));
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS a_c02_test_drop_responder_binding_seal ON c02_responder_stages; DROP FUNCTION IF EXISTS c02_test_drop_responder_binding_seal();');
            unlink($file);
        }
        Http::assertNothingSent();
    }

    public function test_responder_projection_matches_all_original_prompt_goldens(): void
    {
        [$tenant] = $this->fixture();
        $this->within($tenant, function () use ($tenant): void {
            $knowledge = app(KnowledgeRepository::class)->published($tenant);
            $states = json_decode(file_get_contents(base_path('tests/fixtures/prototype/prompts/states.json')), true, 512, JSON_THROW_ON_ERROR);
            $extraction = ['slot_updates' => [], 'client_intent' => 'answer_only', 'client_questions' => [], 'objections' => [], 'sentiment' => 'neutral', 'wants_only_prices' => false, 'asked_if_bot' => false, 'wants_human' => false, 'do_not_disturb' => false, 'off_topic' => false, 'prompt_injection' => false, 'closed_topic' => null, 'unknown_question' => null, 'client_claims' => [], 'refused_slots' => [], 'confidence' => 0.9];
            foreach ($states as $name => $data) {
                $state = new LeadState($data);
                $goal = (new Policy($knowledge))->decide($state, $extraction, $name === 'opening');
                $actual = app(ResponderProjection::class)->render($knowledge, $state, $goal, 'Антон');
                $expected = file_get_contents(base_path('tests/fixtures/prototype/prompts/'.$name.'/responder.txt'));
                self::assertIsString($expected);
                self::assertSame(str_replace("\r\n", "\n", $expected), str_replace("\r\n", "\n", $actual), $name);
            }
        });
        Http::assertNothingSent();
    }

    public function test_responder_stage_binds_server_request_and_candidate_to_the_first_turn(): void
    {
        [$tenant, $http, $claim, $extractor] = $this->fixture('Synthetic question from synthetic.user@example.test');
        [$stage, $request, $context, $file] = $this->responderFixture($tenant, $http, $claim, $extractor);
        $this->within($tenant, function () use ($tenant, $http, $claim, $extractor, $stage, $request, $context, $file): void {
            $source = app(SellerResponderStages::class);
            $state = DB::table('c02_first_turn_states')->where('turn_id', $claim->turnId)->value('id');
            self::assertSame($stage, $source->prepare($http, $claim, $extractor, $state));
            self::assertSame('responder', $context->purpose);
            self::assertSame('user', $request->messages[1]['role']);
            self::assertSame('Synthetic question from synthetic.user@example.test', $request->messages[1]['content']);
            self::assertStringContainsString('ЦЕЛЬ ХОДА: ask_slot', $request->messages[0]['content']);
            self::assertStringNotContainsString('ЦЕЛЬ ХОДА: intro_and_ask', $request->messages[0]['content']);
            $stored = DB::table('c02_responder_stages')->where('id', $stage)->first();
            self::assertStringNotContainsString('synthetic.user@example.test', json_encode($stored, JSON_THROW_ON_ERROR));
            try {
                app(Gateway::class)->complete($request, $context);
                self::fail('Public Gateway accepted a protected prepared request.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
            self::assertSame('completed', app(Gateway::class)->completeProtectedResponder($http, $claim, $stage));
            self::assertSame('completed', app(Gateway::class)->completeProtectedResponder($http, $claim, $stage));
            try {
                app(Gateway::class)->readProtectedResponderLocked($http, $claim, $stage);
                self::fail('Responder candidate was returned outside a transaction.');
            } catch (LogicException $error) {
                self::assertSame('Protected responder result unavailable.', $error->getMessage());
            }
            $read = DB::transaction(fn () => app(Gateway::class)->readProtectedResponderLocked($http, $claim, $stage));
            self::assertSame(['status' => 'candidate', 'text' => 'Тестовый ответ'], $read);
            try {
                app(Gateway::class)->complete($request, $context);
                self::fail('Public Gateway replayed a protected call.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
            DB::table('brand_modules')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)
                ->where('module', 'seller')->update(['enabled' => false]);
            try {
                DB::transaction(fn () => app(Gateway::class)->readProtectedResponderLocked($http, $claim, $stage));
                self::fail('A candidate was read after access was revoked.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
            DB::table('brand_modules')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)
                ->where('module', 'seller')->update(['enabled' => true]);
            app(AuthSessionRegistry::class)->withinActive($http, fn () => app(AuthSessionRegistry::class)->revokeCurrent($http));
            try {
                DB::transaction(fn () => app(Gateway::class)->readProtectedResponderLocked($http, $claim, $stage));
                self::fail('A candidate was read after the session was revoked.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
            self::assertSame(2, DB::table('ai_calls')->where('organization_id', $tenant->organizationId)->count());
            self::assertSame(0, DB::table('seller_tasks')->count());
            self::assertSame(0, DB::table('outbox_events')->count());
            self::assertSame([], json_decode(DB::table('conversations')->where('id', $claim->conversationId)->value('state'), true, 512, JSON_THROW_ON_ERROR));
            unlink($file);
        });
        Http::assertNothingSent();
        Mail::assertNothingOutgoing();
        Queue::assertNothingPushed();
    }

    public function test_responder_unknown_cost_and_tool_calls_never_create_effects(): void
    {
        [$tenant, $http, $claim, $extractor] = $this->fixture('Unknown cost');
        [$stage, , , $file] = $this->responderFixture($tenant, $http, $claim, $extractor, ['costMicros' => null]);
        $this->within($tenant, function () use ($http, $claim, $stage, $file): void {
            self::assertSame('uncertain', app(Gateway::class)->completeProtectedResponder($http, $claim, $stage));
            self::assertSame('uncertain', app(Gateway::class)->completeProtectedResponder($http, $claim, $stage));
            try {
                DB::transaction(fn () => app(Gateway::class)->readProtectedResponderLocked($http, $claim, $stage));
                self::fail('Unknown cost produced a candidate.');
            } catch (LogicException $error) {
                self::assertSame('Protected responder result unavailable.', $error->getMessage());
            }
            unlink($file);
        });
        [$otherTenant, $otherHttp, $otherClaim, $otherExtractor] = $this->fixture('Tool call');
        [$otherStage, , , $otherFile] = $this->responderFixture($otherTenant, $otherHttp, $otherClaim, $otherExtractor, [
            'content' => 'Ignored text', 'toolCalls' => [['id' => 'synthetic-call', 'name' => 'send_price', 'arguments' => ['email' => 'synthetic.user@example.test']]],
        ]);
        $this->within($otherTenant, function () use ($otherHttp, $otherClaim, $otherStage, $otherFile): void {
            self::assertSame('completed', app(Gateway::class)->completeProtectedResponder($otherHttp, $otherClaim, $otherStage));
            $result = DB::transaction(fn () => app(Gateway::class)->readProtectedResponderLocked($otherHttp, $otherClaim, $otherStage));
            self::assertSame(['status' => 'tool_calls', 'text' => null], $result);
            unlink($otherFile);
        });
        self::assertSame(4, DB::table('ai_calls')->count());
        self::assertSame(0, DB::table('seller_tasks')->count());
        self::assertSame(0, DB::table('outbox_events')->count());
        Http::assertNothingSent();
        Mail::assertNothingOutgoing();
        Queue::assertNothingPushed();
    }

    public function test_responder_rejects_forgery_revocation_and_new_input_without_an_effect(): void
    {
        [$tenant, $http, $claim, $extractor] = $this->fixture('Synthetic first input');
        [$stage, , , $file, $stateId] = $this->responderFixture($tenant, $http, $claim, $extractor);
        [$otherTenant, $otherHttp, $otherClaim, $otherExtractor] = $this->fixture('Other synthetic input');
        [$otherStage, , , $otherFile, $otherStateId] = $this->responderFixture($otherTenant, $otherHttp, $otherClaim, $otherExtractor);
        $this->within($tenant, function () use ($tenant, $http, $claim, $extractor, $stage, $otherStage, $otherStateId): void {
            $forged = new TurnClaim('claimed', $claim->turnId, $claim->conversationId, $claim->organizationId, $claim->brandId, $claim->baseVersion, str_repeat('a', 64));
            foreach ([fn () => app(SellerResponderStages::class)->load($http, $forged, $stage),
                fn () => app(SellerResponderStages::class)->load($http, $claim, $otherStage),
                fn () => app(SellerResponderStages::class)->prepare($http, $claim, $extractor, $otherStateId)] as $operation) {
                try {
                    $operation();
                    self::fail('Forged responder source was accepted.');
                } catch (LogicException $error) {
                    self::assertSame('Protected responder stage rejected.', $error->getMessage());
                    self::assertNull($error->getPrevious());
                    $trace = json_encode($error->getTrace(), JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_THROW_ON_ERROR);
                    self::assertStringNotContainsString($http->session()->getId(), $trace);
                }
            }
            config(['runtime.ai_driver' => 'record']);
            try {
                app(Gateway::class)->completeProtectedResponder($http, $claim, $stage);
                self::fail('Non-fake driver was accepted.');
            } catch (LogicException $error) {
                self::assertSame('Protected responder stage could not be completed.', $error->getMessage());
            } finally {
                config(['runtime.ai_driver' => 'fake']);
            }
            $release = DB::table('brands')->where('id', $tenant->brandId)->value('active_knowledge_release_id');
            DB::table('brands')->where('id', $tenant->brandId)->update(['active_knowledge_release_id' => null]);
            try {
                app(Gateway::class)->completeProtectedResponder($http, $claim, $stage);
                self::fail('Inactive knowledge release was accepted.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
            DB::table('brands')->where('id', $tenant->brandId)->update(['active_knowledge_release_id' => $release]);
            Message::create(['conversation_id' => $claim->conversationId, 'role' => 'client', 'text' => 'Later synthetic input', 'meta' => []]);
            try {
                app(Gateway::class)->completeProtectedResponder($http, $claim, $stage);
                self::fail('Stale responder request was sent.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
            self::assertSame('uncertain', DB::table('seller_turns')->where('id', $claim->turnId)->value('status'));
            self::assertSame(2, DB::table('messages')->where('conversation_id', $claim->conversationId)->count());
        });
        $this->within($otherTenant, function () use ($otherHttp, $otherClaim, $otherStage): void {
            app(AuthSessionRegistry::class)->withinActive($otherHttp, fn () => app(AuthSessionRegistry::class)->revokeCurrent($otherHttp));
            try {
                app(Gateway::class)->completeProtectedResponder($otherHttp, $otherClaim, $otherStage);
                self::fail('Revoked session started a responder call.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
        });
        self::assertSame(2, DB::table('ai_calls')->count());
        self::assertSame(0, DB::table('seller_tasks')->count());
        self::assertSame(0, DB::table('outbox_events')->count());
        unlink($file);
        unlink($otherFile);
        Http::assertNothingSent();
    }

    public function test_responder_history_tampering_and_concurrent_input_are_rejected(): void
    {
        [$tenant, $http, $claim, $extractor] = $this->fixture();
        [$stage, , , $file] = $this->responderFixture($tenant, $http, $claim, $extractor);
        $this->within($tenant, function () use ($tenant, $http, $claim, $extractor, $stage, $file): void {
            $oldCall = DB::table('c02_extractor_stages')->where('id', $extractor)->value('ai_call_id');
            $forged = (array) DB::table('c02_responder_stages')->where('id', $stage)->first();
            $forged['id'] = (string) Str::ulid();
            $forged['first_state_id'] = (string) Str::ulid();
            try {
                DB::transaction(fn () => DB::table('c02_responder_stages')->insert($forged));
                self::fail('A responder stage with no matching state was inserted.');
            } catch (QueryException $error) {
                self::assertSame('23514', $error->errorInfo[0]);
            }
            foreach ([fn () => DB::table('c02_responder_stages')->where('id', $stage)->update(['request_encrypted' => 'forged']),
                fn () => DB::table('c02_responder_stages')->where('id', $stage)->update(['ai_call_id' => $oldCall, 'gateway_request_hash' => str_repeat('a', 64)]),
                fn () => DB::table('c02_responder_stages')->where('id', $stage)->delete(),
                fn () => DB::statement('TRUNCATE TABLE c02_responder_stages')] as $operation) {
                try {
                    DB::transaction($operation);
                    self::fail('Protected responder history changed.');
                } catch (QueryException $error) {
                    self::assertSame('23514', $error->errorInfo[0]);
                }
            }
            self::assertSame('completed', app(Gateway::class)->completeProtectedResponder($http, $claim, $stage));
            $call = DB::table('c02_responder_stages')->where('id', $stage)->value('ai_call_id');
            $original = DB::table('ai_calls')->where('id', $call)->value('result_encrypted');
            $wrong = DB::table('ai_calls')->where('id', $oldCall)->value('result_encrypted');
            DB::table('ai_calls')->where('id', $call)->update(['result_encrypted' => $wrong]);
            try {
                DB::transaction(fn () => app(Gateway::class)->readProtectedResponderLocked($http, $claim, $stage));
                self::fail('A different stored result was accepted.');
            } catch (LogicException $error) {
                self::assertSame('Protected responder result unavailable.', $error->getMessage());
            }
            DB::table('ai_calls')->where('id', $call)->update(['result_encrypted' => $original]);
            DB::table('prompt_sets')->whereNull('organization_id')->where('name', 'responder')->update(['status' => 'draft']);
            try {
                DB::transaction(fn () => app(Gateway::class)->readProtectedResponderLocked($http, $claim, $stage));
                self::fail('An unpublished prompt was accepted.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
            DB::table('prompt_sets')->whereNull('organization_id')->where('name', 'responder')->update(['status' => 'published']);
            config(['database.connections.c02_responder_competitor' => config('database.connections.pgsql')]);
            $other = DB::connection('c02_responder_competitor');
            DB::transaction(function () use ($tenant, $http, $claim, $stage, $other): void {
                self::assertSame('candidate', app(Gateway::class)->readProtectedResponderLocked($http, $claim, $stage)['status']);
                $other->beginTransaction();
                try {
                    $other->statement("SET LOCAL lock_timeout = '250ms'");
                    $other->table('messages')->insert(['id' => (string) Str::ulid(), 'organization_id' => $tenant->organizationId,
                        'brand_id' => $tenant->brandId, 'conversation_id' => $claim->conversationId,
                        'role' => 'client', 'text' => 'Concurrent synthetic input', 'meta' => '{}']);
                    self::fail('Input crossed the responder read transaction.');
                } catch (QueryException $error) {
                    self::assertSame('55P03', $error->errorInfo[0]);
                } finally {
                    $other->rollBack();
                }
            });
            DB::purge('c02_responder_competitor');
            Message::create(['conversation_id' => $claim->conversationId, 'role' => 'client', 'text' => 'Later synthetic input', 'meta' => []]);
            try {
                DB::transaction(fn () => app(Gateway::class)->readProtectedResponderLocked($http, $claim, $stage));
                self::fail('Stale responder result remained accessible.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
            self::assertSame('uncertain', DB::table('seller_turns')->where('id', $claim->turnId)->value('status'));
            self::assertSame(2, DB::table('messages')->where('conversation_id', $claim->conversationId)->count());
            self::assertSame(2, DB::table('ai_calls')->count());
            self::assertSame(0, DB::table('seller_tasks')->count());
            unlink($file);
        });
        Http::assertNothingSent();
    }

    public function test_new_input_during_responder_result_persistence_preserves_accounting_and_hides_candidate(): void
    {
        [$tenant, $http, $claim, $extractor] = $this->fixture('Synthetic input');
        [$stage, , , $file] = $this->responderFixture($tenant, $http, $claim, $extractor, ['costMicros' => 2]);
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION c02_test_delay_responder() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF NEW.result_encrypted IS NOT NULL AND OLD.result_encrypted IS NULL THEN
    PERFORM pg_advisory_xact_lock(8620251);
  END IF;
  RETURN NEW;
END;
$$;
CREATE TRIGGER c02_test_delay_responder BEFORE UPDATE ON ai_calls FOR EACH ROW EXECUTE FUNCTION c02_test_delay_responder();
SQL);
        config(['database.connections.c02_responder_advisory' => config('database.connections.pgsql')]);
        $blocker = DB::connection('c02_responder_advisory');
        $blocker->beginTransaction();
        $blocker->select('SELECT pg_advisory_xact_lock(8620251)');
        $worker = new Process([PHP_BINARY, '-d', 'opcache.enable_cli=0', '-d', 'allow_url_fopen=0',
            '-d', 'disable_functions=fsockopen,pfsockopen,stream_socket_client,curl_exec,curl_multi_exec',
            base_path('tests/Feature/Ai/Fixtures/protected-responder-worker.php')], base_path());
        $worker->setInput(json_encode(['org' => $tenant->organizationId, 'brand' => $tenant->brandId,
            'user' => $tenant->actorId, 'member' => $tenant->membershipId, 'session' => $http->session()->getId(),
            'turn' => $claim->turnId, 'conversation' => $claim->conversationId, 'version' => $claim->baseVersion,
            'token' => $claim->token(), 'stage' => $stage, 'fixtures' => dirname($file)], JSON_THROW_ON_ERROR));
        $worker->setTimeout(25);
        $inputRecorded = false;
        try {
            $worker->start();
            $deadline = microtime(true) + 20;
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
                DB::purge('c02_responder_advisory');
            }
        }
        try {
            $worker->wait();
            self::assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
            self::assertSame(['status' => 'completed'], json_decode($worker->getOutput(), true, 512, JSON_THROW_ON_ERROR));
            $this->within($tenant, function () use ($http, $claim, $stage): void {
                try {
                    DB::transaction(fn () => app(Gateway::class)->readProtectedResponderLocked($http, $claim, $stage));
                    self::fail('Result of a stale turn was exposed.');
                } catch (LogicException $error) {
                    self::assertSame('Protected responder result unavailable.', $error->getMessage());
                }
            });
            $call = DB::table('c02_responder_stages')->where('id', $stage)->value('ai_call_id');
            self::assertSame('completed', DB::table('ai_calls')->where('id', $call)->value('status'));
            self::assertSame(2, (int) DB::table('ai_budgets')->where('organization_id', $tenant->organizationId)->value('spent_micros'));
            self::assertSame('uncertain', DB::table('seller_turns')->where('id', $claim->turnId)->value('status'));
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
            DB::purge('c02_responder_advisory');
        }
    }

    public function test_responder_migration_preserves_existing_turn_and_refuses_to_discard_history(): void
    {
        $migration = require database_path('migrations/C-02/2026_11_01_070000_c02_create_responder_stages.php');
        $bindingMigration = require database_path('migrations/C-02/2026_11_01_110000_c02_seal_responder_binding.php');
        $bindingMigration->down();
        $migration->down();
        [$tenant, $http, $claim, $extractor] = $this->fixture();
        self::assertSame(1, DB::table('ai_calls')->count());
        self::assertSame(1, DB::table('messages')->where('conversation_id', $claim->conversationId)->count());
        $migration->up();
        $bindingMigration->up();
        [$stage, , , $file] = $this->responderFixture($tenant, $http, $claim, $extractor);
        self::assertNotEmpty($stage);
        self::assertSame(1, DB::table('c02_responder_stages')->count());
        self::assertSame('processing', DB::table('seller_turns')->where('id', $claim->turnId)->value('status'));
        try {
            $migration->down();
            self::fail('Migration discarded protected responder history.');
        } catch (\RuntimeException $error) {
            self::assertSame('Cannot discard protected responder stage history.', $error->getMessage());
        }
        self::assertSame(1, DB::table('c02_responder_stages')->count());
        unlink($file);
        Http::assertNothingSent();
    }

    public function test_guard_checks_only_bound_candidate_against_existing_prototype_golden(): void
    {
        [$tenant, $http, $claim, $extractor] = $this->fixture('Synthetic customer input');
        [$stage, , , $file] = $this->responderFixture($tenant, $http, $claim, $extractor, ['content' => 'Прайс в чате, посмотрите.']);
        $this->within($tenant, function () use ($tenant, $http, $claim, $stage, $file): void {
            self::assertSame('completed', app(Gateway::class)->completeProtectedResponder($http, $claim, $stage));
            $guard = app(FirstTurnDeterministicGuard::class);
            try {
                $guard->evaluateLocked($http, $claim, $stage);
                self::fail('Guard was evaluated outside a transaction.');
            } catch (LogicException $error) {
                self::assertSame('Protected first-turn guard unavailable.', $error->getMessage());
            }
            $golden = json_decode(file_get_contents(base_path('tests/Unit/Seller/Engine/fixtures/guard-prototype-synthetic.json')), true, 512, JSON_THROW_ON_ERROR);
            $case = array_values(array_filter($golden['cases'], fn ($item) => $item['name'] === 'price_without_tool'))[0];
            DB::transaction(function () use ($http, $claim, $stage, $guard, $case): void {
                $first = $guard->evaluateLocked($http, $claim, $stage);
                self::assertSame($case['expected'], $first);
                $first['issues'] = [];
                $first['verdict'] = 'ok';
                self::assertSame($case['expected'], $guard->evaluateLocked($http, $claim, $stage));
            });
            self::assertFalse(DB::table('conversations')->where('id', $claim->conversationId)->value('do_not_disturb'));
            self::assertSame([], json_decode(DB::table('conversations')->where('id', $claim->conversationId)->value('state'), true, 512, JSON_THROW_ON_ERROR));
            self::assertSame(2, DB::table('ai_calls')->where('organization_id', $tenant->organizationId)->count());
            self::assertSame(0, DB::table('seller_tasks')->count());
            self::assertSame(0, DB::table('outbox_events')->count());
            unlink($file);
        });
        Http::assertNothingSent();
        Mail::assertNothingOutgoing();
        Queue::assertNothingPushed();
    }

    public function test_guard_does_not_treat_unknown_cost_or_tool_signal_as_text(): void
    {
        [$tenant, $http, $claim, $extractor] = $this->fixture('Unknown cost');
        [$stage, , , $file] = $this->responderFixture($tenant, $http, $claim, $extractor, ['costMicros' => null]);
        $this->within($tenant, function () use ($http, $claim, $stage, $file): void {
            self::assertSame('uncertain', app(Gateway::class)->completeProtectedResponder($http, $claim, $stage));
            try {
                DB::transaction(fn () => app(FirstTurnDeterministicGuard::class)->evaluateLocked($http, $claim, $stage));
                self::fail('Unknown result was treated as a guard candidate.');
            } catch (LogicException $error) {
                self::assertSame('Protected first-turn guard unavailable.', $error->getMessage());
            }
            unlink($file);
        });
        [$otherTenant, $otherHttp, $otherClaim, $otherExtractor] = $this->fixture('Tool signal');
        [$otherStage, , , $otherFile] = $this->responderFixture($otherTenant, $otherHttp, $otherClaim, $otherExtractor, [
            'content' => 'Ignored text', 'toolCalls' => [['id' => 'synthetic-call', 'name' => 'send_price', 'arguments' => ['email' => 'synthetic.user@example.test']]],
        ]);
        $this->within($otherTenant, function () use ($otherHttp, $otherClaim, $otherStage, $otherFile): void {
            self::assertSame('completed', app(Gateway::class)->completeProtectedResponder($otherHttp, $otherClaim, $otherStage));
            try {
                DB::transaction(fn () => app(FirstTurnDeterministicGuard::class)->evaluateLocked($otherHttp, $otherClaim, $otherStage));
                self::fail('Tool calls were treated as a text candidate.');
            } catch (LogicException $error) {
                self::assertSame('Protected first-turn guard unavailable.', $error->getMessage());
            }
            unlink($otherFile);
        });
        self::assertSame(4, DB::table('ai_calls')->count());
        self::assertSame(0, DB::table('seller_tasks')->count());
        self::assertSame(0, DB::table('outbox_events')->count());
        Http::assertNothingSent();
        Mail::assertNothingOutgoing();
        Queue::assertNothingPushed();
    }

    public function test_guard_recomputes_a_passing_candidate_from_bound_sources(): void
    {
        [$tenant, $http, $claim, $extractor] = $this->fixture('Synthetic input');
        [$stage, , , $file] = $this->responderFixture($tenant, $http, $claim, $extractor, ['content' => 'Можем обсудить заказ?']);
        $this->within($tenant, function () use ($http, $claim, $stage, $file): void {
            self::assertSame('completed', app(Gateway::class)->completeProtectedResponder($http, $claim, $stage));
            $golden = json_decode(file_get_contents(base_path('tests/Unit/Seller/Engine/fixtures/guard-prototype-synthetic.json')), true, 512, JSON_THROW_ON_ERROR);
            $case = array_values(array_filter($golden['cases'], fn ($item) => $item['name'] === 'one_question'))[0];
            $result = DB::transaction(fn () => app(FirstTurnDeterministicGuard::class)->evaluateLocked($http, $claim, $stage));
            self::assertSame($case['expected'], $result);
            self::assertSame(2, DB::table('ai_calls')->count());
            self::assertSame(0, DB::table('seller_tasks')->count());
            self::assertSame(0, DB::table('outbox_events')->count());
            unlink($file);
        });
        Http::assertNothingSent();
    }

    public function test_guard_rejects_other_sources_tampering_revoked_rights_and_new_input(): void
    {
        [$tenant, $http, $claim, $extractor] = $this->fixture('First synthetic input');
        [$stage, , , $file] = $this->responderFixture($tenant, $http, $claim, $extractor);
        $this->within($tenant, fn () => app(Gateway::class)->completeProtectedResponder($http, $claim, $stage));
        unlink($file);
        [$otherTenant, $otherHttp, $otherClaim, $otherExtractor] = $this->fixture('Second synthetic input');
        [$otherStage, , , $otherFile] = $this->responderFixture($otherTenant, $otherHttp, $otherClaim, $otherExtractor);
        $this->within($otherTenant, fn () => app(Gateway::class)->completeProtectedResponder($otherHttp, $otherClaim, $otherStage));
        unlink($otherFile);
        $otherCall = DB::table('c02_responder_stages')->where('id', $otherStage)->value('ai_call_id');
        $otherCipher = DB::table('ai_calls')->where('id', $otherCall)->value('result_encrypted');
        $this->within($tenant, function () use ($tenant, $http, $claim, $stage, $otherStage, $otherCipher): void {
            $forged = new TurnClaim('claimed', $claim->turnId, $claim->conversationId, $claim->organizationId, $claim->brandId, $claim->baseVersion, str_repeat('a', 64));
            foreach ([[$forged, $stage], [$claim, $otherStage], [$claim, (string) Str::ulid()]] as [$turn, $stageId]) {
                try {
                    DB::transaction(fn () => app(FirstTurnDeterministicGuard::class)->evaluateLocked($http, $turn, $stageId));
                    self::fail('Forged guard source was accepted.');
                } catch (LogicException $error) {
                    self::assertSame('Protected first-turn guard unavailable.', $error->getMessage());
                    self::assertNull($error->getPrevious());
                    $trace = json_encode($error->getTrace(), JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_THROW_ON_ERROR);
                    self::assertStringNotContainsString($turn->token(), $trace);
                    self::assertStringNotContainsString($http->session()->getId(), $trace);
                }
            }
            $call = DB::table('c02_responder_stages')->where('id', $stage)->value('ai_call_id');
            $original = DB::table('ai_calls')->where('id', $call)->value('result_encrypted');
            DB::table('ai_calls')->where('id', $call)->update(['result_encrypted' => $otherCipher]);
            try {
                DB::transaction(fn () => app(FirstTurnDeterministicGuard::class)->evaluateLocked($http, $claim, $stage));
                self::fail('Another responder result was accepted.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
            DB::table('ai_calls')->where('id', $call)->update(['result_encrypted' => $original]);
            DB::table('brand_modules')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)
                ->where('module', 'seller')->update(['enabled' => false]);
            try {
                DB::transaction(fn () => app(FirstTurnDeterministicGuard::class)->evaluateLocked($http, $claim, $stage));
                self::fail('Revoked seller access computed a guard result.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
            DB::table('brand_modules')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)
                ->where('module', 'seller')->update(['enabled' => true]);
            $release = DB::table('brands')->where('id', $tenant->brandId)->value('active_knowledge_release_id');
            DB::table('brands')->where('id', $tenant->brandId)->update(['active_knowledge_release_id' => null]);
            try {
                DB::transaction(fn () => app(FirstTurnDeterministicGuard::class)->evaluateLocked($http, $claim, $stage));
                self::fail('Inactive knowledge release was accepted.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
            DB::table('brands')->where('id', $tenant->brandId)->update(['active_knowledge_release_id' => $release]);
            Message::create(['conversation_id' => $claim->conversationId, 'role' => 'client', 'text' => 'Later synthetic input', 'meta' => []]);
            try {
                DB::transaction(fn () => app(FirstTurnDeterministicGuard::class)->evaluateLocked($http, $claim, $stage));
                self::fail('Stale candidate was checked.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
            self::assertSame('uncertain', DB::table('seller_turns')->where('id', $claim->turnId)->value('status'));
            self::assertSame(2, DB::table('messages')->where('conversation_id', $claim->conversationId)->count());
        });
        $this->within($otherTenant, function () use ($otherHttp, $otherClaim, $otherStage): void {
            app(AuthSessionRegistry::class)->withinActive($otherHttp, fn () => app(AuthSessionRegistry::class)->revokeCurrent($otherHttp));
            try {
                DB::transaction(fn () => app(FirstTurnDeterministicGuard::class)->evaluateLocked($otherHttp, $otherClaim, $otherStage));
                self::fail('Revoked session evaluated a guard result.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
        });
        self::assertSame(4, DB::table('ai_calls')->count());
        self::assertSame(0, DB::table('seller_tasks')->count());
        self::assertSame(0, DB::table('outbox_events')->count());
        Http::assertNothingSent();
    }

    public function test_guard_serializes_with_new_input_and_rejects_the_stale_candidate(): void
    {
        [$tenant, $http, $claim, $extractor] = $this->fixture();
        [$stage, , , $file] = $this->responderFixture($tenant, $http, $claim, $extractor);
        $this->within($tenant, function () use ($tenant, $http, $claim, $stage, $file): void {
            self::assertSame('completed', app(Gateway::class)->completeProtectedResponder($http, $claim, $stage));
            config(['database.connections.c02_guard_competitor' => config('database.connections.pgsql')]);
            $other = DB::connection('c02_guard_competitor');
            DB::transaction(function () use ($tenant, $http, $claim, $stage, $other): void {
                self::assertContains(app(FirstTurnDeterministicGuard::class)->evaluateLocked($http, $claim, $stage)['verdict'], ['ok', 'regenerate']);
                $other->beginTransaction();
                try {
                    $other->statement("SET LOCAL lock_timeout = '250ms'");
                    $other->table('messages')->insert(['id' => (string) Str::ulid(), 'organization_id' => $tenant->organizationId,
                        'brand_id' => $tenant->brandId, 'conversation_id' => $claim->conversationId,
                        'role' => 'client', 'text' => 'Concurrent synthetic input', 'meta' => '{}']);
                    self::fail('Input crossed the guard transaction.');
                } catch (QueryException $error) {
                    self::assertSame('55P03', $error->errorInfo[0]);
                } finally {
                    $other->rollBack();
                }
            });
            DB::purge('c02_guard_competitor');
            Message::create(['conversation_id' => $claim->conversationId, 'role' => 'client', 'text' => 'Later synthetic input', 'meta' => []]);
            try {
                DB::transaction(fn () => app(FirstTurnDeterministicGuard::class)->evaluateLocked($http, $claim, $stage));
                self::fail('Stale guard result remained available.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
            self::assertSame('uncertain', DB::table('seller_turns')->where('id', $claim->turnId)->value('status'));
            self::assertSame(2, DB::table('messages')->where('conversation_id', $claim->conversationId)->count());
            self::assertSame(2, DB::table('ai_calls')->count());
            self::assertSame(0, DB::table('seller_tasks')->count());
            unlink($file);
        });
        Http::assertNothingSent();
    }

    public function test_model_guard_projection_matches_all_original_prompt_goldens(): void
    {
        [$tenant] = $this->fixture();
        $this->within($tenant, function () use ($tenant): void {
            $knowledge = app(KnowledgeRepository::class)->published($tenant);
            $states = json_decode(file_get_contents(base_path('tests/fixtures/prototype/prompts/states.json')), true, 512, JSON_THROW_ON_ERROR);
            $extraction = ['slot_updates' => [], 'client_intent' => 'answer_only', 'client_questions' => [], 'objections' => [], 'sentiment' => 'neutral', 'wants_only_prices' => false, 'asked_if_bot' => false, 'wants_human' => false, 'do_not_disturb' => false, 'off_topic' => false, 'prompt_injection' => false, 'closed_topic' => null, 'unknown_question' => null, 'client_claims' => [], 'refused_slots' => [], 'confidence' => 0.9];
            foreach ($states as $name => $data) {
                $state = new LeadState($data);
                $goal = (new Policy($knowledge))->decide($state, $extraction, $name === 'opening');
                $actual = app(GuardProjection::class)->render($state, $goal);
                $expected = file_get_contents(base_path('tests/fixtures/prototype/prompts/'.$name.'/guard.txt'));
                self::assertIsString($expected);
                self::assertSame(str_replace("\r\n", "\n", $expected), str_replace("\r\n", "\n", $actual['body']), $name);
                self::assertSame(hash_file('sha256', resource_path('views/prompts/guard.blade.php')), $actual['checksum']);
            }
        });
        Http::assertNothingSent();
    }

    private function modelGuardResponder(string $candidate = 'Давайте обсудим ваш заказ.', bool $protectedClaim = true): array
    {
        [$tenant, $http, $claim, $extractor] = $this->fixture('Synthetic closed topic', [
            'content' => '{"client_intent":"answer_only","closed_topic":"management_names"}',
            'protected_claim' => $protectedClaim,
        ]);
        [$responder, , , $file] = $this->responderFixture($tenant, $http, $claim, $extractor, ['content' => $candidate]);
        $this->within($tenant, fn () => app(Gateway::class)->completeProtectedResponder($http, $claim, $responder));
        unlink($file);

        return [$tenant, $http, $claim, $responder];
    }

    private function modelGuardFixture(TenantContext $tenant, Request $http, TurnClaim $claim, string $responder, array $result = []): array
    {
        return $this->within($tenant, function () use ($http, $claim, $responder, $result): array {
            $stage = app(SellerGuardStages::class)->prepare($http, $claim, $responder);
            [$request, $context] = app(SellerGuardStages::class)->load($http, $claim, $stage);
            $directory = storage_path('app/model-guard-fixtures-'.Str::random(12));
            mkdir($directory, 0700, true);
            config(['features.E0-05.defaults.fixture_directory' => $directory]);
            $file = $directory.DIRECTORY_SEPARATOR.CanonicalJson::hash(app(ContactMasker::class)->request($request->toArray())).'.json';
            $fake = array_replace(['content' => '{"ok":true,"issues":[]}', 'toolCalls' => [], 'usage' => ['promptTokens' => 1, 'completionTokens' => 1],
                'model' => $request->model, 'latencyMs' => 1, 'costMicros' => 0], $result);
            file_put_contents($file, json_encode(['result' => $fake], JSON_THROW_ON_ERROR));

            return [$stage, $request, $context, $file];
        });
    }

    public function test_model_guard_binding_seal_rejects_privileged_replacement_with_another_valid_result(): void
    {
        config(['agent.guard_llm' => true]);
        [$tenant, $http, $claim, $responder] = $this->modelGuardResponder();
        [$stage, $request, $context, $file] = $this->modelGuardFixture($tenant, $http, $claim, $responder, ['content' => '{"ok":false,"issues":["Synthetic issue"]}']);
        $this->within($tenant, function () use ($http, $claim, $stage, $request, $context, $file): void {
            self::assertSame('completed', app(Gateway::class)->completeProtectedModelGuard($http, $claim, $stage));
            self::assertSame('regenerate', DB::transaction(fn () => app(Gateway::class)->readProtectedModelGuardLocked($http, $claim, $stage))['verdict']);
            $originalCall = DB::table('c02_guard_stages')->where('id', $stage)->value('ai_call_id');
            $originalKey = DB::table('ai_calls')->where('id', $originalCall)->value('idempotency_key_hash');
            self::assertNotNull(DB::table('c02_guard_stages')->where('id', $stage)->value('binding_seal'));
            file_put_contents($file, json_encode(['result' => ['content' => '{"ok":true,"issues":[]}', 'toolCalls' => [],
                'usage' => ['promptTokens' => 1, 'completionTokens' => 1], 'model' => $request->model, 'latencyMs' => 1, 'costMicros' => 0]], JSON_THROW_ON_ERROR));
            $other = new AiContext($context->organizationId, $context->brandId, $context->purpose, $context->family,
                $context->objectType, $context->objectId, 'synthetic-guard-other-'.Str::random(20), $context->dataClasses);
            $otherResult = app(Gateway::class)->complete($request, $other);
            self::assertNotSame($originalCall, $otherResult->aiCallId);
            self::assertSame(DB::table('ai_calls')->where('id', $originalCall)->value('request_hash'), DB::table('ai_calls')->where('id', $otherResult->aiCallId)->value('request_hash'));
            DB::beginTransaction();
            try {
                DB::statement('ALTER TABLE c02_guard_stages DISABLE TRIGGER c02_guard_stage_guard');
                DB::table('ai_calls')->where('id', $originalCall)->update(['idempotency_key_hash' => str_repeat('b', 64)]);
                DB::table('ai_calls')->where('id', $otherResult->aiCallId)->update(['idempotency_key_hash' => $originalKey]);
                DB::table('c02_guard_stages')->where('id', $stage)->update(['ai_call_id' => $otherResult->aiCallId]);
                DB::statement('ALTER TABLE c02_guard_stages ENABLE TRIGGER c02_guard_stage_guard');
                try {
                    app(Gateway::class)->readProtectedModelGuardLocked($http, $claim, $stage);
                    self::fail('A different authenticated Guard result replaced the bound call.');
                } catch (LogicException $error) {
                    self::assertSame('Protected model guard result unavailable.', $error->getMessage());
                }
                foreach ([null, str_repeat('a', 64)] as $forgedSeal) {
                    DB::statement('ALTER TABLE c02_guard_stages DISABLE TRIGGER c02_guard_stage_guard');
                    DB::statement('ALTER TABLE c02_guard_stages DISABLE TRIGGER c02_guard_binding_seal_guard');
                    DB::table('c02_guard_stages')->where('id', $stage)->update(['binding_seal' => $forgedSeal]);
                    DB::statement('ALTER TABLE c02_guard_stages ENABLE TRIGGER c02_guard_binding_seal_guard');
                    DB::statement('ALTER TABLE c02_guard_stages ENABLE TRIGGER c02_guard_stage_guard');
                    try {
                        app(Gateway::class)->readProtectedModelGuardLocked($http, $claim, $stage);
                        self::fail('A missing or forged Guard binding seal was accepted.');
                    } catch (LogicException $error) {
                        self::assertSame('Protected model guard result unavailable.', $error->getMessage());
                    }
                }
            } finally {
                DB::rollBack();
                unlink($file);
            }
        });
    }

    public function test_guard_binding_migration_preserves_unsigned_legacy_history_without_trusting_it(): void
    {
        config(['agent.guard_llm' => true]);
        [$tenant, $http, $claim, $responder] = $this->modelGuardResponder();
        [$stage, , , $file] = $this->modelGuardFixture($tenant, $http, $claim, $responder);
        $this->within($tenant, fn () => app(Gateway::class)->completeProtectedModelGuard($http, $claim, $stage));
        unlink($file);
        $migration = require database_path('migrations/C-02/2026_11_01_120000_c02_seal_guard_binding.php');
        try {
            $migration->down();
            self::fail('A signed Guard binding was silently discarded.');
        } catch (\RuntimeException $error) {
            self::assertSame('Cannot discard guard binding seals.', $error->getMessage());
        }
        // Recreate the pre-migration representation only in this isolated DB.
        DB::transaction(function () use ($stage): void {
            DB::statement('ALTER TABLE c02_guard_stages DISABLE TRIGGER c02_guard_stage_guard');
            DB::statement('ALTER TABLE c02_guard_stages DISABLE TRIGGER c02_guard_binding_seal_guard');
            DB::table('c02_guard_stages')->where('id', $stage)->update(['binding_seal' => null]);
            DB::statement('ALTER TABLE c02_guard_stages ENABLE TRIGGER c02_guard_binding_seal_guard');
            DB::statement('ALTER TABLE c02_guard_stages ENABLE TRIGGER c02_guard_stage_guard');
        });
        $migration->down();
        $old = (array) DB::table('c02_guard_stages')->where('id', $stage)->first();
        $oldCall = DB::table('ai_calls')->where('id', $old['ai_call_id'])->first();
        [$otherTenant, $otherHttp, $otherClaim, $otherResponder] = $this->modelGuardResponder('Помогу с вашим заказом.');
        $otherStage = $this->within($otherTenant, fn () => app(SellerGuardStages::class)->prepare($otherHttp, $otherClaim, $otherResponder));
        self::assertNull(DB::table('c02_guard_stages')->where('id', $otherStage)->value('ai_call_id'));
        $migration->up();
        $migrated = (array) DB::table('c02_guard_stages')->where('id', $stage)->first();
        self::assertArrayHasKey('binding_seal', $migrated);
        self::assertNull($migrated['binding_seal']);
        unset($migrated['binding_seal']);
        self::assertEquals($old, $migrated);
        self::assertEquals($oldCall, DB::table('ai_calls')->where('id', $old['ai_call_id'])->first());
        $this->within($tenant, function () use ($http, $claim, $stage): void {
            try {
                app(SellerGuardStages::class)->load($http, $claim, $stage);
                self::fail('An unsigned legacy Guard binding was treated as verified.');
            } catch (LogicException $error) {
                self::assertSame('Protected model guard stage rejected.', $error->getMessage());
            }
            try {
                app(Gateway::class)->completeProtectedModelGuard($http, $claim, $stage);
                self::fail('An unsigned legacy Guard was replayed as verified.');
            } catch (LogicException $error) {
                self::assertSame('Protected model guard stage could not be completed.', $error->getMessage());
            }
        });
        [$reused, , , $otherFile] = $this->modelGuardFixture($otherTenant, $otherHttp, $otherClaim, $otherResponder);
        self::assertSame($otherStage, $reused);
        self::assertSame('completed', $this->within($otherTenant, fn () => app(Gateway::class)->completeProtectedModelGuard($otherHttp, $otherClaim, $otherStage)));
        self::assertNotNull(DB::table('c02_guard_stages')->where('id', $otherStage)->value('binding_seal'));
        unlink($otherFile);
        Http::assertNothingSent();
        Mail::assertNothingOutgoing();
        Queue::assertNothingPushed();
    }

    public function test_cleared_guard_binding_cannot_reseal_an_old_call(): void
    {
        config(['agent.guard_llm' => true]);
        [$tenant, $http, $claim, $responder] = $this->modelGuardResponder();
        [$stage, $request, $context, $file] = $this->modelGuardFixture($tenant, $http, $claim, $responder);
        $this->within($tenant, function () use ($http, $claim, $stage, $request, $context, $file): void {
            self::assertSame('completed', app(Gateway::class)->completeProtectedModelGuard($http, $claim, $stage));
            $original = DB::table('c02_guard_stages')->where('id', $stage)->first();
            $originalKey = DB::table('ai_calls')->where('id', $original->ai_call_id)->value('idempotency_key_hash');
            $other = new AiContext($context->organizationId, $context->brandId, $context->purpose, $context->family,
                $context->objectType, $context->objectId, 'synthetic-guard-rebind-'.Str::random(20), $context->dataClasses);
            $otherResult = app(Gateway::class)->complete($request, $other);
            $otherKey = DB::table('ai_calls')->where('id', $otherResult->aiCallId)->value('idempotency_key_hash');
            DB::transaction(function () use ($stage, $original, $originalKey, $otherResult): void {
                DB::statement('ALTER TABLE c02_guard_stages DISABLE TRIGGER c02_guard_stage_guard');
                DB::statement('ALTER TABLE c02_guard_stages DISABLE TRIGGER c02_guard_binding_seal_guard');
                DB::table('c02_guard_stages')->where('id', $stage)->update(['ai_call_id' => null, 'gateway_request_hash' => null, 'binding_seal' => null]);
                DB::table('ai_calls')->where('id', $original->ai_call_id)->update(['idempotency_key_hash' => str_repeat('b', 64)]);
                DB::table('ai_calls')->where('id', $otherResult->aiCallId)->update(['idempotency_key_hash' => $originalKey]);
                DB::statement('ALTER TABLE c02_guard_stages ENABLE TRIGGER c02_guard_binding_seal_guard');
                // Leave the old stage trigger disabled to test the PHP proof independently.
            });
            try {
                try {
                    app(Gateway::class)->completeProtectedModelGuard($http, $claim, $stage);
                    self::fail('An old Guard call was sealed as a new binding.');
                } catch (LogicException $error) {
                    self::assertSame('Protected model guard stage could not be completed.', $error->getMessage());
                }
                self::assertNull(DB::table('c02_guard_stages')->where('id', $stage)->value('binding_seal'));
                self::assertSame(2, DB::table('ai_calls')->where('purpose', 'guard')->count());
            } finally {
                DB::transaction(function () use ($stage, $original, $originalKey, $otherResult, $otherKey): void {
                    DB::statement('ALTER TABLE c02_guard_stages DISABLE TRIGGER c02_guard_binding_seal_guard');
                    DB::table('ai_calls')->where('id', $otherResult->aiCallId)->update(['idempotency_key_hash' => $otherKey]);
                    DB::table('ai_calls')->where('id', $original->ai_call_id)->update(['idempotency_key_hash' => $originalKey]);
                    DB::table('c02_guard_stages')->where('id', $stage)->update(['ai_call_id' => $original->ai_call_id,
                        'gateway_request_hash' => $original->gateway_request_hash, 'binding_seal' => $original->binding_seal]);
                    DB::statement('ALTER TABLE c02_guard_stages ENABLE TRIGGER c02_guard_binding_seal_guard');
                    DB::statement('ALTER TABLE c02_guard_stages ENABLE TRIGGER c02_guard_stage_guard');
                });
                unlink($file);
            }
            self::assertSame('completed', app(Gateway::class)->completeProtectedModelGuard($http, $claim, $stage));
        });
        Http::assertNothingSent();
    }

    public function test_new_guard_binding_cannot_commit_without_its_seal(): void
    {
        config(['agent.guard_llm' => true]);
        [$tenant, $http, $claim, $responder] = $this->modelGuardResponder();
        [$stage, , , $file] = $this->modelGuardFixture($tenant, $http, $claim, $responder);
        $fake = new CoordinatorScriptedFakeDriver([]);
        app()->instance(FakeDriver::class, $fake);
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION c02_test_drop_guard_binding_seal() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN NEW.binding_seal := NULL; RETURN NEW; END;
$$;
CREATE TRIGGER a_c02_test_drop_guard_binding_seal BEFORE UPDATE ON c02_guard_stages
  FOR EACH ROW EXECUTE FUNCTION c02_test_drop_guard_binding_seal();
SQL);
        try {
            $this->within($tenant, function () use ($http, $claim, $stage): void {
                try {
                    app(Gateway::class)->completeProtectedModelGuard($http, $claim, $stage);
                    self::fail('A Guard call was bound without its seal.');
                } catch (LogicException $error) {
                    self::assertSame('Protected model guard stage could not be completed.', $error->getMessage());
                }
            });
            self::assertSame(0, $fake->calls);
            self::assertSame(0, DB::table('ai_calls')->where('purpose', 'guard')->count());
            self::assertNull(DB::table('c02_guard_stages')->where('id', $stage)->value('ai_call_id'));
            self::assertNull(DB::table('c02_guard_stages')->where('id', $stage)->value('binding_seal'));
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS a_c02_test_drop_guard_binding_seal ON c02_guard_stages; DROP FUNCTION IF EXISTS c02_test_drop_guard_binding_seal();');
            unlink($file);
        }
        Http::assertNothingSent();
    }

    public function test_model_guard_requires_server_enablement_and_binds_confirmed_sources(): void
    {
        [$tenant, $http, $claim, $responder] = $this->modelGuardResponder();
        $this->within($tenant, function () use ($http, $claim, $responder): void {
            self::assertFalse((bool) config('agent.guard_llm', false));
            try {
                app(SellerGuardStages::class)->prepare($http, $claim, $responder);
                self::fail('Missing server enablement was accepted.');
            } catch (LogicException $error) {
                self::assertSame('Protected model guard stage rejected.', $error->getMessage());
            }
            self::assertSame(0, DB::table('c02_guard_stages')->count());
        });
        config(['agent.guard_llm' => true]);
        [$stage, $request, $context, $file] = $this->modelGuardFixture($tenant, $http, $claim, $responder);
        $this->within($tenant, function () use ($http, $claim, $responder, $stage, $request, $context, $file): void {
            self::assertSame($stage, app(SellerGuardStages::class)->prepare($http, $claim, $responder));
            self::assertSame('guard', $context->purpose);
            self::assertSame('Давайте обсудим ваш заказ.', $request->messages[1]['content']);
            self::assertStringContainsString('Цель хода: closed_topic', $request->messages[0]['content']);
            self::assertSame([], $request->tools);
            self::assertNull($request->toolChoice);
            self::assertSame(0.0, $request->temperature);
            try {
                app(Gateway::class)->complete($request, $context);
                self::fail('Public Gateway accepted a prepared guard request.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
            self::assertSame('completed', app(Gateway::class)->completeProtectedModelGuard($http, $claim, $stage));
            self::assertSame('completed', app(Gateway::class)->completeProtectedModelGuard($http, $claim, $stage));
            try {
                app(Gateway::class)->readProtectedModelGuardLocked($http, $claim, $stage);
                self::fail('Model guard result was read outside a transaction.');
            } catch (LogicException $error) {
                self::assertSame('Protected model guard result unavailable.', $error->getMessage());
            }
            $decision = DB::transaction(fn () => app(Gateway::class)->readProtectedModelGuardLocked($http, $claim, $stage));
            self::assertSame('completed', $decision['status']);
            self::assertSame('ok', $decision['verdict']);
            self::assertSame([], $decision['issues']);
            try {
                app(Gateway::class)->complete($request, $context);
                self::fail('Public Gateway replayed a protected guard result.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
            self::assertSame(3, DB::table('ai_calls')->count());
            self::assertSame(0, DB::table('seller_tasks')->count());
            self::assertSame(0, DB::table('outbox_events')->count());
            unlink($file);
        });
        Http::assertNothingSent();
        Mail::assertNothingOutgoing();
        Queue::assertNothingPushed();
    }

    public function test_model_guard_is_reachable_for_an_inbound_question_with_facts(): void
    {
        config(['agent.guard_llm' => true]);
        [$tenant, $http, $claim, $extractor] = $this->fixture('Где вы находитесь?', [
            'content' => '{"client_intent":"ask_location"}',
        ]);
        [$responder, , , $file, $stateId] = $this->responderFixture($tenant, $http, $claim, $extractor, ['content' => 'Мы в Москве. Откуда вы?']);
        $this->within($tenant, function () use ($http, $claim, $extractor, $responder, $stateId, $file): void {
            $computed = DB::transaction(fn () => app(FirstTurnGoal::class)->evaluateLocked($http, $claim, $extractor, $stateId));
            self::assertSame('answer_and_ask', $computed['goal']->kind);
            self::assertSame('completed', app(Gateway::class)->completeProtectedResponder($http, $claim, $responder));
            $det = DB::transaction(fn () => app(FirstTurnDeterministicGuard::class)->evaluateLocked($http, $claim, $responder));
            self::assertSame('ok', $det['verdict']);
            unlink($file);
        });
        [$stage, , , $guardFile] = $this->modelGuardFixture($tenant, $http, $claim, $responder);
        $this->within($tenant, function () use ($http, $claim, $stage, $guardFile): void {
            self::assertSame('completed', app(Gateway::class)->completeProtectedModelGuard($http, $claim, $stage));
            $decision = DB::transaction(fn () => app(Gateway::class)->readProtectedModelGuardLocked($http, $claim, $stage));
            self::assertSame('completed', $decision['status']);
            self::assertSame('ok', $decision['verdict']);
            self::assertSame(3, DB::table('ai_calls')->count());
            self::assertSame(0, DB::table('seller_tasks')->count());
            unlink($guardFile);
        });
        Http::assertNothingSent();
    }

    public function test_model_guard_refuses_unneeded_goal_and_deterministic_issues(): void
    {
        config(['agent.guard_llm' => true]);
        [$tenant, $http, $claim, $extractor] = $this->fixture('Ordinary first input');
        [$responder, , , $file] = $this->responderFixture($tenant, $http, $claim, $extractor);
        $this->within($tenant, function () use ($http, $claim, $responder, $file): void {
            self::assertSame('completed', app(Gateway::class)->completeProtectedResponder($http, $claim, $responder));
            try {
                app(SellerGuardStages::class)->prepare($http, $claim, $responder);
                self::fail('Model guard was created for an ineligible goal.');
            } catch (LogicException $error) {
                self::assertSame('Protected model guard stage rejected.', $error->getMessage());
            }
            unlink($file);
        });
        [$otherTenant, $otherHttp, $otherClaim, $otherResponder] = $this->modelGuardResponder('Прайс в чате, посмотрите.');
        $this->within($otherTenant, function () use ($otherHttp, $otherClaim, $otherResponder): void {
            $det = DB::transaction(fn () => app(FirstTurnDeterministicGuard::class)->evaluateLocked($otherHttp, $otherClaim, $otherResponder));
            self::assertSame('regenerate', $det['verdict']);
            try {
                app(SellerGuardStages::class)->prepare($otherHttp, $otherClaim, $otherResponder);
                self::fail('Model guard bypassed deterministic issues.');
            } catch (LogicException $error) {
                self::assertSame('Protected model guard stage rejected.', $error->getMessage());
            }
        });
        self::assertSame(0, DB::table('c02_guard_stages')->count());
        self::assertSame(4, DB::table('ai_calls')->count());
        self::assertSame(0, DB::table('seller_tasks')->count());
        Http::assertNothingSent();
    }

    public function test_model_guard_unknown_tool_calls_and_malformed_result_are_fail_closed(): void
    {
        config(['agent.guard_llm' => true]);
        foreach ([
            ['result' => ['costMicros' => null], 'status' => 'uncertain'],
            ['result' => ['content' => '{"ok":true,"issues":[]}', 'toolCalls' => [['id' => 'synthetic', 'name' => 'send_price', 'arguments' => []]]], 'status' => 'unavailable'],
            ['result' => ['content' => '{"ok":true,"issues":{}}'], 'status' => 'unavailable'],
            ['result' => ['content' => '{"ok":false,"issues":["Синтетическая ошибка"]}'], 'status' => 'regenerate'],
        ] as $case) {
            [$tenant, $http, $claim, $responder] = $this->modelGuardResponder();
            [$stage, , , $file] = $this->modelGuardFixture($tenant, $http, $claim, $responder, $case['result']);
            $this->within($tenant, function () use ($http, $claim, $stage, $file, $case): void {
                $first = app(Gateway::class)->completeProtectedModelGuard($http, $claim, $stage);
                self::assertSame($case['status'] === 'uncertain' ? 'uncertain' : 'completed', $first);
                self::assertSame($first, app(Gateway::class)->completeProtectedModelGuard($http, $claim, $stage));
                if ($case['status'] === 'uncertain') {
                    try {
                        DB::transaction(fn () => app(Gateway::class)->readProtectedModelGuardLocked($http, $claim, $stage));
                        self::fail('Unknown guard cost produced a decision.');
                    } catch (LogicException $error) {
                        self::assertSame('Protected model guard result unavailable.', $error->getMessage());
                    }
                } else {
                    $decision = DB::transaction(fn () => app(Gateway::class)->readProtectedModelGuardLocked($http, $claim, $stage));
                    if ($case['status'] === 'regenerate') {
                        self::assertSame('completed', $decision['status']);
                        self::assertSame('regenerate', $decision['verdict']);
                        self::assertSame(['Синтетическая ошибка'], $decision['issues']);
                    } else {
                        self::assertSame('unavailable', $decision['status']);
                        self::assertNull($decision['verdict']);
                        self::assertSame([], $decision['issues']);
                    }
                }
                unlink($file);
            });
        }
        self::assertSame(12, DB::table('ai_calls')->count());
        self::assertSame(0, DB::table('seller_tasks')->count());
        self::assertSame(0, DB::table('outbox_events')->count());
        Http::assertNothingSent();
        Mail::assertNothingOutgoing();
        Queue::assertNothingPushed();
    }

    public function test_model_guard_rejects_forgery_tampering_revocation_and_new_input(): void
    {
        config(['agent.guard_llm' => true]);
        [$tenant, $http, $claim, $responder] = $this->modelGuardResponder();
        [$stage, , , $file] = $this->modelGuardFixture($tenant, $http, $claim, $responder);
        $this->within($tenant, function () use ($http, $claim, $responder, $stage, $file): void {
            $oldCall = DB::table('c02_responder_stages')->where('id', $responder)->value('ai_call_id');
            $forged = (array) DB::table('c02_guard_stages')->where('id', $stage)->first();
            $forged['id'] = (string) Str::ulid();
            $forged['responder_stage_id'] = (string) Str::ulid();
            foreach ([fn () => DB::table('c02_guard_stages')->insert($forged),
                fn () => DB::table('c02_guard_stages')->where('id', $stage)->update(['request_encrypted' => 'forged']),
                fn () => DB::table('c02_guard_stages')->where('id', $stage)->update(['ai_call_id' => $oldCall, 'gateway_request_hash' => str_repeat('a', 64)]),
                fn () => DB::table('c02_guard_stages')->where('id', $stage)->delete(),
                fn () => DB::statement('TRUNCATE TABLE c02_guard_stages')] as $operation) {
                try {
                    DB::transaction($operation);
                    self::fail('Protected model guard history changed.');
                } catch (QueryException $error) {
                    self::assertSame('23514', $error->errorInfo[0]);
                }
            }
            self::assertSame('completed', app(Gateway::class)->completeProtectedModelGuard($http, $claim, $stage));
            unlink($file);
        });
        [$otherTenant, $otherHttp, $otherClaim, $otherResponder] = $this->modelGuardResponder('Помогу с вашим заказом.');
        [$otherStage, , , $otherFile] = $this->modelGuardFixture($otherTenant, $otherHttp, $otherClaim, $otherResponder);
        $this->within($otherTenant, fn () => app(Gateway::class)->completeProtectedModelGuard($otherHttp, $otherClaim, $otherStage));
        unlink($otherFile);
        $otherCall = DB::table('c02_guard_stages')->where('id', $otherStage)->value('ai_call_id');
        $otherCipher = DB::table('ai_calls')->where('id', $otherCall)->value('result_encrypted');
        $this->within($tenant, function () use ($tenant, $http, $claim, $stage, $otherStage, $otherCipher): void {
            $forged = new TurnClaim('claimed', $claim->turnId, $claim->conversationId, $claim->organizationId, $claim->brandId, $claim->baseVersion, str_repeat('a', 64));
            foreach ([[$forged, $stage], [$claim, $otherStage], [$claim, (string) Str::ulid()]] as [$turn, $stageId]) {
                try {
                    DB::transaction(fn () => app(Gateway::class)->readProtectedModelGuardLocked($http, $turn, $stageId));
                    self::fail('Forged model guard source was accepted.');
                } catch (LogicException $error) {
                    self::assertSame('Protected model guard result unavailable.', $error->getMessage());
                    self::assertNull($error->getPrevious());
                    $trace = json_encode($error->getTrace(), JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_THROW_ON_ERROR);
                    self::assertStringNotContainsString($turn->token(), $trace);
                    self::assertStringNotContainsString($http->session()->getId(), $trace);
                }
            }
            $call = DB::table('c02_guard_stages')->where('id', $stage)->value('ai_call_id');
            $original = DB::table('ai_calls')->where('id', $call)->value('result_encrypted');
            DB::table('ai_calls')->where('id', $call)->update(['result_encrypted' => $otherCipher]);
            try {
                DB::transaction(fn () => app(Gateway::class)->readProtectedModelGuardLocked($http, $claim, $stage));
                self::fail('Another model result was accepted.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
            DB::table('ai_calls')->where('id', $call)->update(['result_encrypted' => $original]);
            config(['agent.guard_llm' => false]);
            try {
                DB::transaction(fn () => app(Gateway::class)->readProtectedModelGuardLocked($http, $claim, $stage));
                self::fail('Disabled model guard returned a result.');
            } catch (LogicException) {
                self::assertTrue(true);
            } finally {
                config(['agent.guard_llm' => true]);
            }
            config(['runtime.ai_driver' => 'record']);
            try {
                app(Gateway::class)->completeProtectedModelGuard($http, $claim, $stage);
                self::fail('A non-fake model guard driver was accepted.');
            } catch (LogicException) {
                self::assertTrue(true);
            } finally {
                config(['runtime.ai_driver' => 'fake']);
            }
            DB::table('prompt_sets')->whereNull('organization_id')->where('name', 'guard')->update(['status' => 'draft']);
            try {
                DB::transaction(fn () => app(Gateway::class)->readProtectedModelGuardLocked($http, $claim, $stage));
                self::fail('An unpublished guard prompt was accepted.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
            DB::table('prompt_sets')->whereNull('organization_id')->where('name', 'guard')->update(['status' => 'published']);
            $release = DB::table('brands')->where('id', $tenant->brandId)->value('active_knowledge_release_id');
            DB::table('brands')->where('id', $tenant->brandId)->update(['active_knowledge_release_id' => null]);
            try {
                DB::transaction(fn () => app(Gateway::class)->readProtectedModelGuardLocked($http, $claim, $stage));
                self::fail('An inactive release was accepted.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
            DB::table('brands')->where('id', $tenant->brandId)->update(['active_knowledge_release_id' => $release]);
            DB::table('brand_modules')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)
                ->where('module', 'seller')->update(['enabled' => false]);
            try {
                DB::transaction(fn () => app(Gateway::class)->readProtectedModelGuardLocked($http, $claim, $stage));
                self::fail('Revoked rights returned a model result.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
            DB::table('brand_modules')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)
                ->where('module', 'seller')->update(['enabled' => true]);
            Message::create(['conversation_id' => $claim->conversationId, 'role' => 'client', 'text' => 'Later synthetic input', 'meta' => []]);
            try {
                DB::transaction(fn () => app(Gateway::class)->readProtectedModelGuardLocked($http, $claim, $stage));
                self::fail('Stale model result was exposed.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
            self::assertSame('uncertain', DB::table('seller_turns')->where('id', $claim->turnId)->value('status'));
            self::assertSame(2, DB::table('messages')->where('conversation_id', $claim->conversationId)->count());
        });
        $this->within($otherTenant, function () use ($otherHttp, $otherClaim, $otherStage): void {
            app(AuthSessionRegistry::class)->withinActive($otherHttp, fn () => app(AuthSessionRegistry::class)->revokeCurrent($otherHttp));
            try {
                DB::transaction(fn () => app(Gateway::class)->readProtectedModelGuardLocked($otherHttp, $otherClaim, $otherStage));
                self::fail('Revoked session read model guard.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
        });
        self::assertSame(6, DB::table('ai_calls')->count());
        self::assertSame(0, DB::table('seller_tasks')->count());
        self::assertSame(0, DB::table('outbox_events')->count());
        Http::assertNothingSent();
    }

    public function test_model_guard_serializes_with_new_input_and_hides_stale_decision(): void
    {
        config(['agent.guard_llm' => true]);
        [$tenant, $http, $claim, $responder] = $this->modelGuardResponder();
        [$stage, , , $file] = $this->modelGuardFixture($tenant, $http, $claim, $responder);
        $this->within($tenant, function () use ($tenant, $http, $claim, $stage, $file): void {
            self::assertSame('completed', app(Gateway::class)->completeProtectedModelGuard($http, $claim, $stage));
            config(['database.connections.c02_model_guard_competitor' => config('database.connections.pgsql')]);
            $other = DB::connection('c02_model_guard_competitor');
            DB::transaction(function () use ($tenant, $http, $claim, $stage, $other): void {
                self::assertSame('ok', app(Gateway::class)->readProtectedModelGuardLocked($http, $claim, $stage)['verdict']);
                $other->beginTransaction();
                try {
                    $other->statement("SET LOCAL lock_timeout = '250ms'");
                    $other->table('messages')->insert(['id' => (string) Str::ulid(), 'organization_id' => $tenant->organizationId,
                        'brand_id' => $tenant->brandId, 'conversation_id' => $claim->conversationId,
                        'role' => 'client', 'text' => 'Concurrent synthetic input', 'meta' => '{}']);
                    self::fail('Input crossed the model guard transaction.');
                } catch (QueryException $error) {
                    self::assertSame('55P03', $error->errorInfo[0]);
                } finally {
                    $other->rollBack();
                }
            });
            DB::purge('c02_model_guard_competitor');
            Message::create(['conversation_id' => $claim->conversationId, 'role' => 'client', 'text' => 'Later synthetic input', 'meta' => []]);
            try {
                DB::transaction(fn () => app(Gateway::class)->readProtectedModelGuardLocked($http, $claim, $stage));
                self::fail('Stale model decision remained accessible.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
            self::assertSame('uncertain', DB::table('seller_turns')->where('id', $claim->turnId)->value('status'));
            self::assertSame(2, DB::table('messages')->where('conversation_id', $claim->conversationId)->count());
            self::assertSame(3, DB::table('ai_calls')->count());
            self::assertSame(0, DB::table('seller_tasks')->count());
            unlink($file);
        });
        Http::assertNothingSent();
    }

    public function test_model_guard_migration_preserves_existing_turn_and_refuses_history_loss(): void
    {
        $migration = require database_path('migrations/C-02/2026_11_01_080000_c02_create_guard_stages.php');
        $bindingMigration = require database_path('migrations/C-02/2026_11_01_120000_c02_seal_guard_binding.php');
        $bindingMigration->down();
        $migration->down();
        config(['agent.guard_llm' => true]);
        [$tenant, $http, $claim, $responder] = $this->modelGuardResponder();
        self::assertSame(2, DB::table('ai_calls')->count());
        self::assertSame(1, DB::table('messages')->where('conversation_id', $claim->conversationId)->count());
        $migration->up();
        $bindingMigration->up();
        [$stage, , , $file] = $this->modelGuardFixture($tenant, $http, $claim, $responder);
        self::assertNotEmpty($stage);
        self::assertSame(1, DB::table('c02_guard_stages')->count());
        self::assertSame('processing', DB::table('seller_turns')->where('id', $claim->turnId)->value('status'));
        try {
            $migration->down();
            self::fail('Migration discarded protected model guard history.');
        } catch (\RuntimeException $error) {
            self::assertSame('Cannot discard protected guard stage history.', $error->getMessage());
        }
        self::assertSame(1, DB::table('c02_guard_stages')->count());
        unlink($file);
        Http::assertNothingSent();
    }

    public function test_new_input_during_model_guard_persistence_keeps_accounting_and_hides_decision(): void
    {
        config(['agent.guard_llm' => true]);
        [$tenant, $http, $claim, $responder] = $this->modelGuardResponder();
        [$stage, , , $file] = $this->modelGuardFixture($tenant, $http, $claim, $responder, ['costMicros' => 2]);
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION c02_test_delay_model_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF NEW.result_encrypted IS NOT NULL AND OLD.result_encrypted IS NULL THEN
    PERFORM pg_advisory_xact_lock(8620252);
  END IF;
  RETURN NEW;
END;
$$;
CREATE TRIGGER c02_test_delay_model_guard BEFORE UPDATE ON ai_calls FOR EACH ROW EXECUTE FUNCTION c02_test_delay_model_guard();
SQL);
        config(['database.connections.c02_model_guard_advisory' => config('database.connections.pgsql')]);
        $blocker = DB::connection('c02_model_guard_advisory');
        $blocker->beginTransaction();
        $blocker->select('SELECT pg_advisory_xact_lock(8620252)');
        $worker = new Process([PHP_BINARY, '-d', 'opcache.enable_cli=0', '-d', 'allow_url_fopen=0',
            '-d', 'disable_functions=fsockopen,pfsockopen,stream_socket_client,curl_exec,curl_multi_exec',
            base_path('tests/Feature/Ai/Fixtures/protected-model-guard-worker.php')], base_path());
        $worker->setInput(json_encode(['org' => $tenant->organizationId, 'brand' => $tenant->brandId,
            'user' => $tenant->actorId, 'member' => $tenant->membershipId, 'session' => $http->session()->getId(),
            'turn' => $claim->turnId, 'conversation' => $claim->conversationId, 'version' => $claim->baseVersion,
            'token' => $claim->token(), 'stage' => $stage, 'fixtures' => dirname($file)], JSON_THROW_ON_ERROR));
        $worker->setTimeout(45);
        $inputRecorded = false;
        try {
            $worker->start();
            $deadline = microtime(true) + 35;
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
                DB::purge('c02_model_guard_advisory');
            }
        }
        try {
            $worker->wait();
            self::assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
            self::assertSame(['status' => 'completed'], json_decode($worker->getOutput(), true, 512, JSON_THROW_ON_ERROR));
            $this->within($tenant, function () use ($http, $claim, $stage): void {
                try {
                    DB::transaction(fn () => app(Gateway::class)->readProtectedModelGuardLocked($http, $claim, $stage));
                    self::fail('A stale model decision was exposed.');
                } catch (LogicException $error) {
                    self::assertSame('Protected model guard result unavailable.', $error->getMessage());
                }
            });
            $call = DB::table('c02_guard_stages')->where('id', $stage)->value('ai_call_id');
            self::assertSame('completed', DB::table('ai_calls')->where('id', $call)->value('status'));
            self::assertSame(2, (int) DB::table('ai_budgets')->where('organization_id', $tenant->organizationId)->value('spent_micros'));
            self::assertSame('uncertain', DB::table('seller_turns')->where('id', $claim->turnId)->value('status'));
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
            DB::purge('c02_model_guard_advisory');
        }
    }

    public function test_model_guard_confirmed_driver_error_is_not_retried(): void
    {
        config(['agent.guard_llm' => true]);
        [$tenant, $http, $claim, $responder] = $this->modelGuardResponder();
        [$stage, , , $file] = $this->modelGuardFixture($tenant, $http, $claim, $responder);
        $contents = file_get_contents($file);
        self::assertIsString($contents);
        unlink($file);
        $this->within($tenant, function () use ($http, $claim, $stage, $file, $contents): void {
            try {
                app(Gateway::class)->completeProtectedModelGuard($http, $claim, $stage);
                self::fail('A missing fake fixture became a successful result.');
            } catch (LogicException $error) {
                self::assertSame('Protected model guard stage could not be completed.', $error->getMessage());
            }
            file_put_contents($file, $contents);
            try {
                app(Gateway::class)->completeProtectedModelGuard($http, $claim, $stage);
                self::fail('The failed call was automatically retried.');
            } catch (LogicException $error) {
                self::assertSame('Protected model guard stage could not be completed.', $error->getMessage());
            }
            try {
                DB::transaction(fn () => app(Gateway::class)->readProtectedModelGuardLocked($http, $claim, $stage));
                self::fail('A failed model call produced a decision.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
            $call = DB::table('c02_guard_stages')->where('id', $stage)->value('ai_call_id');
            self::assertSame('failed', DB::table('ai_calls')->where('id', $call)->value('status'));
            self::assertSame(3, DB::table('ai_calls')->count());
            self::assertSame(0, DB::table('seller_tasks')->count());
            unlink($file);
        });
        Http::assertNothingSent();
    }

    public function test_first_text_is_finalized_from_verified_sources_once_and_replays(): void
    {
        [$tenant, $http, $claim, $extractor] = $this->fixture('Synthetic input', ['protected_claim' => true]);
        [$stage, , , $file] = $this->responderFixture($tenant, $http, $claim, $extractor, ['content' => 'Можем обсудить заказ?']);
        $this->within($tenant, function () use ($http, $claim, $stage, $file): void {
            self::assertSame('completed', app(Gateway::class)->completeProtectedResponder($http, $claim, $stage));
            $service = app(FirstTurnTextFinalizer::class);
            $result = $service->finalize($http, $claim, $stage, null, 'finalize-key');
            self::assertSame($result, $service->finalize($http, $claim, $stage, null, 'finalize-key'));
            self::assertSame(1, $result['version']);
            self::assertSame('completed', DB::table('seller_turns')->where('id', $claim->turnId)->value('status'));
            self::assertSame('Можем обсудить заказ?', DB::table('messages')->where('id', $result['message_id'])->value('text'));
            self::assertSame(2, DB::table('messages')->where('conversation_id', $claim->conversationId)->count());
            self::assertSame(1, DB::table('conversations')->where('id', $claim->conversationId)->value('version'));
            self::assertSame(1, DB::table('protected_operation_receipts')->where('operation', 'seller.first_text_finalize')->count());
            try {
                $service->finalize($http, $claim, (string) Str::ulid(), null, 'finalize-key');
                self::fail('Different content reused a key.');
            } catch (IdempotencyConflict) {
                self::assertTrue(true);
            }
            self::assertSame(0, DB::table('seller_tasks')->count());
            self::assertSame(0, DB::table('outbox_events')->count());
            unlink($file);
        });
        Http::assertNothingSent();
        Mail::assertNothingOutgoing();
        Queue::assertNothingPushed();
    }

    public function test_first_text_rejects_public_claim_even_when_a_prior_receipt_exists(): void
    {
        [$tenant, $http, $command] = $this->protectedClaimFixture();
        $claim = app(ConversationRepository::class)->claimTurn($tenant, $command);
        $responder = (string) Str::ulid();
        $this->within($tenant, function () use ($tenant, $http, $claim, $responder, $command): void {
            $service = app(FirstTurnTextFinalizer::class);
            try {
                $service->finalize($http, $claim, $responder, null, 'legacy-claim');
                self::fail('An ordinary public claim authorized finalization.');
            } catch (LogicException $error) {
                self::assertSame('Protected first-turn finalization unavailable.', $error->getMessage());
                self::assertNull($error->getPrevious());
                $trace = json_encode($error->getTrace(), JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_THROW_ON_ERROR);
                self::assertStringNotContainsString($claim->token(), $trace);
                self::assertStringNotContainsString($http->session()->getId(), $trace);
                self::assertStringNotContainsString($command->text, $trace);
            }
            self::assertSame(0, DB::table('protected_operation_receipts')->where('operation', 'seller.first_text_finalize')->count());
            $selection = app(AuthSessionRegistry::class)->withinActive($http, fn () => app(SessionBrandSelection::class)->current($http));
            $secret = (string) config('app.key');
            $requestHash = CanonicalJson::hash(['turn' => $claim->turnId, 'conversation' => $claim->conversationId,
                'base_version' => $claim->baseVersion, 'responder' => $responder, 'model_guard' => null,
                'actor' => $tenant->actorId, 'session' => hash_hmac('sha256', 'c02-finalizer-session:'.$http->session()->getId(), $secret),
                'selection_revision' => (string) $selection->brand_revision, 'claim' => hash_hmac('sha256', 'c02-finalizer-claim:'.$claim->token(), $secret)]);
            app(PostgresTransactionalIdempotency::class)->execute($tenant->organizationId, $tenant->brandId, 'seller.first_text_finalize',
                'legacy-claim', $requestHash, fn () => ['turn_id' => $claim->turnId, 'message_id' => (string) Str::ulid(), 'version' => 1]);
            try {
                $service->finalize($http, $claim, $responder, null, 'legacy-claim');
                self::fail('An old receipt bypassed protected claim origin.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
            self::assertSame(1, DB::table('messages')->where('conversation_id', $claim->conversationId)->count());
            self::assertSame('processing', DB::table('seller_turns')->where('id', $claim->turnId)->value('status'));
            self::assertSame([], json_decode(DB::table('conversations')->where('id', $claim->conversationId)->value('state'), true, 512, JSON_THROW_ON_ERROR));
            self::assertSame(0, DB::table('conversations')->where('id', $claim->conversationId)->value('version'));
            self::assertSame(0, DB::table('audit_logs')->where('action', 'seller.turn.text_finalized')->count());
            self::assertSame(0, DB::table('seller_tasks')->count());
            self::assertSame(0, DB::table('outbox_events')->count());
        });
        Http::assertNothingSent();
        Mail::assertNothingOutgoing();
        Queue::assertNothingPushed();
    }

    public function test_first_text_rejects_receipt_without_matching_completed_turn(): void
    {
        [$tenant, $http, $claim, $extractor] = $this->fixture('Protected input', ['protected_claim' => true]);
        [$responder, , , $file] = $this->responderFixture($tenant, $http, $claim, $extractor, ['content' => 'Можем обсудить заказ?']);
        $this->within($tenant, function () use ($tenant, $http, $claim, $responder, $file): void {
            app(Gateway::class)->completeProtectedResponder($http, $claim, $responder);
            $selection = app(AuthSessionRegistry::class)->withinActive($http, fn () => app(SessionBrandSelection::class)->current($http));
            $secret = (string) config('app.key');
            $requestHash = CanonicalJson::hash(['turn' => $claim->turnId, 'conversation' => $claim->conversationId,
                'base_version' => $claim->baseVersion, 'responder' => $responder, 'model_guard' => null,
                'actor' => $tenant->actorId, 'session' => hash_hmac('sha256', 'c02-finalizer-session:'.$http->session()->getId(), $secret),
                'selection_revision' => (string) $selection->brand_revision, 'claim' => hash_hmac('sha256', 'c02-finalizer-claim:'.$claim->token(), $secret)]);
            app(PostgresTransactionalIdempotency::class)->execute($tenant->organizationId, $tenant->brandId, 'seller.first_text_finalize',
                'incomplete-receipt', $requestHash, fn () => ['turn_id' => $claim->turnId, 'message_id' => (string) Str::ulid(), 'version' => 1]);
            foreach (['processing', 'uncertain'] as $status) {
                if ($status === 'uncertain') {
                    Message::create(['conversation_id' => $claim->conversationId, 'role' => 'client', 'text' => 'Later input', 'meta' => []]);
                }
                self::assertSame($status, DB::table('seller_turns')->where('id', $claim->turnId)->value('status'));
                try {
                    app(FirstTurnTextFinalizer::class)->finalize($http, $claim, $responder, null, 'incomplete-receipt');
                    self::fail('A receipt without a completed turn was trusted.');
                } catch (LogicException) {
                    self::assertTrue(true);
                }
            }
            self::assertSame(0, DB::table('messages')->where('conversation_id', $claim->conversationId)->where('role', 'agent')->count());
            self::assertSame(0, DB::table('conversations')->where('id', $claim->conversationId)->value('version'));
            self::assertSame(0, DB::table('audit_logs')->where('action', 'seller.turn.text_finalized')->count());
            unlink($file);
        });
        Http::assertNothingSent();
    }

    public function test_first_text_replay_rejects_changed_message_and_outcome_even_with_recomputed_hash(): void
    {
        [$tenant, $http, $claim, $extractor] = $this->fixture('Protected input', ['protected_claim' => true]);
        [$responder, , , $file] = $this->responderFixture($tenant, $http, $claim, $extractor, ['content' => 'Можем обсудить заказ?']);
        $this->within($tenant, function () use ($http, $claim, $responder, $file): void {
            app(Gateway::class)->completeProtectedResponder($http, $claim, $responder);
            $service = app(FirstTurnTextFinalizer::class);
            $result = $service->finalize($http, $claim, $responder, null, 'altered-outcome');
            self::assertArrayNotHasKey('outcome_seal', $result);
            $stored = DB::table('seller_turns')->where('id', $claim->turnId)->first();
            $outcome = json_decode($stored->outcome, true, 512, JSON_THROW_ON_ERROR);
            $outcome['messages'][0]['text'] = 'Altered synthetic output';
            DB::table('messages')->where('id', $result['message_id'])->update(['text' => 'Altered synthetic output']);
            DB::table('seller_turns')->where('id', $claim->turnId)->update([
                'outcome' => json_encode($outcome, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR),
                'outcome_hash' => \App\Seller\Conversations\CanonicalJson::hash($outcome),
            ]);
            try {
                $service->finalize($http, $claim, $responder, null, 'altered-outcome');
                self::fail('A recomputed public hash bypassed the sealed receipt.');
            } catch (LogicException $error) {
                self::assertSame('Protected first-turn finalization unavailable.', $error->getMessage());
                self::assertNull($error->getPrevious());
            }
            self::assertSame(2, DB::table('messages')->where('conversation_id', $claim->conversationId)->count());
            self::assertSame(1, DB::table('audit_logs')->where('action', 'seller.turn.text_finalized')->count());
            unlink($file);
        });
        Http::assertNothingSent();
    }

    public function test_first_text_finalizer_rejects_forgery_revocation_and_stale_input(): void
    {
        [$tenant, $http, $claim, $extractor] = $this->fixture('First input', ['protected_claim' => true]);
        [$stage, , , $file] = $this->responderFixture($tenant, $http, $claim, $extractor, ['content' => 'Можем обсудить заказ?']);
        $this->within($tenant, fn () => app(Gateway::class)->completeProtectedResponder($http, $claim, $stage));
        unlink($file);
        [$otherTenant, $otherHttp, $otherClaim, $otherExtractor] = $this->fixture('Other input', ['protected_claim' => true]);
        [$otherStage, , , $otherFile] = $this->responderFixture($otherTenant, $otherHttp, $otherClaim, $otherExtractor, ['content' => 'Можем обсудить заказ?']);
        $this->within($otherTenant, fn () => app(Gateway::class)->completeProtectedResponder($otherHttp, $otherClaim, $otherStage));
        unlink($otherFile);
        $this->within($tenant, function () use ($tenant, $http, $claim, $stage, $otherStage): void {
            $service = app(FirstTurnTextFinalizer::class);
            $forged = new TurnClaim('claimed', $claim->turnId, $claim->conversationId, $claim->organizationId, $claim->brandId, $claim->baseVersion, str_repeat('a', 64));
            foreach ([[$forged, $stage], [$claim, $otherStage], [$claim, (string) Str::ulid()]] as [$turn, $source]) {
                try {
                    $service->finalize($http, $turn, $source, null, 'forgery-key');
                    self::fail('Forged provenance accepted.');
                } catch (LogicException $error) {
                    self::assertSame('Protected first-turn finalization unavailable.', $error->getMessage());
                    self::assertNull($error->getPrevious());
                    $trace = json_encode($error->getTrace(), JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_THROW_ON_ERROR);
                    self::assertStringNotContainsString($turn->token(), $trace);
                    self::assertStringNotContainsString($http->session()->getId(), $trace);
                }
            }
            DB::table('brand_modules')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->where('module', 'seller')->update(['enabled' => false]);
            try {
                $service->finalize($http, $claim, $stage, null, 'revoked-key');
                self::fail('Revoked module accepted.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
            DB::table('brand_modules')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->where('module', 'seller')->update(['enabled' => true]);
            DB::table('brands')->where('id', $tenant->brandId)->update(['status' => 'archived']);
            try {
                $service->finalize($http, $claim, $stage, null, 'archived-brand');
                self::fail('An archived brand finalized a response.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
            DB::table('brands')->where('id', $tenant->brandId)->update(['status' => 'active']);
            self::assertSame(1, DB::table('messages')->where('conversation_id', $claim->conversationId)->count());
            $call = DB::table('c02_responder_stages')->where('id', $stage)->value('ai_call_id');
            $original = DB::table('ai_calls')->where('id', $call)->value('result_encrypted');
            $otherCall = DB::table('c02_responder_stages')->where('id', $otherStage)->value('ai_call_id');
            DB::table('ai_calls')->where('id', $call)->update(['result_encrypted' => DB::table('ai_calls')->where('id', $otherCall)->value('result_encrypted')]);
            try {
                $service->finalize($http, $claim, $stage, null, 'tampered-result');
                self::fail('Tampered responder result accepted.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
            DB::table('ai_calls')->where('id', $call)->update(['result_encrypted' => $original]);
            Message::create(['conversation_id' => $claim->conversationId, 'role' => 'client', 'text' => 'New input', 'meta' => []]);
            try {
                $service->finalize($http, $claim, $stage, null, 'stale-key');
                self::fail('Stale candidate accepted.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
            self::assertSame('uncertain', DB::table('seller_turns')->where('id', $claim->turnId)->value('status'));
            self::assertSame(2, DB::table('messages')->where('conversation_id', $claim->conversationId)->count());
            self::assertSame(0, DB::table('protected_operation_receipts')->where('operation', 'seller.first_text_finalize')->count());
        });
        $this->within($otherTenant, function () use ($otherHttp, $otherClaim, $otherStage): void {
            app(AuthSessionRegistry::class)->withinActive($otherHttp, fn () => app(AuthSessionRegistry::class)->revokeCurrent($otherHttp));
            try {
                app(FirstTurnTextFinalizer::class)->finalize($otherHttp, $otherClaim, $otherStage, null, 'revoked-session');
                self::fail('Revoked session accepted.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
        });
        self::assertSame(0, DB::table('seller_tasks')->count());
        self::assertSame(0, DB::table('outbox_events')->count());
        Http::assertNothingSent();
    }

    public function test_finalized_receipt_requires_current_authorization_even_after_completion(): void
    {
        [$tenant, $http, $claim, $extractor] = $this->fixture('Synthetic question', ['protected_claim' => true]);
        [$stage, , , $file] = $this->responderFixture($tenant, $http, $claim, $extractor, ['content' => 'Можем обсудить заказ?']);
        $this->within($tenant, function () use ($tenant, $http, $claim, $stage, $file): void {
            app(Gateway::class)->completeProtectedResponder($http, $claim, $stage);
            $service = app(FirstTurnTextFinalizer::class);
            $result = $service->finalize($http, $claim, $stage, null, 'replay-authorization');
            DB::table('brand_modules')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->where('module', 'seller')->update(['enabled' => false]);
            try {
                $service->finalize($http, $claim, $stage, null, 'replay-authorization');
                self::fail('Revoked access replayed a receipt.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
            DB::table('brand_modules')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->where('module', 'seller')->update(['enabled' => true]);
            self::assertSame($result, $service->finalize($http, $claim, $stage, null, 'replay-authorization'));
            DB::table('brands')->where('id', $tenant->brandId)->update(['status' => 'archived']);
            try {
                $service->finalize($http, $claim, $stage, null, 'replay-authorization');
                self::fail('An archived brand replayed a receipt.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
            DB::table('brands')->where('id', $tenant->brandId)->update(['status' => 'active']);
            $otherBrand = Brand::create(['name' => 'Other synthetic brand', 'timezone' => 'Europe/Moscow']);
            app(AuthSessionRegistry::class)->withinActive($http, function () use ($http, $tenant, $otherBrand): void {
                $selection = app(SessionBrandSelection::class);
                $row = $selection->current($http);
                $selection->select($http, $tenant->organizationId, $otherBrand->id, (string) $row->brand_revision);
            });
            try {
                $service->finalize($http, $claim, $stage, null, 'replay-authorization');
                self::fail('A different selected brand replayed a receipt.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
            app(AuthSessionRegistry::class)->withinActive($http, fn () => app(AuthSessionRegistry::class)->revokeCurrent($http));
            try {
                $service->finalize($http, $claim, $stage, null, 'replay-authorization');
                self::fail('Revoked session replayed a receipt.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
            self::assertSame(2, DB::table('messages')->where('conversation_id', $claim->conversationId)->count());
            unlink($file);
        });
        Http::assertNothingSent();
    }

    public function test_first_text_waits_for_required_model_guard_and_rejects_effects(): void
    {
        config(['agent.guard_llm' => true]);
        [$tenant, $http, $claim, $responder] = $this->modelGuardResponder(protectedClaim: true);
        $this->within($tenant, function () use ($http, $claim, $responder): void {
            try {
                app(FirstTurnTextFinalizer::class)->finalize($http, $claim, $responder, null, 'guard-required');
                self::fail('Required model guard was skipped.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
            self::assertSame(1, DB::table('messages')->where('conversation_id', $claim->conversationId)->count());
        });
        [$guard, , , $file] = $this->modelGuardFixture($tenant, $http, $claim, $responder);
        $this->within($tenant, function () use ($http, $claim, $responder, $guard, $file): void {
            self::assertSame('completed', app(Gateway::class)->completeProtectedModelGuard($http, $claim, $guard));
            $result = app(FirstTurnTextFinalizer::class)->finalize($http, $claim, $responder, $guard, 'guard-required');
            self::assertSame(1, $result['version']);
            self::assertSame('completed', DB::table('seller_turns')->where('id', $claim->turnId)->value('status'));
            unlink($file);
        });
        config(['agent.guard_llm' => false]);
        [$otherTenant, $otherHttp, $otherClaim, $extractor] = $this->fixture('Пришлите реквизиты', ['protected_claim' => true, 'content' => '{"client_intent":"ask_requisites"}']);
        [$otherStage, , , $otherFile] = $this->responderFixture($otherTenant, $otherHttp, $otherClaim, $extractor, ['content' => 'Пришлю реквизиты. Какой у вас город?']);
        $this->within($otherTenant, function () use ($otherHttp, $otherClaim, $otherStage, $otherFile): void {
            app(Gateway::class)->completeProtectedResponder($otherHttp, $otherClaim, $otherStage);
            try {
                app(FirstTurnTextFinalizer::class)->finalize($otherHttp, $otherClaim, $otherStage, null, 'requires-effect');
                self::fail('Mandatory external effect was silently omitted.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
            self::assertSame(1, DB::table('messages')->where('conversation_id', $otherClaim->conversationId)->count());
            self::assertSame('processing', DB::table('seller_turns')->where('id', $otherClaim->turnId)->value('status'));
            unlink($otherFile);
        });
        self::assertSame(0, DB::table('seller_tasks')->count());
        self::assertSame(0, DB::table('outbox_events')->count());
        Http::assertNothingSent();
        Mail::assertNothingOutgoing();
        Queue::assertNothingPushed();
    }

    public function test_first_text_postprocessing_matches_the_unchanged_donor_golden(): void
    {
        $golden = json_decode(file_get_contents(base_path('tests/fixtures/prototype/synthetic/open-turn.json')), true, 512, JSON_THROW_ON_ERROR)['trace'][1];
        [$tenant, $http, $claim, $extractor] = $this->fixture('Тула, на продажу.', [
            'protected_claim' => true,
            'content' => json_encode($golden['meta']['extraction'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);
        [$stage, , , $file] = $this->responderFixture($tenant, $http, $claim, $extractor, ['content' => $golden['reply']]);
        $this->within($tenant, function () use ($http, $claim, $stage, $file, $golden): void {
            app(Gateway::class)->completeProtectedResponder($http, $claim, $stage);
            $result = app(FirstTurnTextFinalizer::class)->finalize($http, $claim, $stage, null, 'golden-finalize');
            $turn = DB::table('seller_turns')->where('id', $claim->turnId)->first();
            $saved = json_decode($turn->outcome, true, 512, JSON_THROW_ON_ERROR);
            $state = json_decode(DB::table('conversations')->where('id', $claim->conversationId)->value('state'), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(CanonicalJson::hash($golden['meta']['goal']), CanonicalJson::hash($saved['goal']));
            self::assertSame($golden['reply'], DB::table('messages')->where('id', $result['message_id'])->value('text'));
            self::assertSame($golden['state']['signals']['last_asked_slot'], $state['signals']['last_asked_slot']);
            self::assertSame($golden['state']['signals']['asked_counts']['experience'], $state['signals']['asked_counts']['experience']);
            self::assertSame(CanonicalJson::hash($golden['state']['missing']), CanonicalJson::hash($state['missing']));
            // The golden has an earlier proactive greeting; this path starts with the client.
            self::assertFalse($state['flags']['introduced']);
            self::assertTrue($golden['state']['flags']['introduced']);
            unlink($file);
        });
        Http::assertNothingSent();
    }

    public function test_first_text_never_saves_unknown_or_failed_guard_or_tool_signal(): void
    {
        config(['agent.guard_llm' => true]);
        foreach ([['costMicros' => null], ['content' => '{"ok":false,"issues":["synthetic issue"]}']] as $guardResult) {
            [$tenant, $http, $claim, $responder] = $this->modelGuardResponder(protectedClaim: true);
            [$guard, , , $file] = $this->modelGuardFixture($tenant, $http, $claim, $responder, $guardResult);
            $this->within($tenant, function () use ($http, $claim, $responder, $guard, $file, $guardResult): void {
                self::assertSame(array_key_exists('costMicros', $guardResult) ? 'uncertain' : 'completed', app(Gateway::class)->completeProtectedModelGuard($http, $claim, $guard));
                try {
                    app(FirstTurnTextFinalizer::class)->finalize($http, $claim, $responder, $guard, 'guard-not-passed');
                    self::fail('Unknown or failed guard was accepted.');
                } catch (LogicException) {
                    self::assertTrue(true);
                }
                self::assertSame(1, DB::table('messages')->where('conversation_id', $claim->conversationId)->count());
                self::assertSame('processing', DB::table('seller_turns')->where('id', $claim->turnId)->value('status'));
                unlink($file);
            });
        }
        config(['agent.guard_llm' => false]);
        [$tenant, $http, $claim, $extractor] = $this->fixture('Synthetic question', ['protected_claim' => true]);
        [$stage, , , $file] = $this->responderFixture($tenant, $http, $claim, $extractor, [
            'content' => 'Ignored', 'toolCalls' => [['id' => 'synthetic-tool', 'name' => 'send_price', 'arguments' => []]],
        ]);
        $this->within($tenant, function () use ($http, $claim, $stage, $file): void {
            app(Gateway::class)->completeProtectedResponder($http, $claim, $stage);
            try {
                app(FirstTurnTextFinalizer::class)->finalize($http, $claim, $stage, null, 'tool-signal');
                self::fail('Unexecuted tool signal was accepted as text.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
            self::assertSame(1, DB::table('messages')->where('conversation_id', $claim->conversationId)->count());
            unlink($file);
        });
        self::assertSame(0, DB::table('protected_operation_receipts')->where('operation', 'seller.first_text_finalize')->count());
        self::assertSame(0, DB::table('seller_tasks')->count());
        self::assertSame(0, DB::table('outbox_events')->count());
        Http::assertNothingSent();
        Mail::assertNothingOutgoing();
        Queue::assertNothingPushed();
    }

    public function test_first_text_new_input_first_invalidates_worker_without_losing_input(): void
    {
        [$tenant, $http, $claim, $extractor] = $this->fixture('Synthetic question', ['protected_claim' => true]);
        [$stage, , , $file] = $this->responderFixture($tenant, $http, $claim, $extractor, ['content' => 'Можем обсудить заказ?']);
        $this->within($tenant, fn () => app(Gateway::class)->completeProtectedResponder($http, $claim, $stage));
        unlink($file);
        $marker = base_path('.local/tmp/c02-text-incoming-'.Str::random(16));
        $worker = $this->finalizeWorker($tenant, $http, $claim, $stage, $marker);
        $this->within($tenant, function () use ($claim, $worker, $marker): void {
            DB::beginTransaction();
            try {
                $new = Message::create(['conversation_id' => $claim->conversationId, 'role' => 'client', 'text' => 'New input wins', 'meta' => []]);
                $worker->start();
                $this->waitForFinalizerLock($worker, $marker);
                DB::commit();
                $worker->wait();
                self::assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
                self::assertSame(['status' => 'rejected'], json_decode($worker->getOutput(), true, 512, JSON_THROW_ON_ERROR));
                self::assertSame('New input wins', DB::table('messages')->where('id', $new->id)->value('text'));
                self::assertSame(2, DB::table('messages')->where('conversation_id', $claim->conversationId)->count());
                self::assertSame('uncertain', DB::table('seller_turns')->where('id', $claim->turnId)->value('status'));
                self::assertSame(0, DB::table('conversations')->where('id', $claim->conversationId)->value('version'));
            } finally {
                if (DB::transactionLevel() > 0) {
                    DB::rollBack();
                }
                if ($worker->isRunning()) {
                    $worker->stop();
                }
                if (is_file($marker)) {
                    unlink($marker);
                }
            }
        });
        self::assertSame(0, DB::table('protected_operation_receipts')->where('operation', 'seller.first_text_finalize')->count());
        Http::assertNothingSent();
    }

    public function test_first_text_audit_failure_rolls_back_response_state_turn_and_receipt(): void
    {
        [$tenant, $http, $claim, $extractor] = $this->fixture('Synthetic question', ['protected_claim' => true]);
        [$stage, , , $file] = $this->responderFixture($tenant, $http, $claim, $extractor, ['content' => 'Можем обсудить заказ?']);
        $this->within($tenant, fn () => app(Gateway::class)->completeProtectedResponder($http, $claim, $stage));
        unlink($file);
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION c02_test_fail_text_audit() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF NEW.action = 'seller.turn.text_finalized' THEN RAISE EXCEPTION 'synthetic audit failure'; END IF;
  RETURN NEW;
END;
$$;
CREATE TRIGGER c02_test_fail_text_audit BEFORE INSERT ON audit_logs FOR EACH ROW EXECUTE FUNCTION c02_test_fail_text_audit();
SQL);
        try {
            $this->within($tenant, function () use ($http, $claim, $stage): void {
                try {
                    app(FirstTurnTextFinalizer::class)->finalize($http, $claim, $stage, null, 'audit-failure');
                    self::fail('Failed audit committed a turn.');
                } catch (LogicException $error) {
                    self::assertSame('Protected first-turn finalization unavailable.', $error->getMessage());
                    self::assertNull($error->getPrevious());
                }
                self::assertSame(1, DB::table('messages')->where('conversation_id', $claim->conversationId)->count());
                self::assertSame('processing', DB::table('seller_turns')->where('id', $claim->turnId)->value('status'));
                self::assertSame(0, DB::table('conversations')->where('id', $claim->conversationId)->value('version'));
                self::assertSame([], json_decode(DB::table('conversations')->where('id', $claim->conversationId)->value('state'), true, 512, JSON_THROW_ON_ERROR));
                self::assertSame(0, DB::table('protected_operation_receipts')->where('operation', 'seller.first_text_finalize')->count());
            });
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS c02_test_fail_text_audit ON audit_logs; DROP FUNCTION IF EXISTS c02_test_fail_text_audit();');
        }
        $this->within($tenant, function () use ($http, $claim, $stage): void {
            $result = app(FirstTurnTextFinalizer::class)->finalize($http, $claim, $stage, null, 'audit-failure');
            self::assertSame(1, $result['version']);
            self::assertSame(2, DB::table('messages')->where('conversation_id', $claim->conversationId)->count());
            self::assertSame(2, DB::table('ai_calls')->count());
        });
        Http::assertNothingSent();
    }

    public function test_first_text_finishes_before_concurrent_input_and_replays_across_processes(): void
    {
        [$tenant, $http, $claim, $extractor] = $this->fixture('Synthetic question', ['protected_claim' => true]);
        [$stage, , , $file] = $this->responderFixture($tenant, $http, $claim, $extractor, ['content' => 'Можем обсудить заказ?']);
        $this->within($tenant, fn () => app(Gateway::class)->completeProtectedResponder($http, $claim, $stage));
        unlink($file);
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION c02_test_delay_text_finalize() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF NEW.action = 'seller.turn.text_finalized' THEN
    PERFORM pg_advisory_xact_lock(8620472);
  END IF;
  RETURN NEW;
END;
$$;
CREATE TRIGGER c02_test_delay_text_finalize BEFORE INSERT ON audit_logs FOR EACH ROW EXECUTE FUNCTION c02_test_delay_text_finalize();
SQL);
        config(['database.connections.c02_finalizer_blocker' => config('database.connections.pgsql')]);
        $blocker = DB::connection('c02_finalizer_blocker');
        $blocker->beginTransaction();
        $blocker->select('SELECT pg_advisory_xact_lock(8620472)');
        $firstMarker = base_path('.local/tmp/c02-finalize-a-'.Str::random(16));
        $secondMarker = base_path('.local/tmp/c02-finalize-b-'.Str::random(16));
        $first = $this->finalizeWorker($tenant, $http, $claim, $stage, $firstMarker);
        $second = $this->finalizeWorker($tenant, $http, $claim, $stage, $secondMarker);
        try {
            $first->start();
            $this->waitForFinalizerLock($first, $firstMarker);
            $second->start();
            $this->waitForFinalizerLock($second, $secondMarker);
            DB::beginTransaction();
            try {
                DB::statement("SET LOCAL lock_timeout = '250ms'");
                DB::table('messages')->insert(['id' => (string) Str::ulid(), 'organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId,
                    'conversation_id' => $claim->conversationId, 'role' => 'client', 'text' => 'Blocked input', 'meta' => '{}']);
                self::fail('Incoming text crossed finalization.');
            } catch (QueryException $error) {
                self::assertSame('55P03', $error->errorInfo[0]);
            } finally {
                DB::rollBack();
            }
            $blocker->rollBack();
            $first->wait();
            $second->wait();
            self::assertTrue($first->isSuccessful(), $first->getErrorOutput());
            self::assertTrue($second->isSuccessful(), $second->getErrorOutput());
            $one = json_decode($first->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('completed', $one['status']);
            self::assertSame($one, json_decode($second->getOutput(), true, 512, JSON_THROW_ON_ERROR));
            $nextInput = $this->within($tenant, fn () => Message::create(['conversation_id' => $claim->conversationId, 'role' => 'client', 'text' => 'Input after finalization', 'meta' => []]));
            self::assertSame(3, DB::table('messages')->where('conversation_id', $claim->conversationId)->count());
            self::assertSame('completed', DB::table('seller_turns')->where('id', $claim->turnId)->value('status'));
            self::assertSame(1, DB::table('conversations')->where('id', $claim->conversationId)->value('version'));
            self::assertSame(1, DB::table('protected_operation_receipts')->where('operation', 'seller.first_text_finalize')->count());
            self::assertSame(1, DB::table('audit_logs')->where('action', 'seller.turn.text_finalized')->count());
            $replay = $this->within($tenant, fn () => app(FirstTurnTextFinalizer::class)->finalize($http, $claim, $stage, null, 'parallel-first-text'));
            self::assertSame($one['result'], $replay);
            $next = app(ConversationRepository::class)->claimTurn($tenant, new TurnCommand($claim->conversationId, $nextInput->id, $nextInput->text, null, 'after-finalization', 1));
            self::assertSame('claimed', $next->status);
        } finally {
            if ($blocker->transactionLevel() > 0) {
                $blocker->rollBack();
            }
            foreach ([$first, $second] as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            foreach ([$firstMarker, $secondMarker] as $marker) {
                if (is_file($marker)) {
                    unlink($marker);
                }
            }
            DB::purge('c02_finalizer_blocker');
            DB::unprepared('DROP TRIGGER IF EXISTS c02_test_delay_text_finalize ON audit_logs; DROP FUNCTION IF EXISTS c02_test_delay_text_finalize();');
        }
        Http::assertNothingSent();
    }

    private function finalizeWorker(TenantContext $tenant, Request $http, TurnClaim $claim, string $stage, string $marker): Process
    {
        $worker = new Process([PHP_BINARY, '-d', 'sys_temp_dir='.base_path('.local/tmp'), '-d', 'allow_url_fopen=0',
            '-d', 'disable_functions=fsockopen,pfsockopen,stream_socket_client,curl_exec,curl_multi_exec',
            base_path('tests/Feature/ProtectedOperations/Fixtures/first-text-finalize-worker.php'), $marker], base_path());
        $worker->setInput(json_encode(['org' => $tenant->organizationId, 'brand' => $tenant->brandId, 'user' => $tenant->actorId,
            'member' => $tenant->membershipId, 'session' => $http->session()->getId(), 'turn' => $claim->turnId,
            'conversation' => $claim->conversationId, 'version' => $claim->baseVersion, 'token' => $claim->token(),
            'stage' => $stage, 'key' => 'parallel-first-text'], JSON_THROW_ON_ERROR));
        $worker->setTimeout(60);

        return $worker;
    }

    private function waitForFinalizerLock(Process $worker, string $marker): void
    {
        $deadline = microtime(true) + 30;
        while (! is_file($marker) && $worker->isRunning() && microtime(true) < $deadline) {
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
        } while (! $waiting && $worker->isRunning() && microtime(true) < $deadline);
        self::assertTrue($waiting, 'Worker must wait for its competing transaction.');
    }

    public function test_protected_claim_is_atomic_idempotent_bound_and_private(): void
    {
        [$tenant, $http, $command] = $this->protectedClaimFixture();
        $this->within($tenant, function () use ($tenant, $http, $command): void {
            $service = app(FirstTurnProtectedClaim::class);
            try {
                DB::transaction(fn () => $service->claim($http, $command));
                self::fail('A claim escaped an enclosing uncommitted transaction.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
            $first = $service->claim($http, $command);
            $repeat = $service->claim($http, $command);
            self::assertSame('claimed', $first->status);
            self::assertSame($first->turnId, $repeat->turnId);
            self::assertSame($first->token(), $repeat->token());
            self::assertSame(1, DB::table('seller_turns')->where('conversation_id', $command->conversationId)->count());
            self::assertSame(1, DB::table('c02_protected_turn_claims')->where('turn_id', $first->turnId)->count());
            self::assertSame(1, DB::table('protected_operation_receipts')->where('operation', 'seller.first_turn_claim')->count());
            self::assertSame(1, DB::table('audit_logs')->where('action', 'seller.turn.protected_claimed')->count());
            DB::transaction(fn () => $service->assertProtectedLocked($http, $first));
            try {
                $service->assertProtectedLocked($http, $first);
                self::fail('Origin was checked without a locked transaction.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
            $different = new TurnCommand($command->conversationId, $command->inputMessageId, 'Different synthetic content', null, $command->idempotencyKey, 0);
            try {
                $service->claim($http, $different);
                self::fail('Changed content reused the key.');
            } catch (IdempotencyConflict) {
                self::assertTrue(true);
            }
            $forged = new TurnClaim('claimed', $first->turnId, $first->conversationId, $first->organizationId, $first->brandId, 0, str_repeat('a', 64));
            try {
                DB::transaction(fn () => $service->assertProtectedLocked($http, $forged));
                self::fail('A forged token passed origin checks.');
            } catch (LogicException $error) {
                self::assertSame('Protected first-turn claim unavailable.', $error->getMessage());
                self::assertNull($error->getPrevious());
                $trace = json_encode($error->getTrace(), JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_THROW_ON_ERROR);
                self::assertStringNotContainsString($first->token(), $trace);
                self::assertStringNotContainsString($http->session()->getId(), $trace);
                self::assertStringNotContainsString($command->text, $trace);
            }
            $stored = json_encode(['claim' => DB::table('c02_protected_turn_claims')->where('turn_id', $first->turnId)->first(),
                'receipt' => DB::table('protected_operation_receipts')->where('operation', 'seller.first_turn_claim')->first()], JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString($first->token(), $stored);
            self::assertStringNotContainsString($http->session()->getId(), $stored);
            self::assertStringNotContainsString($command->text, $stored);
            DB::table('brand_modules')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->where('module', 'seller')->update(['enabled' => false]);
            try {
                $service->claim($http, $command);
                self::fail('A revoked permission replayed the claim.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
            DB::table('brand_modules')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->where('module', 'seller')->update(['enabled' => true]);
            $otherBrand = Brand::create(['name' => 'Other claim brand', 'timezone' => 'Europe/Moscow']);
            app(AuthSessionRegistry::class)->withinActive($http, function () use ($http, $tenant, $otherBrand): void {
                $selection = app(SessionBrandSelection::class);
                $row = $selection->current($http);
                $selection->select($http, $tenant->organizationId, $otherBrand->id, (string) $row->brand_revision);
            });
            try {
                $service->claim($http, $command);
                self::fail('A different selected brand replayed the claim.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
            app(AuthSessionRegistry::class)->withinActive($http, fn () => app(AuthSessionRegistry::class)->revokeCurrent($http));
            try {
                $service->claim($http, $command);
                self::fail('A revoked session replayed the claim.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
        });
        Http::assertNothingSent();
        Mail::assertNothingOutgoing();
        Queue::assertNothingPushed();
    }

    public function test_protected_claim_never_upgrades_an_old_or_foreign_turn(): void
    {
        [$tenant, $http, $command] = $this->protectedClaimFixture();
        $old = app(ConversationRepository::class)->claimTurn($tenant, $command);
        [$otherTenant, $otherHttp, $otherCommand] = $this->protectedClaimFixture();
        $other = $this->within($otherTenant, fn () => app(FirstTurnProtectedClaim::class)->claim($otherHttp, $otherCommand));
        $this->within($tenant, function () use ($tenant, $http, $command, $old, $other): void {
            $service = app(FirstTurnProtectedClaim::class);
            foreach ([$old, $other] as $candidate) {
                try {
                    DB::transaction(fn () => $service->assertProtectedLocked($http, $candidate));
                    self::fail('Old or foreign turn gained protected origin.');
                } catch (LogicException) {
                    self::assertTrue(true);
                }
            }
            try {
                $service->claim($http, $command);
                self::fail('An existing public claim was upgraded.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
            try {
                DB::transaction(fn () => DB::table('c02_protected_turn_claims')->insert([
                    'id' => (string) Str::ulid(), 'organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId,
                    'conversation_id' => $old->conversationId, 'turn_id' => $old->turnId, 'actor_id' => $tenant->actorId,
                    'session_hash' => str_repeat('a', 64), 'selection_revision' => 0, 'request_digest' => str_repeat('b', 64),
                    'claim_hash' => hash('sha256', $old->token()), 'seal' => str_repeat('c', 64),
                ]));
                self::fail('A later database transaction attached a fake provenance.');
            } catch (QueryException $error) {
                self::assertSame('23514', $error->errorInfo[0]);
            }
            self::assertSame(0, DB::table('c02_protected_turn_claims')->where('turn_id', $old->turnId)->count());
            self::assertSame('processing', DB::table('seller_turns')->where('id', $old->turnId)->value('status'));
        });
        Http::assertNothingSent();
    }

    public function test_protected_claim_replay_reports_current_uncertain_or_finished_without_token(): void
    {
        [$tenant, $http, $command] = $this->protectedClaimFixture();
        $this->within($tenant, function () use ($http, $command): void {
            $service = app(FirstTurnProtectedClaim::class);
            $claim = $service->claim($http, $command);
            Message::create(['conversation_id' => $command->conversationId, 'role' => 'client', 'text' => 'Newer input', 'meta' => []]);
            $uncertain = $service->claim($http, $command);
            self::assertSame('uncertain', $uncertain->status);
            self::assertSame($claim->turnId, $uncertain->turnId);
            self::assertNull($uncertain->token());
            self::assertNull($uncertain->outcome);
            try {
                DB::transaction(fn () => $service->assertProtectedLocked($http, $claim));
                self::fail('Uncertain protected claim was treated as current.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
        });
        [$otherTenant, $otherHttp, $otherCommand] = $this->protectedClaimFixture();
        $otherClaim = $this->within($otherTenant, fn () => app(FirstTurnProtectedClaim::class)->claim($otherHttp, $otherCommand));
        $stage = $this->completeExtractorForProtectedClaim($otherTenant, $otherHttp, $otherClaim);
        [$responder, , , $file] = $this->responderFixture($otherTenant, $otherHttp, $otherClaim, $stage, ['content' => 'Можем обсудить заказ?']);
        $this->within($otherTenant, function () use ($otherHttp, $otherCommand, $otherClaim, $responder, $file): void {
            app(Gateway::class)->completeProtectedResponder($otherHttp, $otherClaim, $responder);
            app(FirstTurnTextFinalizer::class)->finalize($otherHttp, $otherClaim, $responder, null, 'claim-lifecycle-finalize');
            $finished = app(FirstTurnProtectedClaim::class)->claim($otherHttp, $otherCommand);
            self::assertSame('finished', $finished->status);
            self::assertSame($otherClaim->turnId, $finished->turnId);
            self::assertNull($finished->token());
            self::assertNull($finished->outcome);
            app(AuthSessionRegistry::class)->withinActive($otherHttp, fn () => app(AuthSessionRegistry::class)->revokeCurrent($otherHttp));
            try {
                app(FirstTurnProtectedClaim::class)->claim($otherHttp, $otherCommand);
                self::fail('A revoked session received a finished outcome.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
            unlink($file);
        });
        Http::assertNothingSent();
        Mail::assertNothingOutgoing();
        Queue::assertNothingPushed();
    }

    public function test_protected_claim_rolls_back_all_records_if_audit_fails(): void
    {
        [$tenant, $http, $command] = $this->protectedClaimFixture();
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION c02_test_fail_claim_audit() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF NEW.action = 'seller.turn.protected_claimed' THEN RAISE EXCEPTION 'synthetic audit failure'; END IF;
  RETURN NEW;
END;
$$;
CREATE TRIGGER c02_test_fail_claim_audit BEFORE INSERT ON audit_logs FOR EACH ROW EXECUTE FUNCTION c02_test_fail_claim_audit();
SQL);
        try {
            $this->within($tenant, function () use ($http, $command): void {
                try {
                    app(FirstTurnProtectedClaim::class)->claim($http, $command);
                    self::fail('Failed audit committed a claim.');
                } catch (LogicException $error) {
                    self::assertSame('Protected first-turn claim unavailable.', $error->getMessage());
                    self::assertNull($error->getPrevious());
                }
                self::assertSame(0, DB::table('seller_turns')->where('conversation_id', $command->conversationId)->count());
                self::assertSame(0, DB::table('c02_protected_turn_claims')->count());
                self::assertSame(0, DB::table('protected_operation_receipts')->where('operation', 'seller.first_turn_claim')->count());
                self::assertSame(1, DB::table('messages')->where('id', $command->inputMessageId)->count());
            });
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS c02_test_fail_claim_audit ON audit_logs; DROP FUNCTION IF EXISTS c02_test_fail_claim_audit();');
        }
        $claim = $this->within($tenant, fn () => app(FirstTurnProtectedClaim::class)->claim($http, $command));
        self::assertSame('claimed', $claim->status);
        self::assertSame(1, DB::table('c02_protected_turn_claims')->where('turn_id', $claim->turnId)->count());
        Http::assertNothingSent();
    }

    public function test_protected_claim_does_not_trust_a_forged_marker_even_in_the_creation_transaction(): void
    {
        [$tenant, $http, $command] = $this->protectedClaimFixture();
        $this->within($tenant, function () use ($tenant, $http, $command): void {
            DB::beginTransaction();
            try {
                $old = app(ConversationRepository::class)->claimTurn($tenant, $command);
                DB::table('c02_protected_turn_claims')->insert([
                    'id' => (string) Str::ulid(), 'organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId,
                    'conversation_id' => $old->conversationId, 'turn_id' => $old->turnId, 'actor_id' => $tenant->actorId,
                    'session_hash' => hash_hmac('sha256', 'c02-protected-claim-session:'.$http->session()->getId(), (string) config('app.key')),
                    'selection_revision' => 0, 'request_digest' => str_repeat('b', 64), 'claim_hash' => hash('sha256', $old->token()),
                    'seal' => str_repeat('c', 64),
                ]);
                try {
                    app(FirstTurnProtectedClaim::class)->assertProtectedLocked($http, $old);
                    self::fail('A fabricated origin seal was trusted.');
                } catch (LogicException) {
                    self::assertTrue(true);
                }
            } finally {
                DB::rollBack();
            }
            self::assertSame(0, DB::table('seller_turns')->where('conversation_id', $command->conversationId)->count());
            self::assertSame(0, DB::table('c02_protected_turn_claims')->where('conversation_id', $command->conversationId)->count());
        });
        Http::assertNothingSent();
    }

    public function test_protected_claim_migration_preserves_old_turns_and_refuses_history_deletion(): void
    {
        $migration = require database_path('migrations/C-02/2026_11_01_090000_c02_create_protected_turn_claims.php');
        $migration->down();
        [$tenant, $http, $command] = $this->protectedClaimFixture();
        $old = app(ConversationRepository::class)->claimTurn($tenant, $command);
        $migration->up();
        $stored = DB::table('seller_turns')->where('id', $old->turnId)->first();
        self::assertNull($stored->c02_claim_creation_xid);
        self::assertNull($stored->c02_claim_reservation_digest);
        $this->within($tenant, function () use ($http, $command, $old): void {
            try {
                app(FirstTurnProtectedClaim::class)->claim($http, $command);
                self::fail('An old turn was silently upgraded.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
            try {
                DB::transaction(fn () => app(FirstTurnProtectedClaim::class)->assertProtectedLocked($http, $old));
                self::fail('A migrated turn acquired protected origin.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
        });
        [$newTenant, $newHttp, $newCommand] = $this->protectedClaimFixture();
        $new = $this->within($newTenant, fn () => app(FirstTurnProtectedClaim::class)->claim($newHttp, $newCommand));
        foreach (['update', 'delete', 'truncate', 'turn-origin'] as $operation) {
            try {
                DB::transaction(function () use ($operation, $new): void {
                    match ($operation) {
                        'update' => DB::table('c02_protected_turn_claims')->where('turn_id', $new->turnId)->update(['seal' => str_repeat('a', 64)]),
                        'delete' => DB::table('c02_protected_turn_claims')->where('turn_id', $new->turnId)->delete(),
                        'truncate' => DB::statement('TRUNCATE c02_protected_turn_claims'),
                        'turn-origin' => DB::table('seller_turns')->where('id', $new->turnId)->update(['c02_claim_reservation_digest' => null]),
                    };
                });
                self::fail('Protected provenance history was changed.');
            } catch (QueryException $error) {
                self::assertSame('23514', $error->errorInfo[0]);
            }
        }
        try {
            $migration->down();
            self::fail('Migration discarded protected history.');
        } catch (\RuntimeException) {
            self::assertTrue(true);
        }
        self::assertSame('processing', DB::table('seller_turns')->where('id', $old->turnId)->value('status'));
        self::assertSame(1, DB::table('c02_protected_turn_claims')->where('turn_id', $new->turnId)->count());
        Http::assertNothingSent();
    }

    public function test_protected_claim_serializes_two_processes_and_returns_one_origin(): void
    {
        [$tenant, $http, $command] = $this->protectedClaimFixture();
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION c02_test_delay_claim() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN PERFORM pg_advisory_xact_lock(8620473); RETURN NEW; END;
$$;
CREATE TRIGGER c02_test_delay_claim BEFORE INSERT ON c02_protected_turn_claims FOR EACH ROW EXECUTE FUNCTION c02_test_delay_claim();
SQL);
        config(['database.connections.c02_claim_blocker' => config('database.connections.pgsql')]);
        $blocker = DB::connection('c02_claim_blocker');
        $blocker->beginTransaction();
        $blocker->select('SELECT pg_advisory_xact_lock(8620473)');
        $firstMarker = base_path('.local/tmp/c02-claim-a-'.Str::random(16));
        $secondMarker = base_path('.local/tmp/c02-claim-b-'.Str::random(16));
        $first = $this->protectedClaimWorker($tenant, $http, $command, $firstMarker);
        $second = $this->protectedClaimWorker($tenant, $http, $command, $secondMarker);
        try {
            $first->start();
            $this->waitForFinalizerLock($first, $firstMarker);
            $second->start();
            $this->waitForFinalizerLock($second, $secondMarker);
            $blocker->rollBack();
            $first->wait();
            $second->wait();
            self::assertTrue($first->isSuccessful(), $first->getErrorOutput());
            self::assertTrue($second->isSuccessful(), $second->getErrorOutput());
            $one = json_decode($first->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('claimed', $one['status']);
            self::assertSame($one, json_decode($second->getOutput(), true, 512, JSON_THROW_ON_ERROR));
            $claim = $this->within($tenant, fn () => app(FirstTurnProtectedClaim::class)->claim($http, $command));
            self::assertSame($one['turn_id'], $claim->turnId);
            self::assertSame(1, DB::table('seller_turns')->where('conversation_id', $command->conversationId)->count());
            self::assertSame(1, DB::table('c02_protected_turn_claims')->where('turn_id', $claim->turnId)->count());
            self::assertSame(1, DB::table('protected_operation_receipts')->where('operation', 'seller.first_turn_claim')->count());
            self::assertSame(1, DB::table('audit_logs')->where('action', 'seller.turn.protected_claimed')->count());
        } finally {
            if ($blocker->transactionLevel() > 0) {
                $blocker->rollBack();
            }
            foreach ([$first, $second] as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            foreach ([$firstMarker, $secondMarker] as $marker) {
                if (is_file($marker)) {
                    unlink($marker);
                }
            }
            DB::purge('c02_claim_blocker');
            DB::unprepared('DROP TRIGGER IF EXISTS c02_test_delay_claim ON c02_protected_turn_claims; DROP FUNCTION IF EXISTS c02_test_delay_claim();');
        }
        Http::assertNothingSent();
        Mail::assertNothingOutgoing();
        Queue::assertNothingPushed();
    }

    public function test_protected_claim_concurrent_different_content_conflicts(): void
    {
        [$tenant, $http, $command] = $this->protectedClaimFixture();
        $different = new TurnCommand($command->conversationId, $command->inputMessageId, 'Different concurrent content', null, $command->idempotencyKey, 0);
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION c02_test_delay_claim_conflict() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN PERFORM pg_advisory_xact_lock(8620474); RETURN NEW; END;
$$;
CREATE TRIGGER c02_test_delay_claim_conflict BEFORE INSERT ON c02_protected_turn_claims FOR EACH ROW EXECUTE FUNCTION c02_test_delay_claim_conflict();
SQL);
        config(['database.connections.c02_claim_conflict' => config('database.connections.pgsql')]);
        $blocker = DB::connection('c02_claim_conflict');
        $blocker->beginTransaction();
        $blocker->select('SELECT pg_advisory_xact_lock(8620474)');
        $firstMarker = base_path('.local/tmp/c02-claim-c-'.Str::random(16));
        $secondMarker = base_path('.local/tmp/c02-claim-d-'.Str::random(16));
        $first = $this->protectedClaimWorker($tenant, $http, $command, $firstMarker);
        $second = $this->protectedClaimWorker($tenant, $http, $different, $secondMarker);
        try {
            $first->start();
            $this->waitForFinalizerLock($first, $firstMarker);
            $second->start();
            $this->waitForFinalizerLock($second, $secondMarker);
            $blocker->rollBack();
            $first->wait();
            $second->wait();
            self::assertTrue($first->isSuccessful(), $first->getErrorOutput());
            self::assertTrue($second->isSuccessful(), $second->getErrorOutput());
            self::assertSame('claimed', json_decode($first->getOutput(), true, 512, JSON_THROW_ON_ERROR)['status']);
            self::assertSame(['status' => 'conflict'], json_decode($second->getOutput(), true, 512, JSON_THROW_ON_ERROR));
            self::assertSame(1, DB::table('seller_turns')->where('conversation_id', $command->conversationId)->count());
            self::assertSame(1, DB::table('c02_protected_turn_claims')->count());
            self::assertSame(1, DB::table('protected_operation_receipts')->where('operation', 'seller.first_turn_claim')->count());
        } finally {
            if ($blocker->transactionLevel() > 0) {
                $blocker->rollBack();
            }
            foreach ([$first, $second] as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            foreach ([$firstMarker, $secondMarker] as $marker) {
                if (is_file($marker)) {
                    unlink($marker);
                }
            }
            DB::purge('c02_claim_conflict');
            DB::unprepared('DROP TRIGGER IF EXISTS c02_test_delay_claim_conflict ON c02_protected_turn_claims; DROP FUNCTION IF EXISTS c02_test_delay_claim_conflict();');
        }
        Http::assertNothingSent();
    }

    private function protectedClaimWorker(TenantContext $tenant, Request $http, TurnCommand $command, string $marker): Process
    {
        $worker = new Process([PHP_BINARY, '-d', 'sys_temp_dir='.base_path('.local/tmp'), '-d', 'allow_url_fopen=0',
            '-d', 'disable_functions=fsockopen,pfsockopen,stream_socket_client,curl_exec,curl_multi_exec',
            base_path('tests/Feature/ProtectedOperations/Fixtures/protected-turn-claim-worker.php'), $marker], base_path());
        $worker->setInput(json_encode(['org' => $tenant->organizationId, 'brand' => $tenant->brandId, 'user' => $tenant->actorId,
            'member' => $tenant->membershipId, 'session' => $http->session()->getId(), 'conversation' => $command->conversationId,
            'message' => $command->inputMessageId, 'text' => $command->text, 'key' => $command->idempotencyKey], JSON_THROW_ON_ERROR));
        $worker->setTimeout(60);

        return $worker;
    }

    public function test_coordinator_completes_one_command_and_replays_without_new_calls(): void
    {
        [$tenant, $http, $command] = $this->protectedClaimFixture();
        $fake = new CoordinatorScriptedFakeDriver([
            ['content' => '{"client_intent":"answer_only"}'],
            ['content' => 'Можем обсудить заказ?'],
        ]);
        app()->instance(FakeDriver::class, $fake);
        $this->within($tenant, function () use ($http, $command, $fake): void {
            $service = app(FirstTurnCoordinator::class);
            $first = $service->run($http, $command);
            self::assertSame('completed', $first['status']);
            self::assertSame($first, $service->run($http, $command));
            $different = new TurnCommand($command->conversationId, $command->inputMessageId, 'Different input', null, $command->idempotencyKey, 0);
            try {
                $service->run($http, $different);
                self::fail('Different content reused the coordinator key.');
            } catch (IdempotencyConflict) {
                self::assertTrue(true);
            }
            self::assertSame(2, $fake->calls);
            self::assertSame(2, DB::table('ai_calls')->count());
            self::assertSame(1, DB::table('c02_extractor_effect_reservations')->count());
            self::assertSame(DB::table('ai_calls')->where('purpose', 'extractor')->value('id'), DB::table('c02_extractor_effect_reservations')->value('ai_call_id'));
            self::assertSame(2, DB::table('messages')->where('conversation_id', $command->conversationId)->count());
            self::assertSame(1, DB::table('audit_logs')->where('action', 'seller.turn.text_finalized')->count());
            self::assertSame('completed', DB::table('seller_turns')->where('id', $first['result']['turn_id'])->value('status'));
            self::assertSame('Можем обсудить заказ?', DB::table('messages')->where('id', $first['result']['message_id'])->value('text'));
            $state = json_decode(DB::table('conversations')->where('id', $command->conversationId)->value('state'), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(1, $state['counters']['turns']);
            self::assertSame('city', $state['signals']['last_asked_slot']);
            self::assertFalse($state['flags']['introduced']);
            self::assertArrayNotHasKey('outcome_seal', $first['result']);
            self::assertArrayNotHasKey('token', $first['result']);
            self::assertSame(0, DB::table('seller_tasks')->count());
            self::assertSame(0, DB::table('outbox_events')->count());
            $stored = DB::table('seller_turns')->where('id', $first['result']['turn_id'])->first();
            $outcome = json_decode($stored->outcome, true, 512, JSON_THROW_ON_ERROR);
            $outcome['messages'][0]['text'] = 'Altered synthetic output';
            DB::table('messages')->where('id', $first['result']['message_id'])->update(['text' => 'Altered synthetic output']);
            DB::table('seller_turns')->where('id', $first['result']['turn_id'])->update([
                'outcome' => json_encode($outcome, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR),
                'outcome_hash' => \App\Seller\Conversations\CanonicalJson::hash($outcome),
            ]);
            self::assertSame(['status' => 'unavailable'], $service->run($http, $command));
            app(AuthSessionRegistry::class)->withinActive($http, fn () => app(AuthSessionRegistry::class)->revokeCurrent($http));
            self::assertSame(['status' => 'unavailable'], $service->run($http, $command));
            self::assertSame(2, $fake->calls);
        });
        Http::assertNothingSent();
        Mail::assertNothingOutgoing();
        Queue::assertNothingPushed();
    }

    public function test_coordinator_recovers_after_finalizer_commit_without_repeating_ai(): void
    {
        [$tenant, $http, $command] = $this->protectedClaimFixture();
        $claim = $this->within($tenant, fn () => app(FirstTurnProtectedClaim::class)->claim($http, $command));
        $extractor = $this->completeExtractorForProtectedClaim($tenant, $http, $claim);
        [$responder, , , $file] = $this->responderFixture($tenant, $http, $claim, $extractor, ['content' => 'Можем обсудить заказ?']);
        $this->within($tenant, function () use ($http, $command, $claim, $responder, $file): void {
            app(Gateway::class)->completeProtectedResponder($http, $claim, $responder);
            $result = app(FirstTurnTextFinalizer::class)->finalize($http, $claim, $responder, null, FirstTurnCoordinator::finalizationKey($claim->turnId));
            // A crash here loses the caller's result, while the committed receipt survives.
            self::assertSame(['status' => 'completed', 'result' => $result], app(FirstTurnCoordinator::class)->run($http, $command));
            self::assertSame(2, DB::table('ai_calls')->count());
            self::assertSame(2, DB::table('messages')->where('conversation_id', $claim->conversationId)->count());
            self::assertSame(1, DB::table('audit_logs')->where('action', 'seller.turn.text_finalized')->count());
            unlink($file);
        });
        Http::assertNothingSent();
    }

    public function test_coordinator_resumes_after_extractor_without_repeating_it(): void
    {
        [$tenant, $http, $command] = $this->protectedClaimFixture();
        $claim = $this->within($tenant, fn () => app(FirstTurnProtectedClaim::class)->claim($http, $command));
        $extractor = $this->completeExtractorForProtectedClaim($tenant, $http, $claim);
        $this->within($tenant, function () use ($http, $claim, $extractor): void {
            $state = app(FirstTurnState::class)->prepare($http, $claim, $extractor);
            app(SellerResponderStages::class)->prepare($http, $claim, $extractor, $state);
        });
        $fake = new CoordinatorScriptedFakeDriver([['content' => 'Можем обсудить заказ?']]);
        app()->instance(FakeDriver::class, $fake);
        $this->within($tenant, function () use ($http, $command, $fake): void {
            $result = app(FirstTurnCoordinator::class)->run($http, $command);
            self::assertSame('completed', $result['status']);
            self::assertSame($result, app(FirstTurnCoordinator::class)->run($http, $command));
            self::assertSame(1, $fake->calls);
            self::assertSame(1, DB::table('ai_calls')->where('purpose', 'extractor')->count());
            self::assertSame(1, DB::table('ai_calls')->where('purpose', 'responder')->count());
            self::assertSame(1, DB::table('messages')->where('conversation_id', $command->conversationId)->where('role', 'agent')->count());
        });
        Http::assertNothingSent();
    }

    public function test_coordinator_recovers_after_finalization_rollback_without_repeating_ai(): void
    {
        [$tenant, $http, $command] = $this->protectedClaimFixture();
        $fake = new CoordinatorScriptedFakeDriver([
            ['content' => '{"client_intent":"answer_only"}'],
            ['content' => 'Можем обсудить заказ?'],
        ]);
        app()->instance(FakeDriver::class, $fake);
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION c02_test_fail_coordinator_audit() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF NEW.action = 'seller.turn.text_finalized' THEN RAISE EXCEPTION 'synthetic audit failure'; END IF;
  RETURN NEW;
END;
$$;
CREATE TRIGGER c02_test_fail_coordinator_audit BEFORE INSERT ON audit_logs FOR EACH ROW EXECUTE FUNCTION c02_test_fail_coordinator_audit();
SQL);
        try {
            $this->within($tenant, function () use ($http, $command, $fake): void {
                self::assertSame(['status' => 'unavailable'], app(FirstTurnCoordinator::class)->run($http, $command));
                self::assertSame(2, $fake->calls);
                self::assertSame(2, DB::table('ai_calls')->count());
                self::assertSame(1, DB::table('messages')->where('conversation_id', $command->conversationId)->count());
                self::assertSame(0, DB::table('protected_operation_receipts')->where('operation', 'seller.first_text_finalize')->count());
            });
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS c02_test_fail_coordinator_audit ON audit_logs; DROP FUNCTION IF EXISTS c02_test_fail_coordinator_audit();');
        }
        $this->within($tenant, function () use ($http, $command, $fake): void {
            $result = app(FirstTurnCoordinator::class)->run($http, $command);
            self::assertSame('completed', $result['status']);
            self::assertSame(2, $fake->calls);
            self::assertSame(2, DB::table('messages')->where('conversation_id', $command->conversationId)->count());
            self::assertSame(1, DB::table('audit_logs')->where('action', 'seller.turn.text_finalized')->count());
        });
        Http::assertNothingSent();
    }

    public function test_coordinator_rejects_live_driver_and_outer_transaction_before_claim(): void
    {
        [$tenant, $http, $command] = $this->protectedClaimFixture();
        $this->within($tenant, function () use ($http, $command): void {
            config(['runtime.ai_driver' => 'openrouter']);
            try {
                self::assertSame(['status' => 'unavailable'], app(FirstTurnCoordinator::class)->run($http, $command));
            } finally {
                config(['runtime.ai_driver' => 'fake']);
            }
            DB::transaction(fn () => self::assertSame(['status' => 'unavailable'], app(FirstTurnCoordinator::class)->run($http, $command)));
            self::assertSame(0, DB::table('seller_turns')->where('conversation_id', $command->conversationId)->count());
            self::assertSame(0, DB::table('ai_calls')->count());
        });
        Http::assertNothingSent();
    }

    public function test_coordinator_recovers_saved_responder_after_crash_live_race_and_tool_signal(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION c02_test_delay_coordinator_responder_recovery() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF NEW.unresolved_count < OLD.unresolved_count AND NEW.spent_micros > OLD.spent_micros THEN
    PERFORM pg_advisory_xact_lock(8620495);
  END IF;
  RETURN NEW;
END;
$$;
CREATE TRIGGER c02_test_delay_coordinator_responder_recovery BEFORE UPDATE ON ai_budgets
  FOR EACH ROW EXECUTE FUNCTION c02_test_delay_coordinator_responder_recovery();
SQL);
        config(['database.connections.c02_responder_coord_blocker' => config('database.connections.pgsql')]);
        $blocker = DB::connection('c02_responder_coord_blocker');
        $workers = [];
        $paths = [];
        try {
            foreach (['crash', 'live', 'tool'] as $scenario) {
                [$tenant, $http, $command] = $this->protectedClaimFixture();
                $prefix = base_path('.local/tmp/c02-responder-coordinator-'.Str::random(16));
                $saved = $prefix.'-saved';
                $resume = $prefix.'-resume';
                $responderMarker = $prefix.'-responder';
                $pidMarkers = [$prefix.'-pid1', $prefix.'-pid2', $prefix.'-pid3'];
                array_push($paths, $saved, $resume, $responderMarker, ...$pidMarkers);
                $toolSignal = $scenario === 'tool';
                $initial = $this->coordinatorRecoveryWorker($tenant, $http, $command, 'initial', $saved, $resume, $responderMarker, $pidMarkers[0], 'responder', $toolSignal);
                $workers[] = $initial;
                $initial->start();
                $deadline = microtime(true) + 30;
                while (! is_file($saved) && $initial->isRunning() && microtime(true) < $deadline) {
                    usleep(10000);
                }
                self::assertFileExists($saved);
                self::assertTrue($initial->isRunning());
                $call = DB::table('ai_calls')->where('object_id', $command->conversationId)->where('purpose', 'responder')->first();
                self::assertNotNull($call);
                self::assertSame('reserved', $call->status);
                self::assertNotNull($call->result_encrypted);
                self::assertSame('completed', DB::table('ai_calls')->where('object_id', $command->conversationId)->where('purpose', 'extractor')->value('status'));
                if ($scenario !== 'live') {
                    $initial->stop(1);
                    self::assertFalse($initial->isRunning());
                }
                if ($scenario === 'crash') {
                    $this->assertCoordinatorSavedResponderDenials($tenant, $http, $command, $call);
                }

                $blocker->beginTransaction();
                $blocker->select('SELECT pg_advisory_xact_lock(8620495)');
                $first = $this->coordinatorRecoveryWorker($tenant, $http, $command, 'resume', $saved, $resume, $responderMarker, $pidMarkers[1], 'responder', $toolSignal);
                $second = $this->coordinatorRecoveryWorker($tenant, $http, $command, 'resume', $saved, $resume, $responderMarker, $pidMarkers[2], 'responder', $toolSignal);
                array_push($workers, $first, $second);
                $first->start();
                $this->waitForFinalizerLock($first, $pidMarkers[1]);
                $second->start();
                $this->waitForFinalizerLock($second, $pidMarkers[2]);
                $blocker->rollBack();
                foreach ([$first, $second] as $worker) {
                    $worker->wait();
                    self::assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
                }
                $statuses = array_map(fn (Process $worker) => json_decode($worker->getOutput(), true, 512, JSON_THROW_ON_ERROR)['status'], [$first, $second]);
                if ($toolSignal) {
                    self::assertSame(['blocked', 'blocked'], $statuses);
                } else {
                    self::assertContains('completed', $statuses);
                }
                self::assertSame(1, DB::table('ai_calls')->where('object_id', $command->conversationId)->where('purpose', 'extractor')->count());
                self::assertSame(1, DB::table('ai_calls')->where('object_id', $command->conversationId)->where('purpose', 'responder')->count());
                self::assertSame('completed', DB::table('ai_calls')->where('id', $call->id)->value('status'));
                self::assertSame($toolSignal ? 0 : 1, DB::table('messages')->where('conversation_id', $command->conversationId)->where('role', 'agent')->count());
                self::assertSame($toolSignal ? 0 : 1, DB::table('audit_logs')->where('action', 'seller.turn.text_finalized')->where('brand_id', $tenant->brandId)->count());
                self::assertSame(1, count(file($responderMarker, FILE_IGNORE_NEW_LINES)));
                self::assertSame(14, (int) DB::table('ai_budgets')->where('id', $call->budget_id)->value('spent_micros'));
                self::assertSame(0, (int) DB::table('ai_budgets')->where('id', $call->budget_id)->value('unresolved_count'));
                $replayed = $this->within($tenant, fn () => app(FirstTurnCoordinator::class)->run($http, $command));
                self::assertSame($toolSignal ? 'blocked' : 'completed', $replayed['status']);
                if (! $toolSignal) {
                    self::assertSame(DB::table('messages')->where('conversation_id', $command->conversationId)->where('role', 'agent')->value('id'), $replayed['result']['message_id']);
                }
                if ($scenario === 'live') {
                    self::assertTrue($initial->isRunning());
                    file_put_contents($resume, 'resume');
                    $initial->wait();
                    self::assertTrue($initial->isSuccessful(), $initial->getErrorOutput());
                    self::assertSame('completed', json_decode($initial->getOutput(), true, 512, JSON_THROW_ON_ERROR)['status']);
                    self::assertSame('completed', DB::table('ai_calls')->where('id', $call->id)->value('status'));
                    self::assertSame(1, DB::table('messages')->where('conversation_id', $command->conversationId)->where('role', 'agent')->count());
                    self::assertSame(1, count(file($responderMarker, FILE_IGNORE_NEW_LINES)));
                }
            }
            self::assertSame(0, DB::table('seller_tasks')->count());
            self::assertSame(0, DB::table('outbox_events')->count());
        } finally {
            if ($blocker->transactionLevel() > 0) {
                $blocker->rollBack();
            }
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            foreach ($paths as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            DB::purge('c02_responder_coord_blocker');
            DB::unprepared('DROP TRIGGER IF EXISTS c02_test_delay_coordinator_responder_recovery ON ai_budgets; DROP FUNCTION IF EXISTS c02_test_delay_coordinator_responder_recovery();');
        }
        Http::assertNothingSent();
        Mail::assertNothingOutgoing();
        Queue::assertNothingPushed();
    }

    private function assertCoordinatorSavedResponderDenials(TenantContext $tenant, Request $http, TurnCommand $command, object $call): void
    {
        $fake = new CoordinatorScriptedFakeDriver([]);
        app()->instance(FakeDriver::class, $fake);
        $stored = json_decode(Crypt::decryptString($call->result_encrypted), true, 512, JSON_THROW_ON_ERROR);
        foreach ([null, 'invalid-ciphertext', Crypt::encryptString(json_encode([...$stored, 'costMicros' => null], JSON_THROW_ON_ERROR)),
            Crypt::encryptString(json_encode([...$stored, 'aiCallId' => (string) Str::ulid()], JSON_THROW_ON_ERROR))] as $invalid) {
            DB::table('ai_calls')->where('id', $call->id)->update(['result_encrypted' => $invalid]);
            self::assertSame(['status' => 'in_progress'], $this->within($tenant, fn () => app(FirstTurnCoordinator::class)->run($http, $command)));
            DB::table('ai_calls')->where('id', $call->id)->update(['result_encrypted' => $call->result_encrypted]);
        }
        $stage = DB::table('c02_responder_stages')->where('ai_call_id', $call->id)->first();
        try {
            DB::statement('ALTER TABLE c02_responder_stages DISABLE TRIGGER c02_responder_stage_guard');
            DB::statement('ALTER TABLE c02_responder_stages DISABLE TRIGGER c02_responder_binding_seal_guard');
            DB::table('c02_responder_stages')->where('id', $stage->id)->update(['binding_seal' => null]);
            DB::statement('ALTER TABLE c02_responder_stages ENABLE TRIGGER c02_responder_binding_seal_guard');
            DB::statement('ALTER TABLE c02_responder_stages ENABLE TRIGGER c02_responder_stage_guard');
            self::assertNotSame('completed', $this->within($tenant, fn () => app(FirstTurnCoordinator::class)->run($http, $command))['status']);
        } finally {
            DB::statement('ALTER TABLE c02_responder_stages DISABLE TRIGGER c02_responder_stage_guard');
            DB::statement('ALTER TABLE c02_responder_stages DISABLE TRIGGER c02_responder_binding_seal_guard');
            DB::table('c02_responder_stages')->where('id', $stage->id)->update(['binding_seal' => $stage->binding_seal]);
            DB::statement('ALTER TABLE c02_responder_stages ENABLE TRIGGER c02_responder_binding_seal_guard');
            DB::statement('ALTER TABLE c02_responder_stages ENABLE TRIGGER c02_responder_stage_guard');
        }
        DB::table('ai_calls')->where('id', $call->id)->update(['request_hash' => str_repeat('a', 64)]);
        self::assertNotSame('completed', $this->within($tenant, fn () => app(FirstTurnCoordinator::class)->run($http, $command))['status']);
        DB::table('ai_calls')->where('id', $call->id)->update(['request_hash' => $call->request_hash]);
        $otherBrand = $this->within(new TenantContext($tenant->organizationId, actorId: $tenant->actorId), fn () => Brand::create(['name' => 'Other synthetic brand']));
        $otherTenant = new TenantContext($tenant->organizationId, $otherBrand->id, $tenant->actorId, $tenant->membershipId);
        self::assertSame(['status' => 'unavailable'], $this->within($otherTenant, fn () => app(FirstTurnCoordinator::class)->run($http, $command)));
        config(['runtime.ai_driver' => 'record']);
        self::assertSame(['status' => 'unavailable'], $this->within($tenant, fn () => app(FirstTurnCoordinator::class)->run($http, $command)));
        config(['runtime.ai_driver' => 'fake']);
        DB::table('auth_session_registry')->where('user_id', $tenant->actorId)->update(['revoked_at' => now()]);
        self::assertSame(['status' => 'unavailable'], $this->within($tenant, fn () => app(FirstTurnCoordinator::class)->run($http, $command)));
        DB::table('auth_session_registry')->where('user_id', $tenant->actorId)->update(['revoked_at' => null]);
        self::assertSame(0, $fake->calls);
        self::assertSame('reserved', DB::table('ai_calls')->where('id', $call->id)->value('status'));
        self::assertSame(0, DB::table('messages')->where('conversation_id', $command->conversationId)->where('role', 'agent')->count());
    }

    public function test_coordinator_recovers_saved_extractor_after_crash_and_against_a_live_original(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION c02_test_delay_coordinator_recovery_state() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN PERFORM pg_advisory_xact_lock(8620493); RETURN NEW; END;
$$;
CREATE TRIGGER c02_test_delay_coordinator_recovery_state BEFORE INSERT ON c02_first_turn_states
  FOR EACH ROW EXECUTE FUNCTION c02_test_delay_coordinator_recovery_state();
SQL);
        config(['database.connections.c02_recovery_blocker' => config('database.connections.pgsql')]);
        $blocker = DB::connection('c02_recovery_blocker');
        $workers = [];
        $paths = [];
        try {
            foreach (['crash', 'live'] as $scenario) {
                [$tenant, $http, $command] = $this->protectedClaimFixture();
                $prefix = base_path('.local/tmp/c02-coordinator-recovery-'.Str::random(16));
                $saved = $prefix.'-saved';
                $resume = $prefix.'-resume';
                $responderMarker = $prefix.'-responder';
                $pidMarkers = [$prefix.'-pid1', $prefix.'-pid2', $prefix.'-pid3'];
                array_push($paths, $saved, $resume, $responderMarker, ...$pidMarkers);
                $initial = $this->coordinatorRecoveryWorker($tenant, $http, $command, 'initial', $saved, $resume, $responderMarker, $pidMarkers[0]);
                $workers[] = $initial;
                $initial->start();
                $deadline = microtime(true) + 30;
                while (! is_file($saved) && $initial->isRunning() && microtime(true) < $deadline) {
                    usleep(10000);
                }
                self::assertFileExists($saved);
                self::assertTrue($initial->isRunning());
                $call = DB::table('ai_calls')->where('object_id', $command->conversationId)->where('purpose', 'extractor')->first();
                self::assertNotNull($call);
                self::assertSame('reserved', $call->status);
                self::assertNotNull($call->result_encrypted);
                if ($scenario === 'crash') {
                    $initial->stop(1);
                    self::assertFalse($initial->isRunning());
                    $this->assertCoordinatorSavedExtractorDenials($tenant, $http, $command, $call);
                }

                $blocker->beginTransaction();
                $blocker->select('SELECT pg_advisory_xact_lock(8620493)');
                $first = $this->coordinatorRecoveryWorker($tenant, $http, $command, 'resume', $saved, $resume, $responderMarker, $pidMarkers[1]);
                $second = $this->coordinatorRecoveryWorker($tenant, $http, $command, 'resume', $saved, $resume, $responderMarker, $pidMarkers[2]);
                array_push($workers, $first, $second);
                $first->start();
                $this->waitForFinalizerLock($first, $pidMarkers[1]);
                $second->start();
                $this->waitForFinalizerLock($second, $pidMarkers[2]);
                $blocker->rollBack();
                foreach ([$first, $second] as $worker) {
                    $worker->wait();
                    self::assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
                }
                $statuses = array_map(fn (Process $worker) => json_decode($worker->getOutput(), true, 512, JSON_THROW_ON_ERROR)['status'], [$first, $second]);
                self::assertContains('completed', $statuses);
                self::assertSame(1, DB::table('ai_calls')->where('object_id', $command->conversationId)->where('purpose', 'extractor')->count());
                self::assertSame(1, DB::table('ai_calls')->where('object_id', $command->conversationId)->where('purpose', 'responder')->count());
                self::assertSame('completed', DB::table('ai_calls')->where('id', $call->id)->value('status'));
                self::assertSame(1, DB::table('messages')->where('conversation_id', $command->conversationId)->where('role', 'agent')->count());
                self::assertSame(1, DB::table('audit_logs')->where('action', 'seller.turn.text_finalized')->where('brand_id', $tenant->brandId)->count());
                self::assertSame(1, count(file($responderMarker, FILE_IGNORE_NEW_LINES)));
                self::assertSame(14, (int) DB::table('ai_budgets')->where('id', $call->budget_id)->value('spent_micros'));
                self::assertSame(0, (int) DB::table('ai_budgets')->where('id', $call->budget_id)->value('unresolved_count'));
                $replayed = $this->within($tenant, fn () => app(FirstTurnCoordinator::class)->run($http, $command));
                self::assertSame('completed', $replayed['status']);
                self::assertSame(DB::table('messages')->where('conversation_id', $command->conversationId)->where('role', 'agent')->value('id'), $replayed['result']['message_id']);
                if ($scenario === 'live') {
                    self::assertTrue($initial->isRunning());
                    file_put_contents($resume, 'resume');
                    $initial->wait();
                    self::assertTrue($initial->isSuccessful(), $initial->getErrorOutput());
                    self::assertSame('completed', json_decode($initial->getOutput(), true, 512, JSON_THROW_ON_ERROR)['status']);
                    self::assertSame('completed', DB::table('ai_calls')->where('id', $call->id)->value('status'));
                    self::assertSame(1, DB::table('messages')->where('conversation_id', $command->conversationId)->where('role', 'agent')->count());
                    self::assertSame(1, count(file($responderMarker, FILE_IGNORE_NEW_LINES)));
                }
            }
        } finally {
            if ($blocker->transactionLevel() > 0) {
                $blocker->rollBack();
            }
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            foreach ($paths as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            DB::purge('c02_recovery_blocker');
            DB::unprepared('DROP TRIGGER IF EXISTS c02_test_delay_coordinator_recovery_state ON c02_first_turn_states; DROP FUNCTION IF EXISTS c02_test_delay_coordinator_recovery_state();');
        }
        Http::assertNothingSent();
        Mail::assertNothingOutgoing();
        Queue::assertNothingPushed();
    }

    private function coordinatorRecoveryWorker(TenantContext $tenant, Request $http, TurnCommand $command, string $mode, string $saved, string $resume, string $responder, string $pid, string $pausePurpose = 'extractor', bool $toolSignal = false): Process
    {
        $worker = new Process([PHP_BINARY, '-d', 'sys_temp_dir='.base_path('.local/tmp'), '-d', 'allow_url_fopen=0',
            '-d', 'disable_functions=fsockopen,pfsockopen,stream_socket_client,curl_exec,curl_multi_exec',
            base_path('tests/Feature/ProtectedOperations/Fixtures/coordinator-recovery-worker.php')], base_path());
        $worker->setInput(json_encode(['org' => $tenant->organizationId, 'brand' => $tenant->brandId, 'user' => $tenant->actorId,
            'member' => $tenant->membershipId, 'session' => $http->session()->getId(), 'conversation' => $command->conversationId,
            'message' => $command->inputMessageId, 'text' => $command->text, 'key' => $command->idempotencyKey,
            'mode' => $mode, 'saved_marker' => $saved, 'resume_marker' => $resume, 'responder_marker' => $responder, 'pid_marker' => $pid,
            'pause_purpose' => $pausePurpose, 'tool_signal' => $toolSignal], JSON_THROW_ON_ERROR));
        $worker->setTimeout(90);

        return $worker;
    }

    private function assertCoordinatorSavedExtractorDenials(TenantContext $tenant, Request $http, TurnCommand $command, object $call): void
    {
        $fake = new CoordinatorScriptedFakeDriver([]);
        app()->instance(FakeDriver::class, $fake);
        $stored = json_decode(Crypt::decryptString($call->result_encrypted), true, 512, JSON_THROW_ON_ERROR);
        foreach ([null, 'invalid-ciphertext', Crypt::encryptString(json_encode([...$stored, 'costMicros' => null], JSON_THROW_ON_ERROR)),
            Crypt::encryptString(json_encode([...$stored, 'aiCallId' => (string) Str::ulid()], JSON_THROW_ON_ERROR))] as $invalid) {
            DB::table('ai_calls')->where('id', $call->id)->update(['result_encrypted' => $invalid]);
            self::assertSame(['status' => 'in_progress'], $this->within($tenant, fn () => app(FirstTurnCoordinator::class)->run($http, $command)));
            DB::table('ai_calls')->where('id', $call->id)->update(['result_encrypted' => $call->result_encrypted]);
        }
        DB::table('ai_calls')->where('id', $call->id)->update(['request_hash' => str_repeat('a', 64)]);
        self::assertNotSame('completed', $this->within($tenant, fn () => app(FirstTurnCoordinator::class)->run($http, $command))['status']);
        DB::table('ai_calls')->where('id', $call->id)->update(['request_hash' => $call->request_hash]);
        $otherBrand = $this->within(new TenantContext($tenant->organizationId, actorId: $tenant->actorId), fn () => Brand::create(['name' => 'Other synthetic brand']));
        $otherTenant = new TenantContext($tenant->organizationId, $otherBrand->id, $tenant->actorId, $tenant->membershipId);
        self::assertSame(['status' => 'unavailable'], $this->within($otherTenant, fn () => app(FirstTurnCoordinator::class)->run($http, $command)));
        config(['runtime.ai_driver' => 'record']);
        self::assertSame(['status' => 'unavailable'], $this->within($tenant, fn () => app(FirstTurnCoordinator::class)->run($http, $command)));
        config(['runtime.ai_driver' => 'fake']);
        DB::table('auth_session_registry')->where('user_id', $tenant->actorId)->update(['revoked_at' => now()]);
        self::assertSame(['status' => 'unavailable'], $this->within($tenant, fn () => app(FirstTurnCoordinator::class)->run($http, $command)));
        DB::table('auth_session_registry')->where('user_id', $tenant->actorId)->update(['revoked_at' => null]);
        self::assertSame(0, $fake->calls);
        self::assertSame('reserved', DB::table('ai_calls')->where('id', $call->id)->value('status'));
        self::assertSame(1, DB::table('messages')->where('conversation_id', $command->conversationId)->count());
    }

    public function test_coordinator_does_not_retry_a_call_after_worker_stops_at_reservation(): void
    {
        [$tenant, $http, $command] = $this->protectedClaimFixture();
        config(['database.connections.c02_coordinator_blocker' => config('database.connections.pgsql')]);
        $blocker = DB::connection('c02_coordinator_blocker');
        $blocker->beginTransaction();
        $blocker->select('SELECT pg_advisory_xact_lock(8620480)');
        $marker = base_path('.local/tmp/c02-coordinator-reserved-'.Str::random(16));
        $worker = new Process([PHP_BINARY, '-d', 'sys_temp_dir='.base_path('.local/tmp'), '-d', 'allow_url_fopen=0',
            '-d', 'disable_functions=fsockopen,pfsockopen,stream_socket_client,curl_exec,curl_multi_exec',
            base_path('tests/Feature/ProtectedOperations/Fixtures/coordinator-reservation-worker.php'), $marker], base_path());
        $worker->setInput(json_encode(['org' => $tenant->organizationId, 'brand' => $tenant->brandId, 'user' => $tenant->actorId,
            'member' => $tenant->membershipId, 'session' => $http->session()->getId(), 'conversation' => $command->conversationId,
            'message' => $command->inputMessageId, 'text' => $command->text, 'key' => $command->idempotencyKey], JSON_THROW_ON_ERROR));
        $worker->setTimeout(60);
        try {
            $worker->start();
            $this->waitForFinalizerLock($worker, $marker);
            self::assertSame(1, DB::table('ai_calls')->where('purpose', 'extractor')->where('status', 'reserved')->count());
            $fake = new CoordinatorScriptedFakeDriver([['content' => '{"client_intent":"answer_only"}']]);
            app()->instance(FakeDriver::class, $fake);
            $this->within($tenant, function () use ($http, $command, $fake): void {
                self::assertSame(['status' => 'in_progress'], app(FirstTurnCoordinator::class)->run($http, $command));
                self::assertSame(0, $fake->calls);
            });
            $worker->stop();
            self::assertFalse($worker->isRunning());
            $blocker->rollBack();
            $this->within($tenant, function () use ($http, $command, $fake): void {
                self::assertSame(['status' => 'in_progress'], app(FirstTurnCoordinator::class)->run($http, $command));
                self::assertSame(['status' => 'in_progress'], app(FirstTurnCoordinator::class)->run($http, $command));
                self::assertSame(0, $fake->calls);
                self::assertSame(1, DB::table('ai_calls')->where('purpose', 'extractor')->where('status', 'reserved')->count());
                self::assertSame(1, DB::table('messages')->where('conversation_id', $command->conversationId)->count());
                self::assertSame(0, DB::table('seller_tasks')->count());
                self::assertSame(0, DB::table('outbox_events')->count());
            });
        } finally {
            if ($blocker->transactionLevel() > 0) {
                $blocker->rollBack();
            }
            if ($worker->isRunning()) {
                $worker->stop();
            }
            if (is_file($marker)) {
                unlink($marker);
            }
            DB::purge('c02_coordinator_blocker');
        }
        Http::assertNothingSent();
        Mail::assertNothingOutgoing();
        Queue::assertNothingPushed();
    }

    public function test_coordinator_blocks_tools_and_does_not_retry_unknown_ai(): void
    {
        [$tenant, $http, $command] = $this->protectedClaimFixture();
        $fake = new CoordinatorScriptedFakeDriver([
            ['content' => '{"client_intent":"answer_only"}'],
            ['content' => 'Ignored', 'toolCalls' => [['id' => 'synthetic-tool', 'name' => 'send_price', 'arguments' => []]]],
        ]);
        app()->instance(FakeDriver::class, $fake);
        $this->within($tenant, function () use ($http, $command, $fake): void {
            self::assertSame(['status' => 'blocked'], app(FirstTurnCoordinator::class)->run($http, $command));
            self::assertSame(['status' => 'blocked'], app(FirstTurnCoordinator::class)->run($http, $command));
            self::assertSame(2, $fake->calls);
            self::assertSame(1, DB::table('messages')->where('conversation_id', $command->conversationId)->count());
            self::assertSame(0, DB::table('seller_tasks')->count());
            self::assertSame(0, DB::table('outbox_events')->count());
        });
        [$otherTenant, $otherHttp, $otherCommand] = $this->protectedClaimFixture();
        $unknown = new CoordinatorScriptedFakeDriver([['content' => '{"client_intent":"answer_only"}', 'costMicros' => null]]);
        app()->instance(FakeDriver::class, $unknown);
        $this->within($otherTenant, function () use ($otherHttp, $otherCommand, $unknown): void {
            self::assertSame(['status' => 'uncertain'], app(FirstTurnCoordinator::class)->run($otherHttp, $otherCommand));
            self::assertSame(['status' => 'uncertain'], app(FirstTurnCoordinator::class)->run($otherHttp, $otherCommand));
            self::assertSame(1, $unknown->calls);
            self::assertSame(1, DB::table('messages')->where('conversation_id', $otherCommand->conversationId)->count());
        });
        Http::assertNothingSent();
        Mail::assertNothingOutgoing();
        Queue::assertNothingPushed();
    }

    public function test_coordinator_runs_required_model_guard_and_blocks_required_effect(): void
    {
        config(['agent.guard_llm' => true]);
        [$tenant, $http, $command] = $this->protectedClaimFixture();
        $fake = new CoordinatorScriptedFakeDriver([
            ['content' => '{"client_intent":"answer_only","closed_topic":"management_names"}'],
            ['content' => 'Давайте обсудим ваш заказ.'],
            ['content' => '{"ok":true,"issues":[]}'],
        ]);
        app()->instance(FakeDriver::class, $fake);
        $this->within($tenant, function () use ($http, $command, $fake): void {
            $result = app(FirstTurnCoordinator::class)->run($http, $command);
            self::assertSame('completed', $result['status']);
            self::assertSame($result, app(FirstTurnCoordinator::class)->run($http, $command));
            self::assertSame(3, $fake->calls);
            self::assertSame(1, DB::table('ai_calls')->where('purpose', 'guard')->count());
            self::assertSame(1, DB::table('messages')->where('conversation_id', $command->conversationId)->where('role', 'agent')->count());
        });
        config(['agent.guard_llm' => false]);
        [$otherTenant, $otherHttp, $otherCommand] = $this->protectedClaimFixture();
        $effect = new CoordinatorScriptedFakeDriver([
            ['content' => '{"client_intent":"ask_requisites"}'],
            ['content' => 'Пришлю реквизиты. Какой у вас город?'],
        ]);
        app()->instance(FakeDriver::class, $effect);
        $this->within($otherTenant, function () use ($otherHttp, $otherCommand, $effect): void {
            $result = app(FirstTurnCoordinator::class)->run($otherHttp, $otherCommand);
            self::assertContains($result['status'], ['blocked', 'unavailable']);
            self::assertNotSame('completed', app(FirstTurnCoordinator::class)->run($otherHttp, $otherCommand)['status']);
            self::assertSame(2, $effect->calls);
            self::assertSame(0, DB::table('messages')->where('conversation_id', $otherCommand->conversationId)->where('role', 'agent')->count());
        });
        self::assertSame(0, DB::table('seller_tasks')->count());
        self::assertSame(0, DB::table('outbox_events')->count());
        Http::assertNothingSent();
        Mail::assertNothingOutgoing();
        Queue::assertNothingPushed();
    }

    public function test_coordinator_does_not_save_after_new_input_or_revoked_session(): void
    {
        [$tenant, $http, $command] = $this->protectedClaimFixture();
        $fake = new CoordinatorScriptedFakeDriver([['content' => '{"client_intent":"answer_only"}']]);
        $fake->onCall = function () use ($command): void {
            Message::create(['conversation_id' => $command->conversationId, 'role' => 'client', 'text' => 'Newer input', 'meta' => []]);
        };
        app()->instance(FakeDriver::class, $fake);
        $this->within($tenant, function () use ($http, $command, $fake): void {
            self::assertSame(['status' => 'uncertain'], app(FirstTurnCoordinator::class)->run($http, $command));
            self::assertSame(['status' => 'uncertain'], app(FirstTurnCoordinator::class)->run($http, $command));
            self::assertSame(1, $fake->calls);
            self::assertSame(2, DB::table('messages')->where('conversation_id', $command->conversationId)->count());
            self::assertSame(0, DB::table('messages')->where('conversation_id', $command->conversationId)->where('role', 'agent')->count());
            self::assertSame(1, DB::table('ai_calls')->where('object_id', $command->conversationId)->where('status', 'completed')->count());
        });
        [$otherTenant, $otherHttp, $otherCommand] = $this->protectedClaimFixture();
        $revoked = new CoordinatorScriptedFakeDriver([['content' => '{"client_intent":"answer_only"}']]);
        $revoked->onCall = fn () => app(AuthSessionRegistry::class)->withinActive($otherHttp, fn () => app(AuthSessionRegistry::class)->revokeCurrent($otherHttp));
        app()->instance(FakeDriver::class, $revoked);
        $this->within($otherTenant, function () use ($otherHttp, $otherCommand, $revoked): void {
            self::assertSame(['status' => 'unavailable'], app(FirstTurnCoordinator::class)->run($otherHttp, $otherCommand));
            self::assertSame(['status' => 'unavailable'], app(FirstTurnCoordinator::class)->run($otherHttp, $otherCommand));
            self::assertSame(1, $revoked->calls);
            self::assertSame(1, DB::table('messages')->where('conversation_id', $otherCommand->conversationId)->count());
            self::assertSame(1, DB::table('ai_calls')->where('object_id', $otherCommand->conversationId)->where('status', 'completed')->count());
        });
        self::assertSame(0, DB::table('seller_tasks')->count());
        self::assertSame(0, DB::table('outbox_events')->count());
        Http::assertNothingSent();
        Mail::assertNothingOutgoing();
        Queue::assertNothingPushed();
    }

    public function test_pending_detail_reads_only_real_stage_and_ai_call_statuses_over_http(): void
    {
        [$tenant, $http, $claim, $responder] = $this->modelGuardResponder();
        config(['agent.guard_llm' => true, 'features.E0-02.defaults.organization_id' => $tenant->organizationId]);
        [$guard, , , $file] = $this->modelGuardFixture($tenant, $http, $claim, $responder, ['costMicros' => null]);
        $user = $http->user();
        $this->actingAs($user);
        $this->startSession();
        session()->invalidate();
        $this->withSession(['auth.version' => $user->auth_version, 'brand_id' => $tenant->brandId]);
        $webRequest = Request::create('/', 'GET');
        $webRequest->setLaravelSession(app('session.store'));
        $webRequest->setUserResolver(fn () => $user);
        app(AuthSessionRegistry::class)->issue($webRequest, $user, $user->auth_version);
        $this->withCredentials()->withCookie(config('session.cookie'), session()->getId());
        $path = '/seller/turns/pending/'.$claim->turnId;

        $before = $this->pendingDetailSnapshot($claim);
        $response = $this->getJson($path)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.status', 'processing')->assertJsonPath('data.other_effects_may_exist', true)
            ->assertJsonPath('data.stages.extractor.ai_call_status', 'completed')
            ->assertJsonPath('data.stages.responder.ai_call_status', 'completed')
            ->assertJsonPath('data.stages.guard.ai_call_status', null);
        self::assertEqualsCanonicalizing(['extractor', 'responder', 'guard'], array_keys($response->json('data.stages')));
        foreach ($response->json('data.stages') as $stage) {
            self::assertSame(['ai_call_status'], array_keys($stage));
        }
        self::assertStringNotContainsString($claim->token(), $response->getContent());
        self::assertStringNotContainsString($guard, $response->getContent());
        self::assertStringNotContainsString('Synthetic closed topic', $response->getContent());
        self::assertStringNotContainsString('Давайте обсудим ваш заказ', $response->getContent());
        self::assertEquals($before, $this->pendingDetailSnapshot($claim));

        $driver = new CoordinatorScriptedFakeDriver([['content' => '{"ok":true,"issues":[]}', 'costMicros' => null]]);
        $driver->onCall = function () use ($claim, $path): void {
            $before = $this->pendingDetailSnapshot($claim);
            $this->getJson($path)->assertOk()->assertJsonPath('data.stages.guard.ai_call_status', 'reserved');
            self::assertEquals($before, $this->pendingDetailSnapshot($claim));
        };
        app()->instance(FakeDriver::class, $driver);
        self::assertSame('uncertain', $this->within($tenant, fn () => app(Gateway::class)->completeProtectedModelGuard($http, $claim, $guard)));
        self::assertSame(1, $driver->calls);
        unlink($file);
        $before = $this->pendingDetailSnapshot($claim);
        $this->getJson($path)->assertOk()->assertJsonPath('data.stages.guard.ai_call_status', 'uncertain');
        self::assertEquals($before, $this->pendingDetailSnapshot($claim));
        self::assertSame(0, DB::table('seller_tasks')->count());
        self::assertSame(0, DB::table('outbox_events')->count());
        Http::assertNothingSent();
        Mail::assertNothingOutgoing();
        Queue::assertNothingPushed();
    }

    private function pendingDetailSnapshot(TurnClaim $claim): array
    {
        return [DB::table('seller_turns')->where('id', $claim->turnId)->first(),
            DB::table('c02_extractor_stages')->where('turn_id', $claim->turnId)->get()->toArray(),
            DB::table('c02_responder_stages')->where('turn_id', $claim->turnId)->get()->toArray(),
            DB::table('c02_guard_stages')->where('turn_id', $claim->turnId)->get()->toArray(),
            DB::table('ai_calls')->where('object_id', $claim->conversationId)->orderBy('id')->get()->toArray()];
    }

    private function protectedClaimFixture(): array
    {
        app(PromptSetsSeeder::class)->run();
        $user = User::create(['name' => 'Synthetic', 'email' => 'first-claim-'.Str::lower(Str::random(12)).'@example.test', 'password' => 'synthetic-test-password']);
        $user->forceFill(['email_verified_at' => now()])->save();
        $user->refresh();
        $org = Organization::create(['name' => 'Synthetic', 'owner_user_id' => $user->id]);
        [$member, $brand] = $this->within(new TenantContext($org->id, actorId: $user->id), fn () => [
            Membership::create(['user_id' => $user->id, 'role' => 'owner']), Brand::create(['name' => 'Synthetic brand', 'timezone' => 'Europe/Moscow']),
        ]);
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $tenant = new TenantContext($org->id, $brand->id, $user->id, $member->id);
        $this->within($tenant, fn () => app(YamlKnowledgeImporter::class)->importAndPublish($tenant, base_path('tests/Feature/Seller/Knowledge/Fixtures/data')));
        $session = new Store('c02-claim-session', new ArraySessionHandler(120));
        $session->setId(Str::random(40));
        $session->start();
        $session->put('auth.version', $user->auth_version);
        $http = Request::create('/', 'POST');
        $http->setLaravelSession($session);
        $http->setUserResolver(fn () => $user);
        app(AuthSessionRegistry::class)->issue($http, $user, $user->auth_version);
        app(AuthSessionRegistry::class)->withinActive($http, fn () => app(SessionBrandSelection::class)->initialize($http, $org->id, $brand->id));
        [$conversation, $input] = $this->within($tenant, function (): array {
            $conversation = Conversation::create(['state' => [], 'mode' => 'bot']);
            $input = Message::create(['conversation_id' => $conversation->id, 'role' => 'client', 'text' => 'Private synthetic first input', 'meta' => []]);

            return [$conversation, $input];
        });

        return [$tenant, $http, new TurnCommand($conversation->id, $input->id, $input->text, null, 'protected-claim-'.Str::random(16), 0)];
    }

    private function completeExtractorForProtectedClaim(TenantContext $tenant, Request $http, TurnClaim $claim): string
    {
        return $this->within($tenant, function () use ($http, $claim): string {
            $stage = app(SellerExtractorStages::class)->prepare($http, $claim);
            [$request] = app(SellerExtractorStages::class)->load($http, $claim, $stage);
            $directory = storage_path('app/claim-extractor-fixtures-'.Str::random(12));
            mkdir($directory, 0700, true);
            config(['features.E0-05.defaults.fixture_directory' => $directory]);
            $file = $directory.DIRECTORY_SEPARATOR.CanonicalJson::hash(app(ContactMasker::class)->request($request->toArray())).'.json';
            file_put_contents($file, json_encode(['result' => ['content' => '{"client_intent":"answer_only"}', 'toolCalls' => [],
                'usage' => ['promptTokens' => 1, 'completionTokens' => 1], 'model' => $request->model, 'latencyMs' => 1, 'costMicros' => 0]], JSON_THROW_ON_ERROR));
            self::assertSame('completed', app(Gateway::class)->completeProtectedExtractor($http, $claim, $stage));
            unlink($file);

            return $stage;
        });
    }

    private function responderFixture(TenantContext $tenant, Request $http, TurnClaim $claim, string $extractor, array $result = []): array
    {
        return $this->within($tenant, function () use ($http, $claim, $extractor, $result): array {
            $stateId = app(FirstTurnState::class)->prepare($http, $claim, $extractor);
            $stage = app(SellerResponderStages::class)->prepare($http, $claim, $extractor, $stateId);
            [$request, $context] = app(SellerResponderStages::class)->load($http, $claim, $stage);
            $directory = storage_path('app/responder-fixtures-'.Str::random(12));
            mkdir($directory, 0700, true);
            config(['features.E0-05.defaults.fixture_directory' => $directory]);
            $file = $directory.DIRECTORY_SEPARATOR.CanonicalJson::hash(app(ContactMasker::class)->request($request->toArray())).'.json';
            $fake = array_replace(['content' => '«Антон: Тестовый ответ»', 'toolCalls' => [], 'usage' => ['promptTokens' => 1, 'completionTokens' => 1],
                'model' => $request->model, 'latencyMs' => 1, 'costMicros' => 0], $result);
            file_put_contents($file, json_encode(['result' => $fake], JSON_THROW_ON_ERROR));

            return [$stage, $request, $context, $file, $stateId];
        });
    }

    private function fixture(string $text = 'Synthetic question', array $options = []): array
    {
        app(PromptSetsSeeder::class)->run();
        $user = User::create(['name' => 'Synthetic', 'email' => 'first-state-'.Str::lower(Str::random(12)).'@example.test', 'password' => 'synthetic-test-password']);
        $user->forceFill(['email_verified_at' => now()])->save();
        $user->refresh();
        $org = Organization::create(['name' => 'Synthetic', 'owner_user_id' => $user->id]);
        [$member, $brand] = $this->within(new TenantContext($org->id, actorId: $user->id), fn () => [
            Membership::create(['user_id' => $user->id, 'role' => 'owner']), Brand::create(['name' => 'Synthetic brand', 'timezone' => 'Europe/Moscow']),
        ]);
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $tenant = new TenantContext($org->id, $brand->id, $user->id, $member->id);
        $this->within($tenant, fn () => app(YamlKnowledgeImporter::class)->importAndPublish($tenant, base_path('tests/Feature/Seller/Knowledge/Fixtures/data')));
        $session = new Store('c02-state-session', new ArraySessionHandler(120));
        $session->setId(Str::random(40));
        $session->start();
        $session->put('auth.version', $user->auth_version);
        $http = Request::create('/', 'POST');
        $http->setLaravelSession($session);
        $http->setUserResolver(fn () => $user);
        app(AuthSessionRegistry::class)->issue($http, $user, $user->auth_version);
        app(AuthSessionRegistry::class)->withinActive($http, fn () => app(SessionBrandSelection::class)->initialize($http, $org->id, $brand->id));
        [$conversation, $input] = $this->within($tenant, function () use ($text, $options): array {
            $conversation = Conversation::create(['state' => $options['state'] ?? [], 'mode' => $options['mode'] ?? 'bot']);
            if ($options['history'] ?? false) {
                Message::create(['conversation_id' => $conversation->id, 'role' => 'agent', 'text' => 'Earlier synthetic output', 'meta' => []]);
            }
            if ($options['changed'] ?? false) {
                $conversation->update(['state' => ['temporary' => true]]);
                $conversation->update(['state' => []]);
            }
            $input = Message::create(['conversation_id' => $conversation->id, 'role' => 'client', 'text' => $text, 'meta' => []]);

            return [$conversation, $input];
        });
        $command = new TurnCommand($conversation->id, $input->id, $input->text, null, 'state-stage-'.Str::random(16), 0);
        $claim = ($options['protected_claim'] ?? true)
            ? $this->within($tenant, fn () => app(FirstTurnProtectedClaim::class)->claim($http, $command))
            : app(ConversationRepository::class)->claimTurn($tenant, $command);
        $stage = $this->within($tenant, fn () => app(SellerExtractorStages::class)->prepare($http, $claim));
        [$request] = $this->within($tenant, fn () => app(SellerExtractorStages::class)->load($http, $claim, $stage));
        $directory = storage_path('app/state-fixtures-'.Str::random(12));
        mkdir($directory, 0700, true);
        config(['features.E0-05.defaults.fixture_directory' => $directory]);
        $file = $directory.DIRECTORY_SEPARATOR.CanonicalJson::hash(app(ContactMasker::class)->request($request->toArray())).'.json';
        $content = ($options['invalid'] ?? false) ? 'not-json' : ($options['content'] ?? '{"client_intent":"answer_only","slot_updates":{"city":"Москва","email":"[EMAIL]"}}');
        file_put_contents($file, json_encode(['result' => ['content' => $content, 'toolCalls' => [], 'usage' => ['promptTokens' => 1, 'completionTokens' => 1],
            'model' => $request->model, 'latencyMs' => 1, 'costMicros' => array_key_exists('cost', $options) ? $options['cost'] : 0]], JSON_THROW_ON_ERROR));
        $status = $this->within($tenant, fn () => app(Gateway::class)->completeProtectedExtractor($http, $claim, $stage));
        self::assertSame(array_key_exists('cost', $options) && $options['cost'] === null ? 'uncertain' : 'completed', $status);
        unlink($file);

        return [$tenant, $http, $claim, $stage];
    }

    private function within(TenantContext $tenant, \Closure $action): mixed
    {
        return app(TenantContextRunner::class)->run($tenant, $action);
    }
}

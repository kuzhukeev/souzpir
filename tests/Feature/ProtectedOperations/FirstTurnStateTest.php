<?php

namespace Tests\Feature\ProtectedOperations;

use App\Ai\CanonicalJson;
use App\Ai\ContactMasker;
use App\Ai\Gateway;
use App\Ai\Protected\SellerExtractorStages;
use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\ProtectedOperations\FirstTurnGoal;
use App\ProtectedOperations\FirstTurnState;
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
use Tests\TestCase;

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

    public function test_old_or_nonempty_state_is_rejected_even_when_extractor_stage_is_valid(): void
    {
        foreach ([['state' => ['slots' => ['city' => 'Forged']]], ['history' => true], ['changed' => true], ['mode' => 'manager']] as $case) {
            [$tenant, $http, $claim, $stage] = $this->fixture('Synthetic input', $case);
            $this->within($tenant, function () use ($http, $claim, $stage): void {
                try {
                    app(FirstTurnState::class)->prepare($http, $claim, $stage);
                    self::fail('Unproven initial state was accepted.');
                } catch (LogicException $error) {
                    self::assertSame('Protected first-turn state unavailable.', $error->getMessage());
                    self::assertNull($error->getPrevious());
                }
                self::assertSame(0, DB::table('c02_first_turn_states')->where('turn_id', $claim->turnId)->count());
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
            self::assertSame('intro_and_ask', $result['goal']->kind);
            self::assertSame('company_location', $result['goal']->facts[0]['id']);
            self::assertNotEmpty($result['goal']->facts[0]['facts']);
            self::assertSame('company_location', $result['goal']->toArray()['facts'][0]);
            self::assertTrue($result['state']['flags']['introduced']);
            self::assertSame('qualify', $result['state']['stage']);
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
        $claim = app(ConversationRepository::class)->claimTurn($tenant, new TurnCommand($conversation->id, $input->id, $input->text, null, 'state-stage-'.Str::random(16), 0));
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

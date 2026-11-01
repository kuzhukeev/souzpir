<?php

namespace Tests\Feature\ProtectedOperations;

use App\Models\Brand;
use App\Models\Organization;
use App\ProtectedOperations\ManualReviewRequests;
use App\Seller\Conversations\Contracts\ConversationRepository;
use App\Seller\Conversations\Data\TurnCommand;
use App\Seller\Conversations\Models\Conversation;
use App\Seller\Conversations\Models\Message;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ManualReviewRequestsHttpTest extends TenancyTestCase
{
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

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        // Test-only adapter exercises the real web/session/tenant middleware;
        // this does not register a production endpoint.
        Route::middleware(['web', 'auth', 'verified', 'tenant', 'auth.version', 'auth.nostore'])
            ->match(['get', 'post'], '/test-c02/review/{turn}', function (Request $request, string $turn) {
                try {
                    $data = app(ManualReviewRequests::class)->readOrRequest($request, $turn, $request->isMethod('post'));
                } catch (\LogicException) {
                    return response()->json(['message' => 'Ручная проверка временно недоступна.'], 503)->header('Cache-Control', 'private, no-store');
                }

                return response()->json(['data' => $data])->header('Cache-Control', 'private, no-store');
            })->whereUlid('turn');
    }

    public function refreshDatabase(): void
    {
        $this->beforeRefreshingDatabase();
        $this->artisan('migrate:fresh', $this->migrateFreshUsing());
        $this->afterRefreshingDatabase();
        $this->beforeApplicationDestroyed(static function (): void {
            RefreshDatabaseState::$migrated = false;
        });
    }

    public function test_uncertain_turn_can_be_requested_for_review_once_without_resolving_or_replaying_it(): void
    {
        [$owner, $org, $brand] = $this->configuredCabinet();
        $turn = $this->turn($org, $brand, 'uncertain');
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $path = '/test-c02/review/'.$turn->turnId;
        $before = DB::table('seller_turns')->where('id', $turn->turnId)->first();
        $oldProjection = $this->getJson('/seller/turns/pending/'.$turn->turnId)->assertOk()->json();
        $this->getJson($path)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson(['data' => ['review' => 'not_requested', 'other_effects_may_exist' => true]]);
        $expected = ['data' => ['review' => 'requested', 'reason' => 'outcome_unconfirmed', 'other_effects_may_exist' => true]];
        $response = $this->postJson($path)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertExactJson($expected);
        self::assertStringNotContainsString('private synthetic text', $response->getContent());
        self::assertStringNotContainsString($turn->token(), $response->getContent());
        self::assertStringNotContainsString(session()->getId(), $response->getContent());
        $this->postJson($path)->assertOk()->assertExactJson($expected);
        $this->getJson($path)->assertOk()->assertExactJson($expected);
        self::assertSame(1, DB::table('c02_manual_review_requests')->where('turn_id', $turn->turnId)->count());
        self::assertSame(1, DB::table('audit_logs')->where('action', 'seller.turn.review_requested')->where('subject_id', $turn->turnId)->count());
        self::assertEquals($before, DB::table('seller_turns')->where('id', $turn->turnId)->first());
        self::assertSame($oldProjection, $this->getJson('/seller/turns/pending/'.$turn->turnId)->assertOk()->json());
        $input = DB::table('messages')->where('id', $before->input_message_id)->first();
        $replay = app(ConversationRepository::class)->claimTurn(new TenantContext($org->id, $brand->id, source: 'job'),
            new TurnCommand($turn->conversationId, $input->id, $input->text, null, $before->idempotency_key, $before->base_version));
        self::assertSame('uncertain', $replay->status);
        self::assertNull($replay->token());
        self::assertNull($replay->outcome);
        [$admin] = $this->member($org, 'admin', [$brand->id]);
        $this->actingAs($admin)->withSession(['brand_id' => $brand->id]);
        $this->postJson($path)->assertOk()->assertExactJson($expected);
        self::assertSame(1, DB::table('c02_manual_review_requests')->where('turn_id', $turn->turnId)->count());
        self::assertSame(1, DB::table('audit_logs')->where('action', 'seller.turn.review_requested')->where('subject_id', $turn->turnId)->count());
        self::assertSame(0, DB::table('ai_calls')->count());
        self::assertSame(0, DB::table('seller_tasks')->count());
        self::assertSame(0, DB::table('outbox_events')->count());
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_review_rejects_wrong_brand_role_session_processing_and_client_supplied_reason(): void
    {
        [$owner, $org, $brand, $other] = $this->configuredCabinet();
        $turn = $this->turn($org, $brand, 'uncertain');
        $foreign = $this->turn($org, $other, 'uncertain');
        $processing = $this->turn($org, $brand, 'processing');
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $path = '/test-c02/review/'.$turn->turnId;
        $this->postJson($path, ['reason' => 'safe_to_retry'])->assertStatus(422);
        $this->postJson('/test-c02/review/'.$foreign->turnId)->assertNotFound()->assertDontSee($foreign->conversationId);
        $this->getJson('/test-c02/review/'.$foreign->turnId)->assertNotFound()->assertDontSee($foreign->conversationId);
        $this->postJson('/test-c02/review/'.$processing->turnId)->assertNotFound();
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $this->postJson($path)->assertForbidden();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->getJson($path)->assertOk();
        DB::table('auth_session_registry')->where('user_id', $owner->id)->update(['revoked_at' => now()]);
        $this->postJson($path)->assertUnauthorized()->assertDontSee($turn->turnId);
        $this->getJson($path)->assertUnauthorized()->assertDontSee($turn->turnId);
        self::assertSame(0, DB::table('c02_manual_review_requests')->count());
        self::assertSame(0, DB::table('audit_logs')->where('action', 'seller.turn.review_requested')->count());
        self::assertSame('uncertain', DB::table('seller_turns')->where('id', $turn->turnId)->value('status'));
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_forged_review_record_is_not_accepted_or_replaced(): void
    {
        [$owner, $org, $brand] = $this->configuredCabinet();
        $turn = $this->turn($org, $brand, 'uncertain');
        DB::table('c02_manual_review_requests')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id,
            'brand_id' => $brand->id, 'conversation_id' => $turn->conversationId, 'turn_id' => $turn->turnId,
            'actor_id' => $owner->id, 'session_hash' => str_repeat('a', 64), 'reason' => 'outcome_unconfirmed',
            'seal' => str_repeat('b', 64), 'created_at' => now()]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $path = '/test-c02/review/'.$turn->turnId;
        $this->getJson($path)->assertStatus(503)->assertExactJson(['message' => 'Ручная проверка временно недоступна.']);
        $this->postJson($path)->assertStatus(503)->assertDontSee($turn->turnId);
        self::assertSame(1, DB::table('c02_manual_review_requests')->count());
        self::assertSame(0, DB::table('audit_logs')->where('action', 'seller.turn.review_requested')->count());
        self::assertSame('uncertain', DB::table('seller_turns')->where('id', $turn->turnId)->value('status'));
        Http::assertNothingSent();
    }

    public function test_audit_failure_rolls_back_request_and_a_later_retry_creates_it_once(): void
    {
        [$owner, $org, $brand] = $this->configuredCabinet();
        $turn = $this->turn($org, $brand, 'uncertain');
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $path = '/test-c02/review/'.$turn->turnId;
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION c02_test_fail_review_audit() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF NEW.action = 'seller.turn.review_requested' THEN
    RAISE EXCEPTION 'synthetic audit failure';
  END IF;
  RETURN NEW;
END;
$$;
CREATE TRIGGER c02_test_fail_review_audit BEFORE INSERT ON audit_logs FOR EACH ROW EXECUTE FUNCTION c02_test_fail_review_audit();
SQL);
        try {
            $this->postJson($path)->assertStatus(503)->assertDontSee($turn->turnId);
            self::assertSame(0, DB::table('c02_manual_review_requests')->count());
            self::assertSame(0, DB::table('audit_logs')->where('action', 'seller.turn.review_requested')->count());
            self::assertSame('uncertain', DB::table('seller_turns')->where('id', $turn->turnId)->value('status'));
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS c02_test_fail_review_audit ON audit_logs; DROP FUNCTION IF EXISTS c02_test_fail_review_audit();');
        }
        $this->postJson($path)->assertOk()->assertJsonPath('data.review', 'requested');
        self::assertSame(1, DB::table('c02_manual_review_requests')->count());
        self::assertSame(1, DB::table('audit_logs')->where('action', 'seller.turn.review_requested')->count());
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_database_blocks_rewriting_review_history_and_requests_for_processing_turns(): void
    {
        [$owner, $org, $brand] = $this->configuredCabinet();
        $uncertain = $this->turn($org, $brand, 'uncertain');
        $processing = $this->turn($org, $brand, 'processing');
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->postJson('/test-c02/review/'.$uncertain->turnId)->assertOk();
        foreach ([
            fn () => DB::table('c02_manual_review_requests')->update(['reason' => 'other']),
            fn () => DB::table('c02_manual_review_requests')->delete(),
            fn () => DB::statement('TRUNCATE c02_manual_review_requests'),
            fn () => DB::table('c02_manual_review_requests')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id,
                'brand_id' => $brand->id, 'conversation_id' => $processing->conversationId, 'turn_id' => $processing->turnId,
                'actor_id' => $owner->id, 'session_hash' => str_repeat('a', 64), 'reason' => 'outcome_unconfirmed',
                'seal' => str_repeat('b', 64), 'created_at' => now()]),
        ] as $forbidden) {
            try {
                DB::transaction($forbidden);
                self::fail('The database accepted invalid review history.');
            } catch (QueryException $error) {
                self::assertSame('23514', $error->errorInfo[0]);
            }
        }
        self::assertSame(1, DB::table('c02_manual_review_requests')->count());
        self::assertSame('processing', DB::table('seller_turns')->where('id', $processing->turnId)->value('status'));
    }

    public function test_migration_preserves_legacy_uncertain_turn_and_refuses_to_discard_review_history(): void
    {
        [$owner, $org, $brand] = $this->configuredCabinet();
        $turn = $this->turn($org, $brand, 'uncertain');
        $before = DB::table('seller_turns')->where('id', $turn->turnId)->first();
        $migration = require database_path('migrations/C-02/2026_11_01_130000_c02_create_manual_review_requests.php');
        $migration->down();
        $migration->up();
        self::assertEquals($before, DB::table('seller_turns')->where('id', $turn->turnId)->first());
        self::assertSame(0, DB::table('c02_manual_review_requests')->count());
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $path = '/test-c02/review/'.$turn->turnId;
        $this->getJson($path)->assertOk()->assertJsonPath('data.review', 'not_requested');
        $this->postJson($path)->assertOk()->assertJsonPath('data.review', 'requested');
        try {
            $migration->down();
            self::fail('Migration discarded manual review history.');
        } catch (\LogicException $error) {
            self::assertSame('Cannot discard manual review requests.', $error->getMessage());
        }
        self::assertSame(1, DB::table('c02_manual_review_requests')->count());
        self::assertEquals($before, DB::table('seller_turns')->where('id', $turn->turnId)->first());
    }

    public function test_two_processes_request_the_same_review_once(): void
    {
        [$owner, $org, $brand, , $membership] = $this->configuredCabinet();
        $turn = $this->turn($org, $brand, 'uncertain');
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->getJson('/test-c02/review/'.$turn->turnId)->assertOk();
        $workers = [];
        $markers = [];
        DB::beginTransaction();
        try {
            DB::table('seller_turns')->where('id', $turn->turnId)->lockForUpdate()->first();
            for ($i = 0; $i < 2; $i++) {
                $marker = base_path('.local/tmp/c02-review-'.Str::random(16));
                $process = new Process([PHP_BINARY, '-d', 'sys_temp_dir='.base_path('.local/tmp'), '-d', 'allow_url_fopen=0',
                    '-d', 'disable_functions=fsockopen,pfsockopen,stream_socket_client,curl_exec,curl_multi_exec',
                    base_path('tests/Feature/ProtectedOperations/Fixtures/manual-review-worker.php'), $marker], base_path());
                $process->setInput(json_encode(['org' => $org->id, 'brand' => $brand->id, 'user' => $owner->id,
                    'member' => $membership->id, 'session' => session()->getId(), 'turn' => $turn->turnId], JSON_THROW_ON_ERROR));
                $process->setTimeout(45);
                $workers[] = $process;
                $markers[] = $marker;
                $process->start();
                $deadline = microtime(true) + 25;
                while (! is_file($marker) && $process->isRunning() && microtime(true) < $deadline) {
                    usleep(10000);
                }
                self::assertFileExists($marker, $process->getErrorOutput());
                $pid = (int) file_get_contents($marker);
                do {
                    DB::select('select pg_stat_clear_snapshot()');
                    $waiting = DB::table('pg_stat_activity')->where('pid', $pid)->where('wait_event_type', 'Lock')->exists();
                    if (! $waiting) {
                        usleep(10000);
                    }
                } while (! $waiting && $process->isRunning() && microtime(true) < $deadline);
                self::assertTrue($waiting, 'Concurrent request must wait for a lock.');
            }
            DB::commit();
            foreach ($workers as $process) {
                $process->wait();
                self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
                self::assertSame(['review' => 'requested', 'reason' => 'outcome_unconfirmed', 'other_effects_may_exist' => true],
                    json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR));
            }
            self::assertSame(1, DB::table('c02_manual_review_requests')->where('turn_id', $turn->turnId)->count());
            self::assertSame(1, DB::table('audit_logs')->where('action', 'seller.turn.review_requested')->where('subject_id', $turn->turnId)->count());
            self::assertSame('uncertain', DB::table('seller_turns')->where('id', $turn->turnId)->value('status'));
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($workers as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            foreach ($markers as $marker) {
                if (is_file($marker)) {
                    unlink($marker);
                }
            }
        }
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    private function configuredCabinet(): array
    {
        $cabinet = $this->cabinet();
        [, $org, $first, $second] = $cabinet;
        foreach ([$first, $second] as $brand) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id,
                'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }

        return $cabinet;
    }

    private function turn(Organization $organization, Brand $brand, string $status): object
    {
        $tenant = new TenantContext($organization->id, $brand->id, source: 'job');

        return app(TenantContextRunner::class)->run($tenant, function () use ($tenant, $status): object {
            $conversation = Conversation::create(['state' => []]);
            $message = Message::create(['conversation_id' => $conversation->id, 'role' => 'client', 'text' => 'private synthetic text', 'meta' => []]);
            $claim = app(ConversationRepository::class)->claimTurn($tenant, new TurnCommand($conversation->id, $message->id, $message->text, null, 'list-'.Str::random(24), 0));
            if ($status === 'uncertain') {
                Message::create(['conversation_id' => $conversation->id, 'role' => 'client', 'text' => 'later private synthetic text', 'meta' => []]);
            } elseif ($status === 'completed') {
                DB::table('seller_turns')->where('id', $claim->turnId)->update(['status' => 'completed']);
            }

            return $claim;
        });
    }
}

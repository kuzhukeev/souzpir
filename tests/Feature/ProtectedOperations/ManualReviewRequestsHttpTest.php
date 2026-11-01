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
use PHPUnit\Framework\Attributes\DataProvider;
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
        Route::middleware(['web', 'auth', 'verified', 'tenant', 'auth.version', 'auth.nostore'])
            ->post('/test-c02/quarantine-review/{turn}', function (Request $request, string $turn) {
                try {
                    $data = app(ManualReviewRequests::class)->quarantineAndRequest($request, $turn);
                } catch (\LogicException) {
                    return response()->json(['message' => 'Ручная проверка временно недоступна.'], 503)->header('Cache-Control', 'private, no-store');
                }

                return response()->json(['data' => $data])->header('Cache-Control', 'private, no-store');
            })->whereUlid('turn');
        Route::middleware(['web', 'auth', 'verified', 'tenant', 'auth.version', 'auth.nostore'])
            ->get('/test-c02/reviews', function (Request $request) {
                try {
                    $data = app(ManualReviewRequests::class)->listRequested($request);
                } catch (\LogicException) {
                    return response()->json(['message' => 'Ручная проверка временно недоступна.'], 503)->header('Cache-Control', 'private, no-store');
                }

                return response()->json($data)->header('Cache-Control', 'private, no-store');
            });
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

    public function test_quarantine_and_review_are_atomic_idempotent_and_authorized(): void
    {
        [$owner, $org, $brand, $other] = $this->configuredCabinet();
        $turn = $this->turn($org, $brand, 'processing');
        $foreign = $this->turn($org, $other, 'processing');
        $completed = $this->turn($org, $brand, 'completed');
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $path = '/test-c02/quarantine-review/'.$turn->turnId;
        $before = DB::table('seller_turns')->where('id', $turn->turnId)->first();
        $this->postJson($path, ['reason' => 'safe_to_retry'])->assertStatus(422);
        $this->postJson('/test-c02/quarantine-review/'.$foreign->turnId)->assertNotFound()->assertDontSee($foreign->turnId);
        $this->postJson('/test-c02/quarantine-review/'.$completed->turnId)->assertNotFound();
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $this->postJson($path)->assertForbidden()->assertDontSee($turn->turnId);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->getJson('/test-c02/review/'.$turn->turnId)->assertNotFound();

        foreach (['seller.turn.quarantined', 'seller.turn.review_requested'] as $action) {
            DB::unprepared("CREATE OR REPLACE FUNCTION c02_test_fail_combined_audit() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF NEW.action = '".$action."' THEN RAISE EXCEPTION 'synthetic audit failure'; END IF; RETURN NEW; END; \$\$;");
            DB::unprepared('CREATE TRIGGER c02_test_fail_combined_audit BEFORE INSERT ON audit_logs FOR EACH ROW EXECUTE FUNCTION c02_test_fail_combined_audit()');
            try {
                $this->postJson($path)->assertStatus(503)->assertDontSee($turn->turnId);
                self::assertEquals($before, DB::table('seller_turns')->where('id', $turn->turnId)->first());
                self::assertSame(0, DB::table('c02_manual_review_requests')->count());
                self::assertSame(0, DB::table('audit_logs')->whereIn('action', ['seller.turn.quarantined', 'seller.turn.review_requested'])->count());
            } finally {
                DB::unprepared('DROP TRIGGER IF EXISTS c02_test_fail_combined_audit ON audit_logs; DROP FUNCTION IF EXISTS c02_test_fail_combined_audit();');
            }
        }
        $expected = ['data' => ['review' => 'requested', 'reason' => 'outcome_unconfirmed', 'other_effects_may_exist' => true]];
        $this->postJson($path)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertExactJson($expected);
        $this->postJson($path)->assertOk()->assertExactJson($expected);
        self::assertSame('uncertain', DB::table('seller_turns')->where('id', $turn->turnId)->value('status'));
        self::assertSame(1, DB::table('c02_manual_review_requests')->where('turn_id', $turn->turnId)->count());
        foreach (['seller.turn.quarantined', 'seller.turn.review_requested'] as $action) {
            self::assertSame(1, DB::table('audit_logs')->where('subject_id', $turn->turnId)->where('action', $action)->count());
        }
        $alreadyUncertain = $this->turn($org, $brand, 'uncertain');
        $this->postJson('/test-c02/quarantine-review/'.$alreadyUncertain->turnId)->assertOk()->assertExactJson($expected);
        self::assertSame(0, DB::table('audit_logs')->where('subject_id', $alreadyUncertain->turnId)->where('action', 'seller.turn.quarantined')->count());
        self::assertSame(1, DB::table('audit_logs')->where('subject_id', $alreadyUncertain->turnId)->where('action', 'seller.turn.review_requested')->count());
        DB::table('auth_session_registry')->where('user_id', $owner->id)->update(['revoked_at' => now()]);
        $this->postJson($path)->assertUnauthorized()->assertDontSee($turn->turnId);
        self::assertSame(0, DB::table('ai_calls')->count());
        self::assertSame(0, DB::table('seller_tasks')->count());
        self::assertSame(0, DB::table('outbox_events')->count());
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_review_list_is_scoped_authorized_and_does_not_change_turns(): void
    {
        [$owner, $org, $brand, $other] = $this->configuredCabinet();
        $first = $this->turn($org, $brand, 'uncertain');
        $second = $this->turn($org, $brand, 'uncertain');
        $foreign = $this->turn($org, $other, 'uncertain');
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->getJson('/test-c02/reviews')->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson(['data' => [], 'limit' => 100, 'has_more' => false]);
        $this->postJson('/test-c02/review/'.$first->turnId)->assertOk();
        $this->postJson('/test-c02/review/'.$second->turnId)->assertOk();
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->postJson('/test-c02/review/'.$foreign->turnId)->assertOk();
        $this->post('/brand/switch', ['brand_id' => $brand->id])->assertRedirect();
        $before = DB::table('seller_turns')->whereIn('id', [$first->turnId, $second->turnId, $foreign->turnId])->orderBy('id')->get();
        $audits = DB::table('audit_logs')->count();
        $expected = static fn (object $turn): array => ['conversation_id' => $turn->conversationId, 'turn_id' => $turn->turnId,
            'reason' => 'outcome_unconfirmed', 'other_effects_may_exist' => true];
        $result = $this->getJson('/test-c02/reviews')->assertOk()->assertExactJson([
            'data' => [$expected($second), $expected($first)], 'limit' => 100, 'has_more' => false]);
        $result->assertDontSee($foreign->turnId)->assertDontSee('private synthetic text')
            ->assertDontSee($first->token())->assertDontSee(session()->getId());
        $this->getJson('/test-c02/reviews?turn_id='.$foreign->turnId)->assertStatus(422);
        self::assertEquals($before, DB::table('seller_turns')->whereIn('id', [$first->turnId, $second->turnId, $foreign->turnId])->orderBy('id')->get());
        self::assertSame($audits, DB::table('audit_logs')->count());
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $this->getJson('/test-c02/reviews')->assertForbidden()->assertDontSee($first->turnId);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->getJson('/test-c02/reviews')->assertOk();
        DB::table('auth_session_registry')->where('user_id', $owner->id)->update(['revoked_at' => now()]);
        $this->getJson('/test-c02/reviews')->assertUnauthorized()->assertDontSee($first->turnId);
        self::assertSame(0, DB::table('ai_calls')->count());
        self::assertSame(0, DB::table('seller_tasks')->count());
        self::assertSame(0, DB::table('outbox_events')->count());
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_review_list_fails_closed_for_a_forged_record(): void
    {
        [$owner, $org, $brand] = $this->configuredCabinet();
        $turn = $this->turn($org, $brand, 'uncertain');
        $forgedId = (string) Str::ulid();
        DB::table('c02_manual_review_requests')->insert(['id' => $forgedId, 'organization_id' => $org->id,
            'brand_id' => $brand->id, 'conversation_id' => $turn->conversationId, 'turn_id' => $turn->turnId,
            'actor_id' => $owner->id, 'session_hash' => str_repeat('a', 64), 'reason' => 'outcome_unconfirmed',
            'seal' => str_repeat('b', 64), 'created_at' => now()]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->getJson('/test-c02/reviews')->assertStatus(503)->assertExactJson(['message' => 'Ручная проверка временно недоступна.'])
            ->assertDontSee($turn->turnId);
        $cursor = $forgedId.'.'.hash_hmac('sha256', 'c02-review-page:'.$org->id.':'.$brand->id.':'.$forgedId, config('app.key'));
        $this->getJson('/test-c02/reviews?cursor='.$cursor)->assertStatus(503)->assertDontSee($turn->turnId);
        self::assertSame(0, DB::table('audit_logs')->where('action', 'seller.turn.review_requested')->count());
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_review_list_isolates_organizations_and_rechecks_lost_module_access(): void
    {
        [$owner, $org, $brand] = $this->configuredCabinet();
        $own = $this->turn($org, $brand, 'uncertain');
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->postJson('/test-c02/review/'.$own->turnId)->assertOk();
        $ownId = DB::table('c02_manual_review_requests')->where('turn_id', $own->turnId)->value('id');
        $ownCursor = $ownId.'.'.hash_hmac('sha256', 'c02-review-page:'.$org->id.':'.$brand->id.':'.$ownId, config('app.key'));

        [$otherOwner, $otherOrg, $otherBrand] = $this->configuredCabinet();
        $foreign = $this->turn($otherOrg, $otherBrand, 'uncertain');
        $this->actingAs($otherOwner)->withSession(['brand_id' => $otherBrand->id]);
        $this->postJson('/test-c02/review/'.$foreign->turnId)->assertOk();
        $this->getJson('/test-c02/reviews')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.turn_id', $foreign->turnId)->assertDontSee($own->turnId);
        $this->getJson('/test-c02/reviews?cursor='.$ownCursor)->assertStatus(422)->assertDontSee($own->turnId)->assertDontSee($foreign->turnId);
        DB::table('brand_modules')->where('brand_id', $otherBrand->id)->where('module', 'seller')->update(['enabled' => false]);
        $this->getJson('/test-c02/reviews')->assertForbidden()->assertDontSee($foreign->turnId);
        self::assertSame(2, DB::table('c02_manual_review_requests')->count());
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_review_list_pages_all_results_without_duplicates_or_skips_and_rejects_bad_cursors(): void
    {
        [$owner, $org, $brand, $otherBrand] = $this->configuredCabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        for ($i = 0; $i < 205; $i++) {
            $turn = $this->turn($org, $brand, 'uncertain');
            $this->postJson('/test-c02/review/'.$turn->turnId)->assertOk();
        }
        $expectedIds = DB::table('c02_manual_review_requests')->where('brand_id', $brand->id)
            ->orderByDesc('id')->pluck('turn_id')->all();
        $first = $this->getJson('/test-c02/reviews')->assertOk()->assertJsonCount(100, 'data')
            ->assertJsonPath('limit', 100)->assertJsonPath('has_more', true);
        $cursor = $first->json('next_cursor');
        self::assertIsString($cursor);
        $newTurn = $this->turn($org, $brand, 'uncertain');
        $this->postJson('/test-c02/review/'.$newTurn->turnId)->assertOk();
        $second = $this->getJson('/test-c02/reviews?cursor='.$cursor)->assertOk()->assertJsonCount(100, 'data')
            ->assertJsonPath('has_more', true);
        $third = $this->getJson('/test-c02/reviews?cursor='.$second->json('next_cursor'))->assertOk()
            ->assertJsonCount(5, 'data')->assertJsonPath('has_more', false)->assertJsonMissingPath('next_cursor');
        $ids = array_merge(array_column($first->json('data'), 'turn_id'), array_column($second->json('data'), 'turn_id'), array_column($third->json('data'), 'turn_id'));
        self::assertSame($expectedIds, $ids);
        self::assertCount(205, array_unique($ids));
        self::assertNotContains($newTurn->turnId, $ids);
        $this->getJson('/test-c02/reviews')->assertOk()->assertJsonPath('data.0.turn_id', $newTurn->turnId);
        $tampered = substr($cursor, 0, -1).(str_ends_with($cursor, 'a') ? 'b' : 'a');
        $this->getJson('/test-c02/reviews?cursor='.$tampered)->assertStatus(422)->assertDontSee($ids[0]);
        $this->getJson('/test-c02/reviews?cursor='.str_repeat('a', 1000))->assertStatus(422)->assertDontSee($ids[0]);
        $sign = static fn (string $id): string => $id.'.'.hash_hmac('sha256', 'c02-review-page:'.$org->id.':'.$brand->id.':'.$id, config('app.key'));
        $this->getJson('/test-c02/reviews?cursor='.$sign((string) Str::ulid()))->assertStatus(422)->assertDontSee($ids[0]);
        $lastId = DB::table('c02_manual_review_requests')->where('brand_id', $brand->id)->orderBy('id')->value('id');
        $this->getJson('/test-c02/reviews?cursor='.$sign($lastId))->assertOk()
            ->assertExactJson(['data' => [], 'limit' => 100, 'has_more' => false]);
        $this->post('/brand/switch', ['brand_id' => $otherBrand->id])->assertRedirect();
        $this->getJson('/test-c02/reviews?cursor='.$cursor)->assertStatus(422)->assertDontSee($ids[0]);
        $this->post('/brand/switch', ['brand_id' => $brand->id])->assertRedirect();
        DB::table('auth_session_registry')->where('user_id', $owner->id)->update(['revoked_at' => now()]);
        $this->getJson('/test-c02/reviews?cursor='.$cursor)->assertUnauthorized()->assertDontSee($ids[0]);
        self::assertSame(206, DB::table('c02_manual_review_requests')->count());
        self::assertSame(206, DB::table('audit_logs')->where('action', 'seller.turn.review_requested')->count());
        self::assertSame(206, DB::table('seller_turns')->where('status', 'uncertain')->count());
        Http::assertNothingSent();
        Queue::assertNothingPushed();
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
        $this->postJson('/test-c02/quarantine-review/'.$turn->turnId)->assertStatus(503)->assertDontSee($turn->turnId);
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

    #[DataProvider('reviewModes')]
    public function test_two_processes_request_the_same_review_once(string $mode, string $status): void
    {
        [$owner, $org, $brand, , $membership] = $this->configuredCabinet();
        $turn = $this->turn($org, $brand, $status);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        if ($status === 'uncertain') {
            $this->getJson('/test-c02/review/'.$turn->turnId)->assertOk();
        } else {
            $this->getJson('/test-c02/reviews')->assertOk();
        }
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
                    'member' => $membership->id, 'session' => session()->getId(), 'turn' => $turn->turnId, 'mode' => $mode], JSON_THROW_ON_ERROR));
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
            self::assertSame($mode === 'quarantine' ? 1 : 0, DB::table('audit_logs')->where('action', 'seller.turn.quarantined')->where('subject_id', $turn->turnId)->count());
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

    public static function reviewModes(): array
    {
        return ['existing uncertain' => ['review', 'uncertain'], 'quarantine and request' => ['quarantine', 'processing']];
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

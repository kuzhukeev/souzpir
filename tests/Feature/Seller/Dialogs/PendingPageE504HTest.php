<?php

namespace Tests\Feature\Seller\Dialogs;

use App\Models\Brand;
use App\Models\Organization;
use App\Seller\Conversations\Contracts\ConversationRepository;
use App\Seller\Conversations\Data\TurnCommand;
use App\Seller\Conversations\Models\Conversation;
use App\Seller\Conversations\Models\Message;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class PendingPageE504HTest extends TenancyTestCase
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
    }

    public function test_owner_sees_only_selected_brand_pending_states_with_cursor_and_no_changes(): void
    {
        [$owner, $org, $brand, $other] = $this->configuredCabinet();
        $uncertain = $this->turn($org, $brand, 'uncertain');
        $processing = $this->turn($org, $brand, 'processing');
        $completed = $this->turn($org, $brand, 'completed');
        $hidden = $this->turn($org, $other, 'uncertain');
        $before = DB::table('seller_turns')->orderBy('id')->get()->toArray();
        $messagesBefore = DB::table('messages')->count();
        $callsBefore = DB::table('ai_calls')->count();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);

        $this->get('/seller/dialogs/pending')->assertOk()->assertInertia(fn ($page) => $page->component('Seller/Dialogs/Pending'));
        $this->getJson('/seller/turns/pending?cursor=bad')->assertUnprocessable();
        $first = $this->getJson('/seller/turns/pending?limit=1')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        self::assertCount(1, $first->json('data'));
        $cursor = $first->json('next_cursor');
        self::assertNotNull($cursor);
        $second = $this->getJson('/seller/turns/pending?limit=1&cursor='.$cursor)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        self::assertNull($second->json('next_cursor'));
        $items = [...$first->json('data'), ...$second->json('data')];
        self::assertEqualsCanonicalizing([$uncertain->turnId, $processing->turnId], array_column($items, 'turn_id'));
        self::assertEqualsCanonicalizing(['uncertain', 'processing'], array_column($items, 'status'));
        $byTurn = collect($items)->keyBy('turn_id');
        self::assertSame($uncertain->conversationId, $byTurn[$uncertain->turnId]['conversation_id']);
        self::assertSame($processing->conversationId, $byTurn[$processing->turnId]['conversation_id']);
        foreach ($items as $item) {
            self::assertEqualsCanonicalizing(['conversation_id', 'turn_id', 'status', 'created_at', 'updated_at'], array_keys($item));
        }
        $body = $first->getContent().$second->getContent();
        self::assertStringNotContainsString($hidden->turnId, $body);
        self::assertStringNotContainsString($hidden->conversationId, $body);
        self::assertStringNotContainsString($completed->turnId, $body);
        self::assertStringNotContainsString('private synthetic text', $body);
        self::assertStringNotContainsString($uncertain->token(), $body);
        self::assertEquals($before, DB::table('seller_turns')->orderBy('id')->get()->toArray());
        self::assertSame($messagesBefore, DB::table('messages')->count());
        self::assertSame($callsBefore, DB::table('ai_calls')->count());
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->get('/seller/dialogs/pending')->assertForbidden();
        Queue::assertNothingPushed();
        Http::assertNothingSent();
        $this->postJson('/seller/turns/pending')->assertStatus(405);
        self::assertEquals($before, DB::table('seller_turns')->orderBy('id')->get()->toArray());
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

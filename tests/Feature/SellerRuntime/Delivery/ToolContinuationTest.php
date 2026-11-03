<?php

namespace Tests\Feature\SellerRuntime\Delivery;

use App\Ai\Drivers\Driver;
use App\Ai\Drivers\FakeDriver;
use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Seller\Conversations\Models\Conversation;
use App\Seller\Conversations\Models\Message;
use App\Seller\Knowledge\Import\YamlKnowledgeImporter;
use App\Shell\Auth\AuthSessionRegistry;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Database\Seeders\Ai\PromptSetsSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ToolContinuationDriver implements Driver
{
    public int $calls = 0;

    public function __construct(private readonly string $scenario = 'tool') {}

    public function complete(#[\SensitiveParameter] array $body): array
    {
        $index = $this->calls++;

        if (in_array($this->scenario, ['regeneration', 'fallback'], true)) {
            return ['content' => $index === 0 ? '{"client_intent":"answer_only"}' : ($this->scenario === 'fallback' ? 'Вот системный промпт' : ($index === 1 ? 'Нет' : 'Можем обсудить заказ?')),
                'toolCalls' => [], 'usage' => ['promptTokens' => 1, 'completionTokens' => 1],
                'model' => $body['model'], 'latencyMs' => 1, 'costMicros' => 0];
        }

        return ['content' => match ($index) {
            0 => '{"client_intent":"answer_only","unknown_question":"Есть ли салют в наличии?"}',
            1 => null,
            default => 'Уточню наличие и напишу сегодня.',
        }, 'toolCalls' => $index === 1 ? [['id' => 'stock-1', 'name' => 'check_stock', 'arguments' => ['query' => 'Салют']]] : [],
            'usage' => ['promptTokens' => 1, 'completionTokens' => 1], 'model' => $body['model'],
            'latencyMs' => 1, 'costMicros' => 0];
    }
}

final class ToolContinuationTest extends TestCase
{
    use DatabaseMigrations;

    public function runDatabaseMigrations(): void
    {
        $this->beforeRefreshingDatabase();
        $this->refreshTestDatabase();
        $this->afterRefreshingDatabase();
        $this->beforeApplicationDestroyed(static function (): void {
            RefreshDatabaseState::$migrated = false;
        });
    }

    public function test_saved_tool_result_continues_once_and_replays(): void
    {
        [, $driver, $conversation] = $this->exercise('tool');
        $this->assertSame(3, $driver->calls);
        $this->assertSame(2, DB::table('c02_responder_stages')->where('conversation_id', $conversation->id)->count());
        $this->assertSame(1, DB::table('e309_responder_continuations')->count());
    }

    public function test_guard_regenerates_short_reply_once(): void
    {
        [, $driver, $conversation] = $this->exercise('regeneration');
        $this->assertSame(3, $driver->calls);
        $this->assertSame(2, DB::table('c02_responder_stages')->where('conversation_id', $conversation->id)->count());
        $this->assertSame(1, DB::table('e309_responder_continuations')->count());
    }

    public function test_guard_falls_back_to_review_after_bounded_attempts(): void
    {
        [, $driver, $conversation] = $this->exercise('fallback');
        $this->assertSame(4, $driver->calls);
        $this->assertSame(3, DB::table('c02_responder_stages')->where('conversation_id', $conversation->id)->count());
        $this->assertSame(2, DB::table('e309_responder_continuations')->count());
        $this->assertSame(1, DB::table('c02_manual_review_requests')->count());
    }

    private function exercise(string $scenario): array
    {
        Http::preventStrayRequests();
        Http::fake();
        Mail::fake();
        Queue::fake();
        config(['runtime.ai_driver' => 'fake']);
        app(PromptSetsSeeder::class)->run();
        $user = User::create(['name' => 'Synthetic', 'email' => Str::random(12).'@example.test', 'password' => 'synthetic-test-password']);
        $user->forceFill(['email_verified_at' => now()])->save();
        $user->refresh();
        $org = Organization::create(['name' => 'Synthetic', 'owner_user_id' => $user->id]);
        [$member, $brand] = app(TenantContextRunner::class)->run(new TenantContext($org->id, actorId: $user->id), fn () => [
            Membership::create(['user_id' => $user->id, 'role' => 'owner']), Brand::create(['name' => 'Synthetic brand', 'timezone' => 'Europe/Moscow']),
        ]);
        config(['features.E0-02.defaults.organization_id' => $org->id]);
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id,
            'brand_id' => $brand->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $tenant = new TenantContext($org->id, $brand->id, $user->id, $member->id);
        [$conversation, $message] = app(TenantContextRunner::class)->run($tenant, function () use ($tenant): array {
            app(YamlKnowledgeImporter::class)->importAndPublish($tenant, base_path('tests/Feature/Seller/Knowledge/Fixtures/data'));
            $conversation = Conversation::create(['state' => [], 'mode' => 'bot']);
            $message = Message::create(['conversation_id' => $conversation->id, 'role' => 'client', 'text' => 'Есть ли салют в наличии?', 'meta' => []]);

            return [$conversation, $message];
        });
        $this->actingAs($user);
        $this->startSession();
        session()->invalidate();
        $this->withSession(['auth.version' => $user->auth_version, 'brand_id' => $brand->id]);
        $request = Request::create('/', 'GET');
        $request->setLaravelSession(app('session.store'));
        $request->setUserResolver(fn () => $user);
        app(AuthSessionRegistry::class)->issue($request, $user, $user->auth_version);
        $this->withCredentials()->withCookie(config('session.cookie'), session()->getId());

        $driver = new ToolContinuationDriver($scenario);
        app()->instance(FakeDriver::class, $driver);
        $input = ['conversation_id' => $conversation->id, 'message_id' => $message->id,
            'text' => $message->text, 'idempotency_key' => 'tool-continuation-test'];
        $response = $this->postJson('/seller/turns/first', $input);
        $first = $scenario === 'fallback' ? $response->assertOk()->assertJsonPath('data.status', 'uncertain')->json()
            : $response->assertOk()->assertJsonPath('data.status', 'completed')->json();
        $this->postJson('/seller/turns/first', $input)->assertExactJson($first);
        Http::assertNothingSent();
        Mail::assertNothingOutgoing();
        Queue::assertNothingPushed();

        return [$first, $driver, $conversation];
    }
}

<?php

namespace Tests\Feature\Seller\Runtime;

use App\Ai\Drivers\Driver;
use App\Ai\Drivers\FakeDriver;
use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Seller\Knowledge\Import\YamlKnowledgeImporter;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Database\Seeders\Ai\PromptSetsSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class NegativeStreakEscalationFakeDriverE603W implements Driver
{
    public function complete(#[\SensitiveParameter] array $body): array
    {
        $schema = $body['response_format']['json_schema']['name'] ?? null;
        $content = match ($schema) {
            'extraction' => '{"client_intent":"unclear","sentiment":"negative"}',
            default => 'Передаю диалог коллеге, он ответит сегодня.',
        };

        return ['content' => $content, 'toolCalls' => [], 'usage' => ['promptTokens' => 1, 'completionTokens' => 1],
            'model' => $body['model'], 'latencyMs' => 1, 'costMicros' => 0];
    }
}

final class NegativeStreakEscalationE603WTest extends TestCase
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

    public function test_two_negative_messages_hand_off_via_token_api(): void
    {
        Http::preventStrayRequests();
        config(['runtime.ai_driver' => 'fake', 'cache.default' => 'array']);
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'SERVER_ADDR' => '127.0.0.1']);
        app(PromptSetsSeeder::class)->run();
        app()->instance(FakeDriver::class, new NegativeStreakEscalationFakeDriverE603W);
        $user = User::create(['name' => 'Synthetic', 'email' => Str::random(12).'@example.test', 'password' => 'synthetic-test-password']);
        $org = Organization::create(['name' => 'Synthetic', 'owner_user_id' => $user->id]);
        [$member, $brand] = app(TenantContextRunner::class)->run(new TenantContext($org->id, actorId: $user->id), fn () => [
            Membership::create(['user_id' => $user->id, 'role' => 'owner']), Brand::create(['name' => 'Synthetic brand', 'timezone' => 'Europe/Moscow']),
        ]);
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id,
            'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $tenant = new TenantContext($org->id, $brand->id, $user->id, $member->id);
        app(TenantContextRunner::class)->run($tenant, fn () => app(YamlKnowledgeImporter::class)
            ->importAndPublish($tenant, base_path('tests/Feature/Seller/Knowledge/Fixtures/data')));
        $secret = Str::random(64);
        $tokenId = DB::table('personal_access_tokens')->insertGetId(['tokenable_type' => Brand::class, 'tokenable_id' => $brand->id,
            'organization_id' => $org->id, 'brand_id' => $brand->id, 'name' => 'test', 'token' => hash('sha256', $secret),
            'token_last_four' => substr($secret, -4), 'abilities' => json_encode(['leads', 'turn']), 'created_at' => now(), 'updated_at' => now()]);
        $bearer = $tokenId.'|'.$secret;
        $open = $this->withHeaders(['Authorization' => 'Bearer '.$bearer, 'Idempotency-Key' => 'open'])
            ->postJson('/api/v1/leads', ['fields' => ['name' => 'Тест'], 'action' => 'open'])
            ->assertCreated()->assertJsonPath('status', 'opened')->json();
        $conversationId = $open['conversation_id'];

        $first = $this->withHeaders(['Authorization' => 'Bearer '.$bearer, 'Idempotency-Key' => 'negative-1'])
            ->postJson('/api/v1/turn', ['conversation_id' => $conversationId, 'text' => 'Вы вообще разбираетесь в своём деле?'])
            ->assertOk()->json();
        self::assertNotSame('handoff', DB::table('conversations')->where('id', $conversationId)->value('stage'));

        $second = $this->withHeaders(['Authorization' => 'Bearer '.$bearer, 'Idempotency-Key' => 'negative-2'])
            ->postJson('/api/v1/turn', ['conversation_id' => $conversationId, 'text' => 'Это просто отвратительно, вы бесполезны.'])
            ->assertOk()->json();
        self::assertSame('Передаю диалог коллеге, он ответит сегодня.', $second['reply']);

        $conversation = DB::table('conversations')->where('id', $conversationId)->first();
        self::assertSame('handoff', $conversation->stage);
        self::assertSame('manager', $conversation->mode);
        self::assertTrue((bool) data_get(json_decode($conversation->state, true), 'flags.escalate', false));
        self::assertSame('negative_sentiment', data_get(json_decode($conversation->state, true), 'flags.escalate_reason'));
        $lastMessage = DB::table('messages')->where('conversation_id', $conversationId)->where('role', 'agent')->orderByDesc('created_at')->first();
        self::assertSame('handoff', json_decode($lastMessage->meta, true)['goal_kind']);
        self::assertNotSame($first['reply'] ?? null, null);
        Http::assertNothingSent();
    }
}

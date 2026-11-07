<?php

namespace Tests\Feature\Seller\Channels\Api;

use App\Models\Brand;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ApiTokenRuntimeE503CTest extends TenancyTestCase
{
    private function token(string $org, Brand $brand, array $abilities): array
    {
        $secret = Str::random(64);
        $id = DB::table('personal_access_tokens')->insertGetId(['tokenable_type' => Brand::class, 'tokenable_id' => $brand->id,
            'organization_id' => $org, 'brand_id' => $brand->id, 'name' => 'synthetic', 'token' => hash('sha256', $secret),
            'token_last_four' => substr($secret, -4), 'abilities' => json_encode($abilities, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);

        return [$id.'|'.$secret, $id];
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['runtime.ai_driver' => 'fake', 'cache.default' => 'array']);
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'SERVER_ADDR' => '127.0.0.1']);
        Http::preventStrayRequests();
    }

    public function test_open_turn_repeat_isolation_revocation_and_runtime_refusal(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id,
                'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        [$token, $tokenId] = $this->token($org->id, $brand, ['leads', 'turn']);
        [$foreign] = $this->token($org->id, $other, ['leads', 'turn']);
        [$withoutTurn] = $this->token($org->id, $brand, ['leads']);
        $headers = ['Authorization' => 'Bearer '.$token, 'Idempotency-Key' => 'open-1'];
        $body = ['fields' => ['name' => 'Тест'], 'action' => 'card_only'];
        $first = $this->withHeaders($headers)->postJson('/api/v1/leads', $body)->assertCreated()->assertJsonPath('status', 'created')->json();
        $this->withHeaders($headers)->postJson('/api/v1/leads', $body)->assertOk()->assertExactJson($first);
        $this->withHeaders($headers)->postJson('/api/v1/leads', ['fields' => ['name' => 'Другой'], 'action' => 'card_only'])->assertStatus(409);
        $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Idempotency-Key' => 'open-unavailable'])->postJson('/api/v1/leads', ['fields' => ['name' => 'Тест'], 'action' => 'open'])->assertStatus(503);
        $dialogUrl = '/seller/dialogs/'.$first['conversation_id'];
        $turnBody = ['conversation_id' => $first['conversation_id'], 'text' => 'Тестовый вопрос'];
        $this->withHeaders(['Authorization' => 'Bearer '.$withoutTurn, 'Idempotency-Key' => 'no-ability'])->postJson('/api/v1/turn', $turnBody)->assertForbidden();
        $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Idempotency-Key' => 'not-started'])->postJson('/api/v1/turn', $turnBody)->assertStatus(503);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post($dialogUrl.'/mode', ['action' => 'take'])->assertRedirect($dialogUrl)->assertSessionHasNoErrors();
        $this->post($dialogUrl.'/messages', ['text' => 'Здравствуйте!'])->assertRedirect($dialogUrl)->assertSessionHasNoErrors();
        $this->post($dialogUrl.'/mode', ['action' => 'return'])->assertRedirect($dialogUrl)->assertSessionHasNoErrors();
        $turn = ['conversation_id' => $first['conversation_id'], 'text' => 'Интересует продукция'];
        $turnHeaders = ['Authorization' => 'Bearer '.$token, 'Idempotency-Key' => 'turn-1'];
        $answer = $this->withHeaders($turnHeaders)->postJson('/api/v1/turn', $turn)->assertOk()->assertJsonPath('version', 3)->json();
        self::assertNotEmpty($answer['reply']);
        $this->withHeaders($turnHeaders)->postJson('/api/v1/turn', $turn)->assertOk()->assertExactJson($answer);
        $this->withHeaders($turnHeaders)->postJson('/api/v1/turn', [...$turn, 'text' => 'Иной вопрос'])->assertStatus(409);
        $this->withHeaders(['Authorization' => 'Bearer '.$foreign, 'Idempotency-Key' => 'foreign'])->postJson('/api/v1/turn', $turn)->assertNotFound();
        $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Idempotency-Key' => 'bad'])->postJson('/api/v1/turn', [...$turn, 'text' => ''])->assertUnprocessable();
        self::assertSame(3, DB::table('messages')->where('conversation_id', $first['conversation_id'])->count());
        config(['runtime.ai_driver' => 'openrouter']);
        $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Idempotency-Key' => 'disabled'])->postJson('/api/v1/turn', $turn)->assertStatus(503);
        config(['runtime.ai_driver' => 'fake']);
        DB::table('personal_access_tokens')->where('id', $tokenId)->delete();
        $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Idempotency-Key' => 'revoked'])->postJson('/api/v1/turn', $turn)->assertUnauthorized();
        $logs = json_encode(DB::table('seller_api_request_failures')->get(), JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString($token, $logs);
        self::assertStringNotContainsString('Интересует продукция', $logs);
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_manager_suggest_and_off_modes_follow_runtime_without_delivery(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id,
            'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        [$token] = $this->token($org->id, $brand, ['leads', 'turn']);
        $headers = ['Authorization' => 'Bearer '.$token, 'Idempotency-Key' => 'lead-modes'];
        $lead = $this->withHeaders($headers)->postJson('/api/v1/leads', ['fields' => ['name' => 'Тест']])->assertCreated()->json();
        $id = $lead['conversation_id'];
        DB::table('conversations')->where('id', $id)->update(['mode' => 'manager']);
        $data = ['conversation_id' => $id, 'text' => 'Первое сообщение'];
        $turnHeaders = ['Authorization' => 'Bearer '.$token, 'Idempotency-Key' => 'manager-mode'];
        $manager = $this->withHeaders($turnHeaders)->postJson('/api/v1/turn', $data)->assertOk()->assertJsonPath('status', 'suppressed')->json();
        $this->withHeaders($turnHeaders)->postJson('/api/v1/turn', $data)->assertOk()->assertExactJson($manager);
        self::assertSame(1, DB::table('messages')->where('conversation_id', $id)->count());
        DB::table('conversations')->where('id', $id)->update(['mode' => 'suggest']);
        $suggest = $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Idempotency-Key' => 'suggest-mode'])->postJson('/api/v1/turn', ['conversation_id' => $id, 'text' => 'Второе сообщение'])->assertOk()->assertJsonPath('status', 'suggested')->json();
        self::assertArrayNotHasKey('suggestion', $suggest);
        self::assertSame(1, DB::table('seller_turns')->where('conversation_id', $id)->where('status', 'suggested')->count());
        self::assertSame(2, DB::table('messages')->where('conversation_id', $id)->count());
        DB::table('conversations')->where('id', $id)->update(['mode' => 'bot']);
        DB::table('seller_schedule_preferences')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id,
            'payload' => json_encode(['mode' => 'off'], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
        $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Idempotency-Key' => 'off-mode'])->postJson('/api/v1/turn', ['conversation_id' => $id, 'text' => 'Третье сообщение'])->assertOk()->assertJsonPath('status', 'suppressed');
        self::assertSame(3, DB::table('messages')->where('conversation_id', $id)->count());
        DB::table('seller_schedule_preferences')->where('brand_id', $brand->id)->update(['payload' => json_encode(['mode' => 'suggest'], JSON_THROW_ON_ERROR)]);
        $scheduled = $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Idempotency-Key' => 'schedule-suggest'])->postJson('/api/v1/turn', ['conversation_id' => $id, 'text' => 'Четвёртое сообщение'])->assertOk()->assertJsonPath('status', 'suggested')->json();
        self::assertArrayNotHasKey('suggestion', $scheduled);
        $this->assertDatabaseHas('conversations', ['id' => $id, 'mode' => 'suggest']);
        self::assertSame(4, DB::table('messages')->where('conversation_id', $id)->count());
        DB::table('seller_schedule_preferences')->where('brand_id', $brand->id)->update(['payload' => json_encode(['mode' => 'autonomous'], JSON_THROW_ON_ERROR)]);
        DB::table('conversations')->where('id', $id)->update(['mode' => 'bot', 'stage' => 'handoff']);
        $handoff = ['conversation_id' => $id, 'text' => 'Уже передано'];
        $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Idempotency-Key' => 'bad-handoff'])->postJson('/api/v1/turn', $handoff)->assertStatus(503);
        self::assertSame(4, DB::table('messages')->where('conversation_id', $id)->count());
        DB::table('conversations')->where('id', $id)->update(['mode' => 'manager']);
        $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Idempotency-Key' => 'manager-handoff'])->postJson('/api/v1/turn', $handoff)->assertOk()->assertJsonPath('status', 'suppressed');
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/seller/channels/api')->assertOk()
            ->assertInertia(fn ($page) => $page->has('logs', 7));
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }
}

<?php

namespace Tests\Feature\Seller\Runtime;

use App\Models\Brand;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\SellerRuntime\Turns\ProtectedFollowupFixtures;
use Tests\Feature\Tenancy\TenancyTestCase;

final class OutOfHoursPhraseE606ETest extends TenancyTestCase
{
    use ProtectedFollowupFixtures;

    private const PHRASE = 'Передаю менеджеру, он напишет сегодня до конца дня.';

    private function enable(string $org, string $brand): void
    {
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org, 'brand_id' => $brand, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function setOff(string $org, string $brand, bool $off): void
    {
        DB::table('seller_schedule_preferences')->where('organization_id', $org)->where('brand_id', $brand)->delete();
        if ($off) {
            DB::table('seller_schedule_preferences')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org, 'brand_id' => $brand,
                'payload' => json_encode(['mode' => 'off'], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function test_widget_off_hours_suppresses_with_phrase_keeps_mode_and_resumes_in_hours(): void
    {
        Http::fake();
        [$owner, $org, $brand, $other, $membership] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            $this->enable($org->id, $item->id);
        }
        $this->seedProtected(new TenantContext($org->id, $brand->id, $owner->id, $membership->id));
        $this->seedProtected(new TenantContext($org->id, $other->id, $owner->id, $membership->id));
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/seller/channels/widget')->assertOk();
        $key = DB::table('widget_settings')->where('brand_id', $brand->id)->value('public_key');
        $this->post('/seller/channels/widget', ['enabled' => true, 'origins' => ['https://example.test'],
            'appearance' => ['title' => 'Чат', 'color' => '#225544', 'position' => 'right'], 'behavior' => ['invitation' => 'Здравствуйте!']])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/seller/channels/widget')->assertOk();
        $otherKey = DB::table('widget_settings')->where('brand_id', $other->id)->value('public_key');
        $this->post('/seller/channels/widget', ['enabled' => true, 'origins' => ['https://example.test'],
            'appearance' => ['title' => 'Чат', 'color' => '#225544', 'position' => 'right'], 'behavior' => ['invitation' => 'Здравствуйте!']])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->withHeader('Origin', 'https://example.test');
        $session = $this->postJson('/widget/session?key='.$key)->assertCreated()->json('token');
        $url = '/widget/message?key='.$key;
        $first = $this->postJson($url, ['token' => $session, 'text' => 'Здравствуйте', 'idempotency_key' => 'in-hours'])->assertCreated();
        self::assertArrayHasKey('reply', $first->json());
        $conversationId = DB::table('widget_sessions')->where('token_hash', hash('sha256', $session))->value('conversation_id');
        self::assertSame('bot', DB::table('conversations')->where('id', $conversationId)->value('mode'));

        $this->setOff($org->id, $brand->id, true);
        $offInput = ['token' => $session, 'text' => 'Есть кто?', 'idempotency_key' => 'off-hours'];
        $off = $this->postJson($url, $offInput)->assertCreated();
        self::assertArrayNotHasKey('reply', $off->json());
        self::assertSame('bot', DB::table('conversations')->where('id', $conversationId)->value('mode'));
        $agentMessagesAfterOff = DB::table('messages')->where('conversation_id', $conversationId)->where('role', 'agent')->orderBy('created_at')->get('text');
        self::assertSame(self::PHRASE, $agentMessagesAfterOff->last()->text);
        self::assertCount(2, $agentMessagesAfterOff, 'the in-hours bot reply plus the off-hours phrase');
        $this->postJson($url, $offInput)->assertCreated();
        self::assertCount(2, DB::table('messages')->where('conversation_id', $conversationId)->where('role', 'agent')->get(), 'retry with the same idempotency key must not duplicate the phrase');

        $this->setOff($org->id, $brand->id, false);
        $resume = $this->postJson($url, ['token' => $session, 'text' => 'Снова здесь', 'idempotency_key' => 'in-hours-2'])->assertCreated();
        self::assertArrayHasKey('reply', $resume->json());

        // Brand isolation: the other brand's widget is unaffected by $brand's off-hours schedule.
        $this->setOff($org->id, $brand->id, true);
        $otherSession = $this->withHeader('Origin', 'https://example.test')->postJson('/widget/session?key='.$otherKey)->assertCreated()->json('token');
        $otherReply = $this->postJson('/widget/message?key='.$otherKey, ['token' => $otherSession, 'text' => 'Здравствуйте', 'idempotency_key' => 'other-brand'])->assertCreated();
        self::assertArrayHasKey('reply', $otherReply->json());
        Http::assertNothingSent();
    }

    private function token(string $org, Brand $brand): string
    {
        $secret = Str::random(64);
        $id = DB::table('personal_access_tokens')->insertGetId(['tokenable_type' => Brand::class, 'tokenable_id' => $brand->id,
            'organization_id' => $org, 'brand_id' => $brand->id, 'name' => 'synthetic', 'token' => hash('sha256', $secret),
            'token_last_four' => substr($secret, -4), 'abilities' => json_encode(['leads', 'turn'], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);

        return $id.'|'.$secret;
    }

    public function test_api_token_off_hours_suppresses_with_phrase_and_keeps_bot_mode_for_resume(): void
    {
        Http::preventStrayRequests();
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'SERVER_ADDR' => '127.0.0.1']);
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            $this->enable($org->id, $item->id);
        }
        $token = $this->token($org->id, $brand);
        $this->setOff($org->id, $brand->id, true);
        $headers = ['Authorization' => 'Bearer '.$token, 'Idempotency-Key' => 'off-lead'];
        $lead = $this->withHeaders($headers)->postJson('/api/v1/leads', ['fields' => ['name' => 'Тест']])->assertCreated()->json();
        $conversationId = $lead['conversation_id'];
        self::assertSame('bot', DB::table('conversations')->where('id', $conversationId)->value('mode'));
        $turn = ['conversation_id' => $conversationId, 'text' => 'Есть кто?'];
        $turnHeaders = ['Authorization' => 'Bearer '.$token, 'Idempotency-Key' => 'off-turn'];
        $this->withHeaders($turnHeaders)->postJson('/api/v1/turn', $turn)->assertOk()->assertJsonPath('status', 'suppressed');
        self::assertSame('bot', DB::table('conversations')->where('id', $conversationId)->value('mode'));
        self::assertSame(self::PHRASE, DB::table('messages')->where('conversation_id', $conversationId)->where('role', 'agent')->value('text'));
        self::assertSame(1, DB::table('messages')->where('conversation_id', $conversationId)->where('role', 'agent')->count());
        $this->withHeaders($turnHeaders)->postJson('/api/v1/turn', $turn)->assertOk()->assertJsonPath('status', 'suppressed');
        self::assertSame(1, DB::table('messages')->where('conversation_id', $conversationId)->where('role', 'agent')->count(), 'retry with the same idempotency key must not duplicate the phrase');

        // Brand isolation: $other's lead/turn is unaffected by $brand's off-hours schedule (own module access check succeeds, no phrase).
        $otherToken = $this->token($org->id, $other);
        $otherHeaders = ['Authorization' => 'Bearer '.$otherToken, 'Idempotency-Key' => 'other-lead'];
        $otherLead = $this->withHeaders($otherHeaders)->postJson('/api/v1/leads', ['fields' => ['name' => 'Другой']])->assertCreated()->json();
        DB::table('conversations')->where('id', $otherLead['conversation_id'])->update(['mode' => 'manager']);
        $otherTurnHeaders = ['Authorization' => 'Bearer '.$otherToken, 'Idempotency-Key' => 'other-turn'];
        $this->withHeaders($otherTurnHeaders)->postJson('/api/v1/turn', ['conversation_id' => $otherLead['conversation_id'], 'text' => 'Вопрос'])
            ->assertOk()->assertJsonPath('status', 'suppressed');
        self::assertSame(0, DB::table('messages')->where('conversation_id', $otherLead['conversation_id'])->where('role', 'agent')->count());
        Http::assertNothingSent();
    }
}

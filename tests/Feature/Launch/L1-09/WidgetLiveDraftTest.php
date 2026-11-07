<?php

namespace Tests\Feature\Launch\L1_09;

use App\Seller\Live\LiveDraftJob;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\SellerRuntime\Turns\ProtectedFollowupFixtures;
use Tests\Feature\Tenancy\TenancyTestCase;

final class WidgetLiveDraftTest extends TenancyTestCase
{
    use ProtectedFollowupFixtures;

    private function openWidget(object $org, object $brand, object $owner): string
    {
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/seller/channels/widget')->assertOk();
        $key = DB::table('widget_settings')->where('brand_id', $brand->id)->value('public_key');
        $this->post('/seller/channels/widget', ['enabled' => true, 'origins' => ['https://example.test'], 'appearance' => ['title' => 'Чат', 'color' => '#225544', 'position' => 'right'], 'behavior' => ['invitation' => 'Здравствуйте!', 'collect_contact' => false]])->assertRedirect()->assertSessionHasNoErrors();

        return $key;
    }

    public function test_live_suggest_channel_queues_a_draft_and_never_answers_the_visitor(): void
    {
        Http::fake();
        Queue::fake();
        [$owner, $org, $brand] = $this->cabinet();
        $key = $this->openWidget($org, $brand, $owner);
        DB::table('channel_accounts')->where('brand_id', $brand->id)->where('type', 'widget')->update(['default_mode' => 'suggest']);
        config(['runtime.ai_driver' => 'openrouter', 'features.LV-01.defaults.enabled' => true]);
        $this->withHeader('Origin', 'https://example.test');
        $token = $this->postJson('/widget/session?key='.$key)->assertCreated()->json('token');
        $url = '/widget/message?key='.$key;
        $response = $this->postJson($url, ['token' => $token, 'text' => 'Сколько стоит доставка?', 'idempotency_key' => 'request-1'])->assertCreated();
        self::assertArrayNotHasKey('reply', $response->json());
        $conversationId = DB::table('widget_sessions')->where('token_hash', hash('sha256', $token))->value('conversation_id');
        $this->assertDatabaseHas('conversations', ['id' => $conversationId, 'mode' => 'suggest', 'version' => 1]);
        $this->assertDatabaseHas('seller_turns', ['conversation_id' => $conversationId, 'status' => 'suppressed']);
        $messageId = DB::table('messages')->where('conversation_id', $conversationId)->where('role', 'client')->value('id');
        Queue::assertPushed(LiveDraftJob::class, fn (LiveDraftJob $job) => $job->conversationId === $conversationId && $job->messageId === $messageId);
        Http::assertNothingSent();
    }

    public function test_live_manager_channel_suppresses_without_an_ai_answer(): void
    {
        Http::fake();
        Queue::fake();
        [$owner, $org, $brand] = $this->cabinet();
        $key = $this->openWidget($org, $brand, $owner);
        config(['runtime.ai_driver' => 'openrouter', 'features.LV-01.defaults.enabled' => true]);
        $this->withHeader('Origin', 'https://example.test');
        $token = $this->postJson('/widget/session?key='.$key)->assertCreated()->json('token');
        $url = '/widget/message?key='.$key;
        $response = $this->postJson($url, ['token' => $token, 'text' => 'Здравствуйте', 'idempotency_key' => 'request-1'])->assertCreated();
        self::assertArrayNotHasKey('reply', $response->json());
        $conversationId = DB::table('widget_sessions')->where('token_hash', hash('sha256', $token))->value('conversation_id');
        $this->assertDatabaseHas('conversations', ['id' => $conversationId, 'mode' => 'manager', 'version' => 1]);
        $this->assertDatabaseHas('seller_turns', ['conversation_id' => $conversationId, 'status' => 'suppressed']);
        // QueueLiveDraft queues for every client message; LiveDraftJob itself is a no-op outside mode=suggest.
        Queue::assertPushed(LiveDraftJob::class);
        (new LiveDraftJob($org->id, $brand->id, $conversationId, DB::table('messages')->where('conversation_id', $conversationId)->value('id')))->handle();
        $this->assertDatabaseHas('conversations', ['id' => $conversationId, 'mode' => 'manager', 'version' => 1]);
        Http::assertNothingSent();
    }

    public function test_fake_channel_keeps_bot_reply_unchanged_when_other_brands_run_live(): void
    {
        Http::fake();
        Queue::fake();
        [$owner, $org, $brand, , $membership] = $this->cabinet();
        $this->seedProtected(new TenantContext($org->id, $brand->id, $owner->id, $membership->id));
        $key = $this->openWidget($org, $brand, $owner);
        config(['runtime.ai_driver' => 'fake']);
        $this->withHeader('Origin', 'https://example.test');
        $token = $this->postJson('/widget/session?key='.$key)->assertCreated()->json('token');
        $url = '/widget/message?key='.$key;
        $response = $this->postJson($url, ['token' => $token, 'text' => 'Здравствуйте', 'idempotency_key' => 'request-1'])->assertCreated();
        self::assertSame('Спасибо за сообщение!', $response->json('reply'));
        $conversationId = DB::table('widget_sessions')->where('token_hash', hash('sha256', $token))->value('conversation_id');
        $this->assertDatabaseHas('conversations', ['id' => $conversationId, 'mode' => 'bot', 'version' => 1]);
        Queue::assertNotPushed(LiveDraftJob::class);
    }
}

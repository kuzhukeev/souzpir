<?php

namespace Tests\Feature\Launch\L1_09;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class WidgetMessagesTest extends TenancyTestCase
{
    private function openWidget(object $org, object $brand, object $owner): string
    {
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/seller/channels/widget')->assertOk();
        $key = DB::table('widget_settings')->where('brand_id', $brand->id)->value('public_key');
        $this->post('/seller/channels/widget', ['enabled' => true, 'origins' => ['https://example.test'], 'appearance' => ['title' => 'Чат', 'color' => '#225544', 'position' => 'right'], 'behavior' => ['invitation' => 'Здравствуйте!', 'collect_contact' => false]])->assertRedirect()->assertSessionHasNoErrors();

        return $key;
    }

    private function startConversation(string $key, object $org, object $brand): string
    {
        Http::fake();
        Queue::fake();
        DB::table('channel_accounts')->where('brand_id', $brand->id)->where('type', 'widget')->update(['default_mode' => 'manager']);
        config(['runtime.ai_driver' => 'openrouter']);
        $this->withHeader('Origin', 'https://example.test');
        $token = $this->postJson('/widget/session?key='.$key)->assertCreated()->json('token');
        $this->postJson('/widget/message?key='.$key, ['token' => $token, 'text' => 'Здравствуйте', 'idempotency_key' => 'request-1'])->assertCreated();
        $conversationId = DB::table('widget_sessions')->where('token_hash', hash('sha256', $token))->value('conversation_id');
        $this->assertDatabaseHas('conversations', ['id' => $conversationId]);

        return $token;
    }

    public function test_manager_reply_becomes_visible_through_polling_and_cursor_advances(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $key = $this->openWidget($org, $brand, $owner);
        $token = $this->startConversation($key, $org, $brand);
        $conversationId = DB::table('widget_sessions')->where('token_hash', hash('sha256', $token))->value('conversation_id');

        $this->withHeader('Origin', 'https://example.test')->getJson('/widget/messages?key='.$key.'&token='.$token)
            ->assertOk()->assertExactJson(['messages' => []]);

        $managerMessageId = (string) Str::ulid();
        DB::table('messages')->insert(['id' => $managerMessageId, 'organization_id' => $org->id, 'brand_id' => $brand->id,
            'conversation_id' => $conversationId, 'role' => 'manager', 'text' => 'Добрый день! Чем помочь?', 'meta' => '{}', 'created_at' => now(), 'updated_at' => now()]);

        $response = $this->withHeader('Origin', 'https://example.test')->getJson('/widget/messages?key='.$key.'&token='.$token)->assertOk();
        self::assertSame([['id' => $managerMessageId, 'text' => 'Добрый день! Чем помочь?']], $response->json('messages'));

        $this->withHeader('Origin', 'https://example.test')->getJson('/widget/messages?key='.$key.'&token='.$token.'&after='.$managerMessageId)
            ->assertOk()->assertExactJson(['messages' => []]);
    }

    public function test_foreign_session_token_returns_404(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $key = $this->openWidget($org, $brand, $owner);
        $this->startConversation($key, $org, $brand);

        [$otherOwner, $otherOrg, $otherBrand] = $this->cabinet();
        $otherKey = $this->openWidget($otherOrg, $otherBrand, $otherOwner);
        $otherToken = $this->withHeader('Origin', 'https://example.test')->postJson('/widget/session?key='.$otherKey)->assertCreated()->json('token');

        $this->withHeader('Origin', 'https://example.test')->getJson('/widget/messages?key='.$key.'&token='.$otherToken)->assertNotFound();
    }
}

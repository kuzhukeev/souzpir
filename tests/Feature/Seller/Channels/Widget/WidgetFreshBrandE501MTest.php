<?php

namespace Tests\Feature\Seller\Channels\Widget;

use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\SellerRuntime\Turns\ProtectedFollowupFixtures;
use Tests\Feature\Tenancy\TenancyTestCase;

final class WidgetFreshBrandE501MTest extends TenancyTestCase
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

    public function test_first_message_on_fresh_brand_without_knowledge_hands_off_to_manager_instead_of_503(): void
    {
        Http::fake();
        [$owner, $org, $brand] = $this->cabinet();
        $key = $this->openWidget($org, $brand, $owner);
        $this->withHeader('Origin', 'https://example.test');
        $token = $this->postJson('/widget/session?key='.$key)->assertCreated()->json('token');
        $url = '/widget/message?key='.$key;
        $input = ['token' => $token, 'text' => 'Здравствуйте', 'idempotency_key' => 'request-1'];
        $response = $this->postJson($url, $input)->assertCreated();
        self::assertArrayNotHasKey('reply', $response->json());
        $conversationId = DB::table('widget_sessions')->where('token_hash', hash('sha256', $token))->value('conversation_id');
        $this->assertDatabaseHas('conversations', ['id' => $conversationId, 'mode' => 'manager', 'version' => 1]);
        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseHas('seller_turns', ['conversation_id' => $conversationId, 'status' => 'failed']);
        $repeat = $this->postJson($url, $input)->assertCreated();
        self::assertArrayNotHasKey('reply', $repeat->json());
        $this->assertDatabaseCount('messages', 1);
    }

    public function test_first_message_on_brand_with_knowledge_still_gets_a_bot_reply(): void
    {
        Http::fake();
        [$owner, $org, $brand, , $membership] = $this->cabinet();
        $this->seedProtected(new TenantContext($org->id, $brand->id, $owner->id, $membership->id));
        $key = $this->openWidget($org, $brand, $owner);
        $this->withHeader('Origin', 'https://example.test');
        $token = $this->postJson('/widget/session?key='.$key)->assertCreated()->json('token');
        $url = '/widget/message?key='.$key;
        $input = ['token' => $token, 'text' => 'Здравствуйте', 'idempotency_key' => 'request-1'];
        $response = $this->postJson($url, $input)->assertCreated();
        self::assertSame('Спасибо за сообщение!', $response->json('reply'));
        $conversationId = DB::table('widget_sessions')->where('token_hash', hash('sha256', $token))->value('conversation_id');
        $this->assertDatabaseHas('conversations', ['id' => $conversationId, 'mode' => 'bot', 'version' => 1]);
    }
}

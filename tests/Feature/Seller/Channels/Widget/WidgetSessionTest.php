<?php

namespace Tests\Feature\Seller\Channels\Widget;

use App\Seller\Settings\BrandSellerSettings;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\SellerRuntime\Turns\ProtectedFollowupFixtures;
use Tests\Feature\Tenancy\TenancyTestCase;

final class WidgetSessionTest extends TenancyTestCase
{
    use ProtectedFollowupFixtures;

    public function test_session_is_bound_to_widget_origin_brand_and_consent(): void
    {
        Http::fake();
        [$owner, $org, $brand, $other, $membership] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->seedProtected(new TenantContext($org->id, $brand->id, $owner->id, $membership->id));
        $this->within($org, $brand, fn () => BrandSellerSettings::create(['greetings' => ['widget_catalog' => 'Здравствуйте, {имя}! Страница: {страница}.']]));
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/seller/channels/widget')->assertOk();
        $key = DB::table('widget_settings')->where('brand_id', $brand->id)->value('public_key');
        $this->post('/seller/channels/widget', ['enabled' => true, 'origins' => ['https://example.test'], 'appearance' => ['title' => 'Чат', 'color' => '#225544', 'position' => 'right'], 'behavior' => ['invitation' => 'Здравствуйте!', 'collect_contact' => true, 'consent_url' => 'https://example.test/privacy']])->assertRedirect()->assertSessionHasNoErrors();
        $this->withHeader('Origin', 'https://wrong.test')->postJson('/widget/session?key='.$key)->assertNotFound();
        $this->withHeader('Origin', 'https://example.test');
        $session = $this->postJson('/widget/session?key='.$key)->assertCreated()->assertHeader('Access-Control-Allow-Origin', 'https://example.test')->json('token');
        self::assertNotSame($session, DB::table('widget_sessions')->value('token_hash'));
        $url = '/widget/message?key='.$key;
        $input = ['token' => $session, 'text' => 'Здравствуйте', 'idempotency_key' => 'request-1', 'page' => 'catalog'];
        config(['runtime.ai_driver' => 'fake']);
        $first = $this->postJson($url, $input)->assertCreated();
        $first->assertJson(['reply' => "Здравствуйте, гость! Страница: Каталог.\n\nСпасибо за сообщение!"]);
        $repeat = $this->postJson($url, $input)->assertCreated();
        self::assertSame($first->json('reply'), $repeat->json('reply'));
        $conversationId = DB::table('widget_sessions')->where('token_hash', hash('sha256', $session))->value('conversation_id');
        $this->assertDatabaseHas('conversations', ['id' => $conversationId, 'mode' => 'bot', 'version' => 1]);
        $this->assertDatabaseCount('messages', 2);
        $this->postJson($url, [...$input, 'token' => str_repeat('x', 64)])->assertNotFound();
        $this->postJson($url, [...$input, 'text' => ''])->assertUnprocessable();
        $this->postJson('/widget/contact?key='.$key, ['session_token' => $session, 'consent' => true, 'name' => 'Посетитель виджета', 'email' => 'client@example.test'])->assertCreated();
        $stored = DB::table('widget_sessions')->where('token_hash', hash('sha256', $session))->first();
        self::assertNotNull($stored->contact_request_id);
        $this->assertDatabaseHas('contacts', ['id' => $stored->contact_id, 'brand_id' => $brand->id, 'name' => 'Посетитель виджета', 'email' => 'client@example.test']);
        $this->assertDatabaseCount('contacts', 1);
        $this->assertDatabaseHas('widget_contact_requests', ['id' => $stored->contact_request_id, 'brand_id' => $brand->id, 'email' => 'client@example.test']);
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/seller/channels/widget')->assertOk();
        $otherKey = DB::table('widget_settings')->where('brand_id', $other->id)->value('public_key');
        $this->post('/seller/channels/widget', ['enabled' => true, 'origins' => ['https://example.test'], 'appearance' => ['title' => 'Чат', 'color' => '#225544', 'position' => 'right'], 'behavior' => ['invitation' => 'Здравствуйте!', 'collect_contact' => true, 'consent_url' => 'https://example.test/privacy']])->assertRedirect()->assertSessionHasNoErrors();
        $this->postJson('/widget/message?key='.$otherKey, $input)->assertNotFound();
        // Live mode (SP-L1-09): the widget no longer answers 503; the turn is suppressed to a manager without an AI reply.
        config(['runtime.ai_driver' => 'live']);
        $liveSession = $this->postJson('/widget/session?key='.$key)->assertCreated()->json('token');
        $live = $this->postJson($url, [...$input, 'token' => $liveSession, 'idempotency_key' => 'request-live'])->assertCreated();
        self::assertArrayNotHasKey('reply', $live->json());
        Http::assertNothingSent();
    }
}

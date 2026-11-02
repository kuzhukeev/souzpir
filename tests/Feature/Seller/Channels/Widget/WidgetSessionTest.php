<?php

namespace Tests\Feature\Seller\Channels\Widget;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class WidgetSessionTest extends TenancyTestCase
{
    public function test_session_is_bound_to_widget_origin_brand_and_consent(): void
    {
        Http::fake();
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/seller/channels/widget')->assertOk();
        $key = DB::table('widget_settings')->where('brand_id', $brand->id)->value('public_key');
        $this->post('/seller/channels/widget', ['enabled' => true, 'origins' => ['https://example.test'], 'appearance' => ['title' => 'Чат', 'color' => '#225544', 'position' => 'right'], 'behavior' => ['invitation' => 'Здравствуйте!', 'collect_contact' => true, 'consent_url' => 'https://example.test/privacy']])->assertRedirect()->assertSessionHasNoErrors();
        $this->withHeader('Origin', 'https://wrong.test')->postJson('/widget/session?key='.$key)->assertNotFound();
        $this->withHeader('Origin', 'https://example.test');
        $session = $this->postJson('/widget/session?key='.$key)->assertCreated()->assertHeader('Access-Control-Allow-Origin', 'https://example.test')->json('token');
        self::assertNotSame($session, DB::table('widget_sessions')->value('token_hash'));
        $url = '/widget/message?key='.$key;
        $input = ['token' => $session, 'text' => 'Здравствуйте', 'idempotency_key' => 'request-1'];
        $this->postJson($url, $input)->assertStatus(503);
        $this->postJson($url, $input)->assertStatus(503);
        $this->postJson($url, [...$input, 'token' => str_repeat('x', 64)])->assertNotFound();
        $this->postJson($url, [...$input, 'text' => ''])->assertUnprocessable();
        $this->postJson('/widget/contact?key='.$key, ['session_token' => $session, 'consent' => true, 'email' => 'client@example.test'])->assertCreated();
        $stored = DB::table('widget_sessions')->where('token_hash', hash('sha256', $session))->first();
        self::assertNotNull($stored->contact_request_id);
        $this->assertDatabaseHas('widget_contact_requests', ['id' => $stored->contact_request_id, 'brand_id' => $brand->id, 'email' => 'client@example.test']);
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/seller/channels/widget')->assertOk();
        $otherKey = DB::table('widget_settings')->where('brand_id', $other->id)->value('public_key');
        $this->post('/seller/channels/widget', ['enabled' => true, 'origins' => ['https://example.test'], 'appearance' => ['title' => 'Чат', 'color' => '#225544', 'position' => 'right'], 'behavior' => ['invitation' => 'Здравствуйте!', 'collect_contact' => true, 'consent_url' => 'https://example.test/privacy']])->assertRedirect()->assertSessionHasNoErrors();
        $this->postJson('/widget/message?key='.$otherKey, $input)->assertNotFound();
        Http::assertNothingSent();
    }
}

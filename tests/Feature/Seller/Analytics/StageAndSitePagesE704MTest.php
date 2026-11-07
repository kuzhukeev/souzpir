<?php

namespace Tests\Feature\Seller\Analytics;

use App\Models\Brand;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\SellerRuntime\Turns\ProtectedFollowupFixtures;
use Tests\Feature\Tenancy\TenancyTestCase;

final class StageAndSitePagesE704MTest extends TenancyTestCase
{
    use ProtectedFollowupFixtures;

    public function test_manager_close_records_a_stage_transition(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $dialog = (string) Str::ulid();
        DB::table('conversations')->insert(['id' => $dialog, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'mode' => 'bot', 'state' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->post('/seller/dialogs/'.$dialog.'/mode', ['action' => 'close'])->assertRedirect();
        $this->assertDatabaseHas('seller_stage_events', ['organization_id' => $org->id, 'brand_id' => $brand->id, 'conversation_id' => $dialog, 'from_stage' => 'open', 'to_stage' => 'done']);
    }

    public function test_widget_turn_records_a_stage_transition_and_feeds_site_pages(): void
    {
        Http::fake();
        [$owner, $org, $brand, , $membership] = $this->cabinet();
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->seedProtected(new TenantContext($org->id, $brand->id, $owner->id, $membership->id));
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/seller/channels/widget')->assertOk();
        $key = DB::table('widget_settings')->where('brand_id', $brand->id)->value('public_key');
        $this->post('/seller/channels/widget', ['enabled' => true, 'origins' => ['https://example.test'], 'appearance' => ['title' => 'Чат', 'color' => '#225544', 'position' => 'right'], 'behavior' => ['invitation' => 'Здравствуйте!']])->assertRedirect()->assertSessionHasNoErrors();
        $this->withHeader('Origin', 'https://example.test');
        $token = $this->postJson('/widget/session?key='.$key, ['page' => '/catalog', 'utm' => 'utm_source=search'])->assertCreated()->json('token');
        $this->postJson('/widget/message?key='.$key, ['token' => $token, 'text' => 'Здравствуйте', 'idempotency_key' => 'request-1', 'page' => 'catalog'])->assertCreated();
        $conversationId = DB::table('widget_sessions')->where('token_hash', hash('sha256', $token))->value('conversation_id');
        $this->assertDatabaseHas('widget_sessions', ['token_hash' => hash('sha256', $token), 'page' => '/catalog', 'utm' => 'utm_source=search']);

        $response = $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/seller/analytics?from='.now()->format('Y-m-d').'&to='.now()->format('Y-m-d'))->assertOk();
        $pages = collect($response->inertiaProps('analytics.sitePages'));
        $catalog = $pages->firstWhere('page', '/catalog');
        self::assertNotNull($catalog);
        self::assertSame(1, $catalog['opens']);
        self::assertSame(1, $catalog['dialogs']);
        self::assertSame('utm_source=search', $catalog['utm']);
        Http::assertNothingSent();
    }

    public function test_api_opening_records_a_stage_transition(): void
    {
        Http::preventStrayRequests();
        config(['runtime.ai_driver' => 'fake']);
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'SERVER_ADDR' => '127.0.0.1']);
        [$owner, $org, $brand, , $membership] = $this->cabinet();
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->seedProtected(new TenantContext($org->id, $brand->id, $owner->id, $membership->id));
        $secret = Str::random(64);
        $tokenId = DB::table('personal_access_tokens')->insertGetId(['tokenable_type' => Brand::class, 'tokenable_id' => $brand->id,
            'organization_id' => $org->id, 'brand_id' => $brand->id, 'name' => 'test', 'token' => hash('sha256', $secret),
            'token_last_four' => substr($secret, -4), 'abilities' => json_encode(['leads']), 'created_at' => now(), 'updated_at' => now()]);
        $headers = ['Authorization' => 'Bearer '.$tokenId.'|'.$secret, 'Idempotency-Key' => 'open-1'];
        $response = $this->withHeaders($headers)->postJson('/api/v1/leads', ['fields' => ['name' => 'Тест'], 'action' => 'open'])
            ->assertCreated()->assertJsonPath('status', 'opened')->json();
        $stage = DB::table('conversations')->where('id', $response['conversation_id'])->value('stage');
        self::assertNotSame('open', $stage);
        $this->assertDatabaseHas('seller_stage_events', ['organization_id' => $org->id, 'brand_id' => $brand->id, 'conversation_id' => $response['conversation_id'], 'from_stage' => 'open', 'to_stage' => $stage]);
    }
}

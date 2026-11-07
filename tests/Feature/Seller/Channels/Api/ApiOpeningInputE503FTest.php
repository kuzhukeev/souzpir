<?php

namespace Tests\Feature\Seller\Channels\Api;

use App\Ai\Protected\ApiOpeningInput;
use App\Models\Brand;
use App\Seller\Knowledge\Import\YamlKnowledgeImporter;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use App\Tenancy\TokenApi\BrandAccessToken;
use Database\Seeders\Ai\PromptSetsSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ApiOpeningInputE503FTest extends TenancyTestCase
{
    public function test_default_action_is_brand_scoped_and_requires_settings_permission(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id,
                'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->post('/seller/channels/api/action', ['action' => 'open'])->assertRedirect();
        $this->get('/seller/channels/api')->assertOk()->assertInertia(fn ($page) => $page->where('action', 'open'));
        $this->actingAs($owner)->withSession(['brand_id' => $other->id])->get('/seller/channels/api')->assertOk()
            ->assertInertia(fn ($page) => $page->where('action', 'card_only'));
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->post('/seller/channels/api/action', ['action' => 'open'])->assertForbidden();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->post('/seller/channels/api/action', ['action' => 'unknown'])->assertSessionHasErrors('action');
    }

    public function test_opening_uses_persisted_state_and_no_fabricated_client_message(): void
    {
        Http::preventStrayRequests();
        config(['runtime.ai_driver' => 'fake']);
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'SERVER_ADDR' => '127.0.0.1']);
        [$owner, $org, $brand, $other] = $this->cabinet();
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id,
            'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        app(PromptSetsSeeder::class)->run();
        $member = DB::table('memberships')->where('organization_id', $org->id)->where('user_id', $owner->id)->value('id');
        $sessionTenant = new TenantContext($org->id, $brand->id, $owner->id, $member);
        app(TenantContextRunner::class)->run($sessionTenant, fn () => app(YamlKnowledgeImporter::class)
            ->importAndPublish($sessionTenant, base_path('tests/Feature/Seller/Knowledge/Fixtures/data')));
        $secret = Str::random(64);
        $tokenId = DB::table('personal_access_tokens')->insertGetId(['tokenable_type' => Brand::class, 'tokenable_id' => $brand->id,
            'organization_id' => $org->id, 'brand_id' => $brand->id, 'name' => 'test', 'token' => hash('sha256', $secret),
            'token_last_four' => substr($secret, -4), 'abilities' => json_encode(['leads']), 'created_at' => now(), 'updated_at' => now()]);
        $response = $this->withHeaders(['Authorization' => 'Bearer '.$tokenId.'|'.$secret, 'Idempotency-Key' => 'opening'])
            ->postJson('/api/v1/leads', ['fields' => ['name' => 'Тест']])->assertCreated();
        $lead = DB::table('seller_api_lead_requests')->where('conversation_id', $response->json('conversation_id'))->first();
        DB::table('seller_api_lead_requests')->where('id', $lead->id)->update(['status' => 'opening']);
        $token = BrandAccessToken::query()->findOrFail($tokenId);
        $http = Request::create('/api/v1/leads', 'POST');
        $http->setUserResolver(fn () => $brand);
        $http->attributes->set('tenant.token', $token);
        $tenant = new TenantContext($org->id, $brand->id, source: 'token');
        app(TenantContextRunner::class)->run($tenant, function () use ($http, $tenant, $lead): void {
            [$request, $context, $state, $goal] = app(ApiOpeningInput::class)->buildLocked($http, $tenant, $lead->id);
            self::assertSame('intro_and_ask', $goal->kind);
            self::assertSame('Тест', $state->slot('name'));
            self::assertSame($lead->conversation_id, $context->objectId);
            self::assertSame(['system'], array_column($request->messages, 'role'));
            self::assertSame([], $request->tools);
        });
        self::assertSame(0, DB::table('messages')->where('conversation_id', $lead->conversation_id)->count());
        Http::assertNothingSent();
    }
}

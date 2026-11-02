<?php

namespace Tests\Feature\Seller\Channels\Api;

use App\Models\Brand;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class LeadApiTest extends TenancyTestCase
{
    private function enable(string $org, string $brand): void
    {
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org, 'brand_id' => $brand, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function token(string $org, Brand $brand, array $abilities): string
    {
        $secret = Str::random(64);
        $id = DB::table('personal_access_tokens')->insertGetId(['tokenable_type' => Brand::class, 'tokenable_id' => $brand->id,
            'organization_id' => $org, 'brand_id' => $brand->id, 'name' => 'synthetic', 'token' => hash('sha256', $secret),
            'token_last_four' => substr($secret, -4), 'abilities' => json_encode($abilities, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);

        return $id.'|'.$secret;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'SERVER_ADDR' => '127.0.0.1']);
        Http::preventStrayRequests();
    }

    public function test_lead_idempotency_mapping_and_brand_isolation(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $this->enable($org->id, $other->id);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/channels/api', ['mapping' => ['client_name' => 'name', 'town' => 'city']])->assertRedirect();
        $this->get('/seller/channels/api')->assertOk()->assertInertia(fn ($page) => $page->where('mapping.client_name', 'name'));
        $token = $this->token($org->id, $brand, ['leads']);
        $headers = ['Authorization' => 'Bearer '.$token, 'Idempotency-Key' => 'demo-key'];
        $body = ['fields' => ['client_name' => 'Анна', 'town' => 'Тверь'], 'action' => 'card_only'];
        $first = $this->withHeaders($headers)->postJson('/api/v1/leads', $body)->assertCreated();
        $contact = $first->json('contact_id');
        $conversation = $first->json('conversation_id');
        $this->withHeaders($headers)->postJson('/api/v1/leads', $body)->assertOk()->assertJsonPath('contact_id', $contact)->assertJsonPath('conversation_id', $conversation);
        $this->withHeaders($headers)->postJson('/api/v1/leads', ['fields' => ['client_name' => 'Другое']])->assertStatus(409);
        $this->assertDatabaseCount('contacts', 1);
        $this->assertDatabaseCount('conversations', 1);
        $this->assertDatabaseHas('contacts', ['id' => $contact, 'brand_id' => $brand->id, 'name' => 'Анна']);
        $state = json_decode(DB::table('conversations')->where('id', $conversation)->value('state'), true);
        self::assertSame('Тверь', $state['slots']['city']);
        $this->withHeaders([...$headers, 'X-Brand-Id' => $other->id])->postJson('/api/v1/leads', $body)->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_bearer_only_permissions_and_input_errors(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->postJson('/api/v1/leads', ['fields' => ['name' => 'А']])->assertUnauthorized();
        $wrong = $this->token($org->id, $brand, ['read']);
        $this->withHeaders(['Authorization' => 'Bearer '.$wrong, 'Idempotency-Key' => 'k'])->postJson('/api/v1/leads', ['fields' => ['name' => 'А']])->assertForbidden();
        $token = $this->token($org->id, $brand, ['leads', 'turn']);
        $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Idempotency-Key' => 'bad key'])->postJson('/api/v1/leads', ['fields' => ['name' => 'А']])->assertUnprocessable();
        $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Idempotency-Key' => 'key2'])->postJson('/api/v1/leads', ['fields' => ['email' => 'not-mail']])->assertUnprocessable();
        $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Idempotency-Key' => 'key3'])->postJson('/api/v1/leads', ['fields' => ['name' => 'А'], 'action' => 'open'])->assertStatus(503);
        $this->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/v1/turn', [])->assertStatus(503);
        $this->assertDatabaseCount('contacts', 0);
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/channels/api')->assertForbidden();
        $this->post('/seller/channels/api', ['mapping' => ['name' => 'name']])->assertForbidden();
    }
}

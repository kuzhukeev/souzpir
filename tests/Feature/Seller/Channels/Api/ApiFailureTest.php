<?php

namespace Tests\Feature\Seller\Channels\Api;

use App\Models\Brand;
use App\Shell\Audit\Contracts\AuditWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ApiFailureTest extends TenancyTestCase
{
    private function enable(string $org, string $brand): void
    {
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org, 'brand_id' => $brand, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function token(string $org, Brand $brand, array $abilities): string
    {
        $secret = Str::random(64);
        $id = DB::table('personal_access_tokens')->insertGetId(['tokenable_type' => Brand::class, 'tokenable_id' => $brand->id, 'organization_id' => $org, 'brand_id' => $brand->id,
            'name' => 'test', 'token' => hash('sha256', $secret), 'token_last_four' => substr($secret, -4), 'abilities' => json_encode($abilities), 'created_at' => now(), 'updated_at' => now()]);

        return $id.'|'.$secret;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'SERVER_ADDR' => '127.0.0.1']);
        Http::preventStrayRequests();
    }

    public function test_failures_survive_rollback_and_are_redacted_and_isolated(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $this->enable($org->id, $other->id);
        $limited = $this->token($org->id, $brand, ['read']);
        $token = $this->token($org->id, $brand, ['leads']);
        $this->withHeaders(['Authorization' => 'Bearer '.$limited, 'Idempotency-Key' => 'one'])->postJson('/api/v1/leads', ['fields' => ['name' => 'Секрет клиента']])->assertForbidden();
        $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Idempotency-Key' => 'bad key'])->postJson('/api/v1/leads', ['fields' => ['name' => 'Секрет клиента']])->assertUnprocessable();
        $mock = \Mockery::mock(AuditWriter::class);
        $mock->shouldReceive('record')->andThrow(new \RuntimeException('test failure'));
        app()->instance(AuditWriter::class, $mock);
        $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Idempotency-Key' => 'valid'])->postJson('/api/v1/leads', ['fields' => ['name' => 'Секрет клиента']])->assertStatus(500);
        $this->assertDatabaseCount('contacts', 0);
        $this->assertDatabaseCount('seller_api_request_failures', 3);
        $this->assertDatabaseHas('seller_api_request_failures', ['brand_id' => $brand->id, 'http_status' => 403]);
        $this->assertDatabaseHas('seller_api_request_failures', ['brand_id' => $brand->id, 'http_status' => 422]);
        $this->assertDatabaseHas('seller_api_request_failures', ['brand_id' => $brand->id, 'http_status' => 500]);
        $stored = json_encode(DB::table('seller_api_request_failures')->get(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString($token, $stored);
        $this->assertStringNotContainsString('Секрет клиента', $stored);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/channels/api/failures')->assertOk()->assertSee('Ошибка 403')->assertSee('Ошибка 422')->assertSee('Ошибка 500');
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/seller/channels/api/failures')->assertOk()->assertDontSee('Ошибка 500')->assertSee('Отклонённых запросов пока нет.');
    }

    public function test_anonymous_authorization_failure_is_unassigned_and_viewer_cannot_read(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $this->withHeader('Authorization', 'Bearer fake-secret')->postJson('/api/v1/leads', ['fields' => ['name' => 'Secret']])->assertUnauthorized();
        $this->assertDatabaseHas('seller_api_request_failures', ['brand_id' => null, 'http_status' => 401]);
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/channels/api/failures')->assertForbidden();
    }
}

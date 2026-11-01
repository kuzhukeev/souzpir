<?php

namespace Tests\Feature\Shell\Integrations\Keys;

use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Integrations\Keys\KeysController;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\Feature\Tenancy\TenancyTestCase;
use Tests\Support\MfaTestDatabase;

final class ApiKeysTest extends TenancyTestCase
{
    protected function beforeRefreshingDatabase(): void
    {
        MfaTestDatabase::assert();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'SERVER_ADDR' => '127.0.0.1']);
        config(['session.encrypt' => false]);
    }

    public function test_owner_can_create_list_and_revoke_without_persisting_plain_token(): void
    {
        [$owner, $organization, $brand] = $this->cabinet();
        $response = $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->post('/integrations/api/keys', [
            'name' => 'Наш ключ', 'abilities' => ['read', 'turn'],
        ]);
        $response->assertRedirect('/integrations/api')->assertSessionHasNoErrors()->assertSessionHas('integration_api_key');
        $sealed = session('integration_api_key');
        self::assertIsString($sealed);
        $plain = json_decode(Crypt::decryptString($sealed), true, flags: JSON_THROW_ON_ERROR)['value'];
        self::assertStringNotContainsString($plain, serialize(session()->all()));
        [$id, $secret] = explode('|', $plain, 2);
        $row = DB::table('personal_access_tokens')->where('id', $id)->first();
        self::assertSame(hash('sha256', $secret), $row->token);
        self::assertNotSame($secret, $row->token);
        self::assertSame($brand->id, $row->brand_id);
        self::assertSame($organization->id, $row->organization_id);
        self::assertSame(substr($secret, -4), $row->token_last_four);
        $ctx = new TenantContext($organization->id, $brand->id, source: 'job');
        $items = app(TenantContextRunner::class)->run($ctx, fn () => KeysController::listing($ctx));
        self::assertCount(1, $items);
        self::assertSame(['read', 'turn'], $items[0]['abilities']);
        self::assertStringNotContainsString($secret, json_encode($items, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString($secret, json_encode(DB::table('audit_logs')->get(), JSON_THROW_ON_ERROR));
        $this->assertDatabaseHas('audit_logs', ['organization_id' => $organization->id, 'brand_id' => $brand->id, 'action' => 'integration.api_key.created', 'subject_id' => $id]);
        $this->delete('/integrations/api/keys/'.$id)->assertRedirect('/integrations/api');
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $id]);
        $this->assertDatabaseHas('audit_logs', ['brand_id' => $brand->id, 'action' => 'integration.api_key.revoked', 'subject_id' => $id]);
        $this->delete('/integrations/api/keys/'.$id)->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_one_time_secret_is_not_in_inertia_and_post_returns_it_once_without_cache(): void
    {
        [$owner, , $brand] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->post('/integrations/api/keys', [
            'name' => 'Once', 'abilities' => ['read'],
        ])->assertSessionHasNoErrors();
        $sealed = session('integration_api_key');
        self::assertIsString($sealed);
        $plain = json_decode(Crypt::decryptString($sealed), true, flags: JSON_THROW_ON_ERROR)['value'];
        self::assertStringNotContainsString($plain, serialize(session()->all()));
        $page = $this->get('/integrations/api');
        $page->assertOk()->assertInertia(fn (Assert $page) => $page->where('integration_api_key_available', true)->missing('integration_api_key'));
        self::assertStringNotContainsString($plain, json_encode($page->viewData('page'), JSON_THROW_ON_ERROR));
        self::assertStringContainsString('no-store', $page->headers->get('Cache-Control'));
        $response = $this->postJson('/integrations/api/secrets', ['type' => 'api_key'])->assertOk();
        self::assertTrue(hash_equals($plain, $response->json('value')));
        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->postJson('/integrations/api/secrets', ['type' => 'api_key'])->assertNotFound();
        $this->get('/integrations/api')->assertOk()->assertInertia(fn (Assert $page) => $page->where('integration_api_key_available', false));
    }

    public function test_switching_brand_prevents_retrieving_secret_even_after_switching_back(): void
    {
        [$owner, , $first, $second] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $first->id])->post('/integrations/api/keys', [
            'name' => 'First brand', 'abilities' => ['read'],
        ])->assertSessionHasNoErrors();
        $this->post('/brand/switch', ['brand_id' => $second->id])->assertRedirect('/auth/ready');
        $this->postJson('/integrations/api/secrets', ['type' => 'api_key'])->assertNotFound();
        $this->post('/brand/switch', ['brand_id' => $first->id])->assertRedirect('/auth/ready');
        $this->postJson('/integrations/api/secrets', ['type' => 'api_key'])->assertNotFound();
    }

    public function test_secret_endpoint_checks_permission_and_type(): void
    {
        [$owner, , $brand] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->post('/integrations/api/keys', [
            'name' => 'Once', 'abilities' => ['read'],
        ])->assertSessionHasNoErrors();
        $this->postJson('/integrations/api/secrets', ['type' => 'other'])->assertUnprocessable();
        Gate::before(fn ($user, $ability) => $ability === 'integrations.write' ? false : null);
        $this->postJson('/integrations/api/secrets', ['type' => 'api_key'])->assertForbidden();
    }

    public function test_integrations_permission_is_required_even_for_a_brand_manager(): void
    {
        [$owner, , $brand] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->post('/integrations/api/keys', [
            'name' => 'Existing', 'abilities' => ['read'],
        ])->assertSessionHasNoErrors();
        $id = DB::table('personal_access_tokens')->value('id');
        Gate::before(fn ($user, $ability) => $ability === 'integrations.write' ? false : null);
        $this->post('/integrations/api/keys', ['name' => 'Denied', 'abilities' => ['read']])->assertForbidden();
        $this->delete('/integrations/api/keys/'.$id)->assertForbidden();
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_validation_and_permission_reject_invalid_or_unauthorized_creation(): void
    {
        [$owner, $organization, $brand] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->post('/integrations/api/keys', [
            'name' => str_repeat('a', 61), 'abilities' => ['leads', 'admin'],
        ])->assertSessionHasErrors(['name', 'abilities.1']);
        $this->post('/integrations/api/keys', ['name' => ' ', 'abilities' => []])->assertSessionHasErrors();
        [$member] = $this->member($organization, 'member', [$brand->id]);
        $this->actingAs($member)->withSession(['brand_id' => $brand->id])->post('/integrations/api/keys', [
            'name' => 'Denied', 'abilities' => ['read'],
        ])->assertForbidden();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_other_brand_cannot_list_or_revoke_key(): void
    {
        [$owner, $organization, $first, $second] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $first->id])->post('/integrations/api/keys', [
            'name' => 'First brand', 'abilities' => ['leads'],
        ])->assertSessionHasNoErrors();
        $id = DB::table('personal_access_tokens')->value('id');
        $ctx = new TenantContext($organization->id, $second->id, source: 'job');
        self::assertSame([], app(TenantContextRunner::class)->run($ctx, fn () => KeysController::listing($ctx)));
        $this->post('/brand/switch', ['brand_id' => $second->id])->assertRedirect('/auth/ready');
        $this->delete('/integrations/api/keys/'.$id)->assertNotFound();
        $this->delete('/integrations/api/keys/999999999999999999999')->assertNotFound();
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $id, 'brand_id' => $first->id]);
    }

    public function test_failed_audit_rolls_back_key_creation(): void
    {
        [$owner, , $brand] = $this->cabinet();
        $audit = $this->createStub(AuditWriter::class);
        $audit->method('record')->willThrowException(new RuntimeException('audit unavailable'));
        $this->app->instance(AuditWriter::class, $audit);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->post('/integrations/api/keys', [
            'name' => 'No key', 'abilities' => ['read'],
        ])->assertStatus(500);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}

<?php

namespace Tests\Feature\Shell\Integrations\Webhooks;

use App\Shell\Brands\Contracts\DomainDnsResolver;
use App\Shell\Integrations\Webhooks\WebhookDelivery;
use App\Shell\Integrations\Webhooks\WebhookOutbox;
use App\Shell\Integrations\Webhooks\WebhookService;
use App\Tenancy\TenantContext;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use LogicException;
use Tests\Feature\Tenancy\TenancyTestCase;

final class WebhookTest extends TenancyTestCase
{
    private WebhookDns $dns;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'SERVER_ADDR' => '127.0.0.1']);
        config(['session.encrypt' => false]);
        $this->dns = new WebhookDns;
        $this->app->instance(DomainDnsResolver::class, $this->dns);
    }

    public function test_test_delivery_is_disabled_for_both_brands(): void
    {
        Http::fake();
        [$owner, $organization, $first, $second] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $first->id]);
        $id = $this->insertLegacyWebhook($organization->id, $first->id);
        $this->post('/integrations/api/webhooks/'.$id.'/test')->assertStatus(503);
        config(['features.E1-10.defaults.webhook_delivery' => true]);
        $this->post('/integrations/api/webhooks/'.$id.'/test')->assertStatus(503);
        $this->get('/integrations/api')->assertOk()->assertInertia(fn ($page) => $page->where('webhookDeliveryAvailable', false));
        $this->withSession(['brand_id' => $second->id])->post('/integrations/api/webhooks/'.$id.'/test')->assertStatus(503);
        $this->assertDatabaseCount('webhook_deliveries', 0);
        Http::assertNothingSent();
    }

    public function test_test_delivery_signs_exact_bytes_and_limits_log_to_ten(): void
    {
        self::markTestSkipped('Manual webhook delivery is disabled until an authenticated receipt is implemented.');
        Http::fake();
        [$owner, $org, $brand] = $this->cabinet();
        config(['features.E1-10.defaults.webhook_delivery' => true]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->insertLegacyWebhook($org->id, $brand->id);
        $hook = DB::table('webhooks')->first();
        $this->post('/integrations/api/webhooks/'.$hook->id.'/test')->assertSessionHasNoErrors();
        $delivery = DB::table('webhook_deliveries')->first();
        self::assertNotNull($delivery);
        app(WebhookDelivery::class)->send($delivery->id);
        $secret = Crypt::decryptString($hook->secret);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://public.org/hook' && $request->hasHeader('X-Souzpir-Signature', 'sha256='.hash_hmac('sha256', $request->body(), $secret)));
        $this->assertDatabaseHas('webhook_deliveries', ['id' => $delivery->id, 'status' => 'sent', 'attempts' => 1, 'status_code' => 200]);
        for ($i = 0; $i < 11; $i++) {
            DB::table('webhook_deliveries')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'webhook_id' => $hook->id,
                'event' => 'webhook.test', 'payload' => '{}', 'status' => 'sent', 'attempts' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
        self::assertCount(10, app(WebhookService::class)->forBrand(new TenantContext($org->id, $brand->id, source: 'job'))[0]['deliveries']);
    }

    public function test_failures_retry_five_times_but_private_dns_never_sends(): void
    {
        self::markTestSkipped('Webhook network delivery is disabled until the authorization contract is implemented.');
        [$owner, , $brand] = $this->cabinet();
        config(['features.E1-10.defaults.webhook_delivery' => true]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/integrations/api/webhooks', ['url' => 'https://public.org/hook', 'events' => ['seller.lead.created']]);
        $id = DB::table('webhooks')->value('id');
        Http::fake(['*' => Http::response('', 500)]);
        $this->post('/integrations/api/webhooks/'.$id.'/test');
        $delivery = DB::table('webhook_deliveries')->latest('created_at')->first();
        app(WebhookDelivery::class)->send($delivery->id);
        Http::assertSentCount(5);
        $this->assertDatabaseHas('webhook_deliveries', ['id' => $delivery->id, 'status' => 'failed', 'attempts' => 5, 'status_code' => 500]);
        Http::fake();
        $this->dns->addresses = ['127.0.0.1'];
        $privateId = (string) Str::ulid();
        $hook = DB::table('webhooks')->where('id', $id)->first();
        DB::table('webhook_deliveries')->insert(['id' => $privateId, 'organization_id' => $hook->organization_id, 'brand_id' => $hook->brand_id, 'webhook_id' => $id, 'event' => 'webhook.test', 'payload' => '{}', 'status' => 'pending', 'attempts' => 0, 'created_at' => now(), 'updated_at' => now()]);
        app(WebhookDelivery::class)->send($privateId);
        Http::assertNothingSent();
        $this->assertDatabaseHas('webhook_deliveries', ['id' => $privateId, 'status' => 'failed', 'attempts' => 5]);
    }

    public function test_outbox_and_drain_leave_unverified_events_unconsumed_and_create_no_delivery(): void
    {
        Http::fake();
        [$owner, $org, $brand, $other] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->insertLegacyWebhook($org->id, $brand->id);
        $eventId = (string) Str::ulid();
        $envelope = ['event_id' => $eventId, 'schema_version' => 1, 'type' => 'seller.lead.created', 'occurred_at' => now()->toIso8601String(),
            'organization_id' => $org->id, 'brand_id' => $brand->id, 'actor_id' => null,
            'aggregate' => ['type' => 'conversation', 'id' => (string) Str::ulid(), 'version' => 1], 'data' => ['conversation_id' => (string) Str::ulid()]];
        DB::table('outbox_events')->insert(['id' => $eventId, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'envelope' => json_encode($envelope, JSON_THROW_ON_ERROR), 'created_at' => now()]);
        config(['features.E1-10.defaults.webhook_delivery' => true]);
        Queue::fake();
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                app(WebhookOutbox::class)->consume($eventId);
                self::fail('An event without a subscription snapshot must remain unconsumed.');
            } catch (LogicException $exception) {
                self::assertSame('Webhook subscription snapshot unavailable.', $exception->getMessage());
            }
        }
        $this->assertDatabaseCount('webhook_deliveries', 0);
        $this->assertDatabaseMissing('consumed_events', ['event_id' => $eventId, 'consumer' => 'integrations.webhooks.v1']);
        $this->artisan('integrations:webhooks-drain')->assertExitCode(1);
        $this->assertDatabaseCount('webhook_deliveries', 0);
        $this->assertDatabaseMissing('consumed_events', ['event_id' => $eventId, 'consumer' => 'integrations.webhooks.v1']);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_legacy_pending_and_test_rows_are_never_sent_even_if_delivery_flag_is_enabled(): void
    {
        Http::fake();
        Queue::fake();
        config(['features.E1-10.defaults.webhook_delivery' => true]);
        [, $org, $brand] = $this->cabinet();
        $hookId = (string) Str::ulid();
        DB::table('webhooks')->insert([
            'id' => $hookId, 'organization_id' => $org->id, 'brand_id' => $brand->id,
            'url' => 'https://public.org/hook', 'events' => json_encode(['seller.lead.created'], JSON_THROW_ON_ERROR),
            'secret' => Crypt::encryptString('synthetic-secret'), 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (['seller.lead.created', 'webhook.test'] as $event) {
            $deliveryId = (string) Str::ulid();
            DB::table('webhook_deliveries')->insert([
                'id' => $deliveryId, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'webhook_id' => $hookId,
                'event_id' => $event === 'webhook.test' ? null : (string) Str::ulid(), 'event' => $event,
                'payload' => json_encode(['id' => $deliveryId, 'type' => $event, 'data' => []], JSON_THROW_ON_ERROR),
                'status' => 'pending', 'attempts' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
            app(WebhookDelivery::class)->send($deliveryId);
            $this->assertDatabaseHas('webhook_deliveries', ['id' => $deliveryId, 'status' => 'pending', 'attempts' => 0]);
        }
        $this->artisan('integrations:webhooks-drain')->assertExitCode(1);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_outbox_rejects_event_id_and_tenant_mismatches_before_delivery(): void
    {
        Http::fake();
        Queue::fake();
        config(['features.E1-10.defaults.webhook_delivery' => true]);
        [, $org, $brand, $otherBrand] = $this->cabinet();
        [, $otherOrg, $foreignBrand] = $this->cabinet(false);
        foreach ([[$org->id, $brand->id], [$org->id, $otherBrand->id], [$otherOrg->id, $foreignBrand->id]] as [$orgId, $brandId]) {
            DB::table('webhooks')->insert([
                'id' => (string) Str::ulid(), 'organization_id' => $orgId, 'brand_id' => $brandId,
                'url' => 'https://public.org/hook', 'events' => json_encode(['seller.lead.created'], JSON_THROW_ON_ERROR),
                'secret' => Crypt::encryptString('synthetic-secret'), 'created_at' => now()->subMinute(), 'updated_at' => now(),
            ]);
        }
        $eventId = (string) Str::ulid();
        $envelope = ['event_id' => $eventId, 'schema_version' => 1, 'type' => 'seller.lead.created', 'occurred_at' => now()->toIso8601String(),
            'organization_id' => $org->id, 'brand_id' => $brand->id, 'actor_id' => null,
            'aggregate' => ['type' => 'conversation', 'id' => (string) Str::ulid(), 'version' => 1], 'data' => ['conversation_id' => (string) Str::ulid()]];
        DB::table('outbox_events')->insert(['id' => $eventId, 'organization_id' => $org->id, 'brand_id' => $brand->id,
            'envelope' => json_encode($envelope, JSON_THROW_ON_ERROR), 'created_at' => now()]);

        foreach ([
            ['event_id' => (string) Str::ulid()],
            ['brand_id' => $otherBrand->id],
            ['organization_id' => $otherOrg->id, 'brand_id' => $foreignBrand->id],
        ] as $changes) {
            DB::table('outbox_events')->where('id', $eventId)->update(['envelope' => json_encode(array_replace($envelope, $changes), JSON_THROW_ON_ERROR)]);
            try {
                app(WebhookOutbox::class)->consume($eventId);
                self::fail('Mismatched outbox envelope must fail closed.');
            } catch (LogicException $exception) {
                self::assertSame('Webhook event identity mismatch.', $exception->getMessage());
            }
            $this->assertDatabaseCount('webhook_deliveries', 0);
            $this->assertDatabaseMissing('consumed_events', ['event_id' => $eventId, 'consumer' => 'integrations.webhooks.v1']);
        }
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    private function insertLegacyWebhook(string $organizationId, string $brandId): string
    {
        $id = (string) Str::ulid();
        DB::table('webhooks')->insert(['id' => $id, 'organization_id' => $organizationId, 'brand_id' => $brandId,
            'url' => 'https://public.org/hook', 'events' => json_encode(['seller.lead.created'], JSON_THROW_ON_ERROR),
            'secret' => Crypt::encryptString('synthetic-secret'), 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }
}

final class WebhookDns implements DomainDnsResolver
{
    public array $addresses = ['8.8.8.8'];

    public function addresses(string $host): array
    {
        return $this->addresses;
    }

    public function txtRecords(string $host): array
    {
        return [];
    }
}

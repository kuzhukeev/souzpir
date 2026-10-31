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
use RuntimeException;
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

    public function test_create_validates_and_scopes_webhook_and_hides_secret(): void
    {
        Http::fake();
        [$owner, $org, $brand] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/integrations/api/webhooks', ['url' => 'http://private.local/', 'events' => ['unknown']])->assertSessionHasErrors(['url', 'events.0']);
        $this->post('/integrations/api/webhooks', ['url' => 'https://user:pass@public.org/hook', 'events' => ['seller.lead.created']])->assertSessionHasErrors('url');
        $this->post('/integrations/api/webhooks', ['url' => 'https://public.org/hook', 'events' => ['seller.lead.created']])->assertSessionHasNoErrors()->assertSessionHas('webhook_secret');
        $row = DB::table('webhooks')->first();
        $secret = Crypt::decryptString($row->secret);
        self::assertIsString(session('webhook_secret'));
        self::assertSame($secret, json_decode(Crypt::decryptString(session('webhook_secret')), true, flags: JSON_THROW_ON_ERROR)['value']);
        self::assertStringNotContainsString($secret, serialize(session()->all()));
        $page = $this->get('/integrations/api')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $pageProps = $page->viewData('page')['props'];
        self::assertTrue($pageProps['integration_webhook_secret_available']);
        self::assertStringNotContainsString($secret, json_encode($pageProps, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString($secret, $page->getContent());
        $revealed = $this->postJson('/integrations/api/secrets', ['type' => 'webhook_secret'])->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $revealed->assertExactJson(['value' => $secret]);
        $this->postJson('/integrations/api/secrets', ['type' => 'webhook_secret'])->assertNotFound();
        $nextPage = $this->get('/integrations/api')->assertOk();
        self::assertFalse($nextPage->viewData('page')['props']['integration_webhook_secret_available']);
        self::assertSame($brand->id, $row->brand_id);
        self::assertNotSame(Crypt::decryptString($row->secret), $row->secret);
        $ctx = new TenantContext($org->id, $brand->id, source: 'job');
        $props = app(WebhookService::class)->forBrand($ctx);
        self::assertSame('••••••••', $props[0]['secret']);
        self::assertStringNotContainsString(Crypt::decryptString($row->secret), json_encode($props, JSON_THROW_ON_ERROR));
        $this->assertDatabaseHas('audit_logs', ['organization_id' => $org->id, 'brand_id' => $brand->id, 'action' => 'integrations.webhook.created']);
        Http::assertNothingSent();
    }

    public function test_one_time_secret_cannot_be_retrieved_for_another_brand(): void
    {
        Http::fake();
        [$owner, , $first, $second] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $first->id]);
        $this->post('/integrations/api/webhooks', ['url' => 'https://public.org/hook', 'events' => ['seller.lead.created']])->assertSessionHasNoErrors();
        $this->withSession(['brand_id' => $second->id])->postJson('/integrations/api/secrets', ['type' => 'webhook_secret'])->assertNotFound();
        $this->withSession(['brand_id' => $first->id])->postJson('/integrations/api/secrets', ['type' => 'webhook_secret'])->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_encryption_failure_does_not_expose_secret_in_exception_chain_or_trace(): void
    {
        if (ini_get('zend.exception_ignore_args') !== '0') {
            self::markTestSkipped('Run with zend.exception_ignore_args=0 to exercise exception arguments.');
        }

        [$owner, , $brand] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $secret = null;
        $original = null;
        $encrypter = \Mockery::mock(Crypt::getFacadeRoot())->makePartial();
        Crypt::swap($encrypter);
        $encrypter->shouldReceive('encryptString')->once()->andReturnUsing(function (string $value) use (&$secret, &$original): never {
            $secret = $value;
            throw $original = new RuntimeException('Simulated encryption failure.');
        });

        $this->withoutExceptionHandling();
        try {
            $this->post('/integrations/api/webhooks', ['url' => 'https://public.org/hook', 'events' => ['seller.lead.created']]);
            self::fail('Expected encryption failure.');
        } catch (RuntimeException $error) {
            self::assertSame('Unable to protect webhook secret.', $error->getMessage());
            self::assertNull($error->getPrevious());
            self::assertNotNull($secret);
            self::assertNotNull($original);
            $traceArguments = static function (array $trace): array {
                $strings = [];
                array_walk_recursive($trace, static function ($value) use (&$strings): void {
                    if (is_string($value)) {
                        $strings[] = $value;
                    }
                });

                return $strings;
            };
            self::assertContains($secret, $traceArguments($original->getTrace()));
            self::assertNotContains($secret, $traceArguments($error->getTrace()));
            self::assertStringNotContainsString($secret, $error->getTraceAsString());
        } finally {
            $this->withExceptionHandling();
        }
        $this->assertDatabaseCount('webhooks', 0);
        $this->assertDatabaseCount('integration_secret_receipts', 0);
    }

    public function test_test_delivery_is_disabled_by_default_and_rejects_other_brand(): void
    {
        Http::fake();
        [$owner, , $first, $second] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $first->id]);
        $this->post('/integrations/api/webhooks', ['url' => 'https://public.org/hook', 'events' => ['seller.lead.created']]);
        $id = DB::table('webhooks')->value('id');
        $this->post('/integrations/api/webhooks/'.$id.'/test')->assertStatus(503);
        config(['features.E1-10.defaults.webhook_delivery' => true]);
        $this->withSession(['brand_id' => $second->id])->post('/integrations/api/webhooks/'.$id.'/test')->assertNotFound();
        $this->assertDatabaseCount('webhook_deliveries', 0);
        Http::assertNothingSent();
    }

    public function test_test_delivery_signs_exact_bytes_and_limits_log_to_ten(): void
    {
        Http::fake();
        [$owner, $org, $brand] = $this->cabinet();
        config(['features.E1-10.defaults.webhook_delivery' => true]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/integrations/api/webhooks', ['url' => 'https://public.org/hook', 'events' => ['seller.lead.created']]);
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

    public function test_independent_outbox_consumer_is_idempotent_and_brand_scoped(): void
    {
        Http::fake();
        [$owner, $org, $brand, $other] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/integrations/api/webhooks', ['url' => 'https://public.org/hook', 'events' => ['seller.lead.created']]);
        $eventId = (string) Str::ulid();
        $envelope = ['event_id' => $eventId, 'schema_version' => 1, 'type' => 'seller.lead.created', 'occurred_at' => now()->toIso8601String(),
            'organization_id' => $org->id, 'brand_id' => $brand->id, 'actor_id' => null,
            'aggregate' => ['type' => 'conversation', 'id' => (string) Str::ulid(), 'version' => 1], 'data' => ['conversation_id' => (string) Str::ulid()]];
        DB::table('outbox_events')->insert(['id' => $eventId, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'envelope' => json_encode($envelope, JSON_THROW_ON_ERROR), 'created_at' => now()]);
        app(WebhookOutbox::class)->consume($eventId);
        app(WebhookOutbox::class)->consume($eventId);
        $this->assertDatabaseCount('webhook_deliveries', 1);
        $this->assertDatabaseHas('webhook_deliveries', ['event_id' => $eventId, 'brand_id' => $brand->id, 'status' => 'pending']);
        $this->assertDatabaseHas('consumed_events', ['event_id' => $eventId, 'consumer' => 'integrations.webhooks.v1']);
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

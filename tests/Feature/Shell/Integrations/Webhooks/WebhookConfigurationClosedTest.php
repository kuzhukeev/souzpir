<?php

namespace Tests\Feature\Shell\Integrations\Webhooks;

use App\Http\Middleware\HandleInertiaRequests;
use App\Shell\Integrations\Webhooks\WebhookConfigurationBoundary;
use App\Shell\Integrations\Webhooks\WebhookService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Feature\Tenancy\TenancyTestCase;

final class WebhookConfigurationClosedTest extends TenancyTestCase
{
    public function test_configuration_requests_are_denied_and_scrubbed_before_observers(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        Queue::fake();
        [$owner, $organization, $brand] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $url = 'https://hooks.example.test/private-fixture-path?token=fixture-query-secret';
        $captured = [];
        $this->app['events']->listen(MessageLogged::class, static function (MessageLogged $event) use (&$captured): void {
            $captured[] = [$event->message, $event->context];
        });
        $this->app['events']->listen(RequestHandled::class, static function (RequestHandled $event) use (&$captured): void {
            $captured[] = [$event->request->fullUrl(), $event->request->all(), $event->request->getContent(),
                $event->request->server('QUERY_STRING'), $event->request->server('REQUEST_URI'), $_POST, $_GET, $_REQUEST,
                $_SERVER['QUERY_STRING'] ?? null, $_SERVER['REQUEST_URI'] ?? null];
        });
        DB::listen(static function (QueryExecuted $query) use (&$captured): void {
            $captured[] = [$query->sql, $query->bindings];
        });
        foreach (['POST', 'PATCH', 'PUT', 'DELETE'] as $method) {
            $path = $method === 'POST' ? '/integrations/api/webhooks' : '/integrations/api/webhooks/old-id';
            $this->json($method, $path.'?url='.urlencode($url), ['url' => $url, 'events' => ['seller.lead.created']])
                ->assertStatus(503)->assertHeader('Cache-Control', 'no-store, private')
                ->assertJsonMissing(['url' => $url]);
        }
        $this->post('/integrations/api/webhooks', ['url' => $url, 'events' => ['seller.lead.created']])->assertStatus(503);
        $this->json('POST', '/integrations/api/%77ebhooks?url='.urlencode($url), ['url' => $url])->assertStatus(503);
        $this->post('/integrations/api/webhooks', ['_method' => 'PATCH', 'url' => $url])->assertStatus(503);
        $this->assertDatabaseCount('webhooks', 0);
        $this->assertDatabaseCount('webhook_deliveries', 0);
        $this->assertDatabaseMissing('audit_logs', ['organization_id' => $organization->id, 'brand_id' => $brand->id, 'action' => 'integrations.webhook.created']);
        self::assertNotEmpty($captured);
        $serialized = json_encode($captured, JSON_PARTIAL_OUTPUT_ON_ERROR);
        self::assertStringNotContainsString($url, $serialized);
        self::assertStringNotContainsString('fixture-query-secret', $serialized);
        self::assertStringNotContainsString('private-fixture-path', $serialized);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_existing_webhook_url_is_never_selected_or_returned_to_the_page(): void
    {
        Http::preventStrayRequests();
        [$owner, $organization, $brand] = $this->cabinet();
        $url = 'https://hooks.example.test/private-fixture-path?token=fixture-query-secret';
        $id = (string) Str::ulid();
        DB::table('webhooks')->insert(['id' => $id, 'organization_id' => $organization->id, 'brand_id' => $brand->id,
            'url' => $url, 'events' => json_encode(['seller.lead.created', $url], JSON_THROW_ON_ERROR),
            'secret' => Crypt::encryptString('synthetic-signing-secret'), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('webhook_deliveries')->insert(['id' => (string) Str::ulid(), 'organization_id' => $organization->id,
            'brand_id' => $brand->id, 'webhook_id' => $id, 'event' => $url, 'payload' => json_encode(['url' => $url], JSON_THROW_ON_ERROR),
            'status' => 'fixture-secret', 'attempts' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'SERVER_ADDR' => '127.0.0.1']);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $response = $this->get('/integrations/api')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $props = $response->viewData('page')['props'];
        self::assertCount(1, $props['webhooks']);
        self::assertArrayNotHasKey('url', $props['webhooks'][0]);
        self::assertSame(['seller.lead.created'], $props['webhooks'][0]['events']);
        self::assertSame('unknown', $props['webhooks'][0]['deliveries'][0]['event']);
        self::assertSame('unknown', $props['webhooks'][0]['deliveries'][0]['status']);
        self::assertFalse($props['integration_webhook_secret_available']);
        self::assertStringNotContainsString($url, $response->getContent());
        self::assertStringNotContainsString('fixture-query-secret', json_encode($props, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('private-fixture-path', json_encode($props, JSON_THROW_ON_ERROR));
        // CI runs PHP tests before the frontend build; use a deterministic
        // Inertia asset version even when build/manifest.json does not exist.
        config(['app.asset_url' => 'https://e110-assets.invalid']);
        $version = app(HandleInertiaRequests::class)->version(Request::create('/integrations/api'));
        $inertia = $this->get('/integrations/api', ['X-Inertia' => 'true', 'X-Inertia-Version' => $version, 'X-Requested-With' => 'XMLHttpRequest'])->assertOk();
        self::assertStringNotContainsString($url, $inertia->getContent());
        self::assertStringNotContainsString('fixture-query-secret', $inertia->getContent());
        $this->postJson('/integrations/api/secrets', ['type' => 'webhook_secret'])->assertStatus(503);
    }

    public function test_service_cannot_bypass_closed_http_boundary(): void
    {
        $request = Request::create('/', 'POST', ['url' => 'https://example.test/private?token=fixture-secret']);
        try {
            app(WebhookService::class)->store($request);
            self::fail('Disabled webhook store accepted a request.');
        } catch (HttpExceptionInterface $exception) {
            self::assertSame(503, $exception->getStatusCode());
            self::assertStringNotContainsString('fixture-secret', $exception->getMessage());
        }
        $this->assertDatabaseCount('webhooks', 0);
        $this->assertDatabaseCount('webhook_deliveries', 0);
    }

    public function test_concurrent_runtime_keeps_process_globals_while_scrubbing_request(): void
    {
        $savedServer = $_SERVER;
        $savedPost = $_POST;
        $savedGet = $_GET;
        $savedRequest = $_REQUEST;
        $savedFiles = $_FILES;
        try {
            $_SERVER['LARAVEL_OCTANE'] = '1';
            $_POST = ['sentinel' => 'other-request'];
            $request = Request::create('/integrations/api/webhooks?url=fixture-secret', 'POST', ['url' => 'fixture-secret']);
            $response = app(WebhookConfigurationBoundary::class)->handle($request, static fn () => throw new \LogicException('must not continue'));
            self::assertSame(503, $response->getStatusCode());
            self::assertSame(['sentinel' => 'other-request'], $_POST);
            self::assertStringNotContainsString('fixture-secret', $request->fullUrl());
            self::assertSame([], $request->all());
            self::assertSame('', $request->getContent());
        } finally {
            $_SERVER = $savedServer;
            $_POST = $savedPost;
            $_GET = $savedGet;
            $_REQUEST = $savedRequest;
            $_FILES = $savedFiles;
        }
    }
}

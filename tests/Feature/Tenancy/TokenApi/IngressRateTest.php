<?php

namespace Tests\Feature\Tenancy\TokenApi;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Shell\Support\Fixtures\IsolatedPlanTestDatabase;
use Tests\Support\IsolatedDatabaseMigrations;
use Tests\TestCase;

final class IngressRateTest extends TestCase
{
    use IsolatedDatabaseMigrations;

    public function createApplication(): Application
    {
        $app = parent::createApplication();
        $app->make(Kernel::class)->bootstrap();
        IsolatedPlanTestDatabase::assert($app);

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->withServerVariables(['HTTPS' => 'on', 'SERVER_ADDR' => '127.0.0.1']);
    }

    public function test_sixty_invalid_bearers_are_checked_then_blocked_before_token_lookup(): void
    {
        $lookups = 0;
        $handled = '';
        $raw = '123|'.str_repeat('Z', 64);
        Event::listen(RequestHandled::class, static function (RequestHandled $event) use (&$handled): void {
            if ($event->response->getStatusCode() === 429) {
                $handled = json_encode([$event->request->headers->all(), $event->request->server->all()], JSON_THROW_ON_ERROR);
            }
        });
        DB::listen(static function (QueryExecuted $query) use (&$lookups): void {
            if (str_contains(strtolower($query->sql), 'personal_access_tokens')) {
                $lookups++;
            }
        });
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.22']);
        $this->withHeader('Authorization', 'Bearer '.$raw);
        for ($i = 0; $i < 60; $i++) {
            $result = $this->getJson('https://localhost/api/v1/brand');
            self::assertSame(401, $result->getStatusCode(), $result->getContent());
        }
        self::assertSame(60, $lookups);
        $this->getJson('https://localhost/api/v1/brand')->assertStatus(429)->assertHeader('Cache-Control', 'no-store, private');
        self::assertSame(60, $lookups, 'Throttling must run before Sanctum touches the token table.');
        self::assertNotSame('', $handled);
        self::assertStringNotContainsString($raw, $handled);
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.99'])->getJson('https://localhost/api/v1/brand')->assertUnauthorized();
        self::assertSame(61, $lookups, 'A separate physical peer must keep its own bucket.');
    }

    public function test_forged_host_and_forwarded_address_cannot_change_peer_bucket(): void
    {
        $observed = [];
        Event::listen(RequestHandled::class, static function (RequestHandled $event) use (&$observed): void {
            if ($observed === []) {
                $observed = [$event->request->ip(), $event->request->server->get('REMOTE_ADDR')];
            }
        });
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.23', 'HTTP_HOST' => 'spoof.on-forge.com']);
        $this->withHeader('Host', 'spoof.on-forge.com');
        $this->withHeader('Authorization', 'Bearer 123|'.str_repeat('Y', 64));
        $this->withHeader('X-Forwarded-For', '203.0.113.1')->getJson('https://spoof.on-forge.com/api/v1/brand')->assertForbidden();
        self::assertSame(['192.0.2.23', '192.0.2.23'], $observed, 'An untrusted forwarded address must not replace the physical peer.');
        config(['trustedproxy.proxies' => ['192.0.2.23']]);
        $this->withHeader('X-Forwarded-Proto', 'https');
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.23', 'HTTP_HOST' => 'localhost'])->withHeader('Host', 'localhost');
        for ($i = 1; $i <= 60; $i++) {
            $this->withHeader('X-Forwarded-For', '203.0.113.'.$i)->getJson('https://localhost/api/v1/brand')->assertUnauthorized();
        }
        $this->withHeader('X-Forwarded-For', '203.0.113.200')->getJson('https://localhost/api/v1/brand')->assertStatus(429);
    }

    public function test_ipv6_forms_and_ipv4_mapped_address_use_stable_peer_buckets(): void
    {
        $this->withHeader('Authorization', 'Bearer 123|'.str_repeat('X', 64));
        $this->withServerVariables(['REMOTE_ADDR' => '2001:db8::1']);
        for ($i = 0; $i < 60; $i++) {
            $this->getJson('https://localhost/api/v1/brand')->assertUnauthorized();
        }
        $this->withServerVariables(['REMOTE_ADDR' => '2001:0db8:0:0:0:0:0:1'])->getJson('https://localhost/api/v1/brand')->assertStatus(429);
        $this->withServerVariables(['REMOTE_ADDR' => '::ffff:192.0.2.24']);
        for ($i = 0; $i < 60; $i++) {
            $this->getJson('https://localhost/api/v1/brand')->assertUnauthorized();
        }
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.24'])->getJson('https://localhost/api/v1/brand')->assertStatus(429);
    }

    public function test_missing_or_invalid_peer_fails_closed_even_with_spoofed_headers(): void
    {
        $this->withHeader('Authorization', 'Bearer 123|'.str_repeat('V', 64));
        $this->withHeader('X-Forwarded-For', '203.0.113.5');
        $this->withServerVariables(['REMOTE_ADDR' => null])->getJson('https://localhost/api/v1/brand')->assertForbidden();
        $this->withServerVariables(['REMOTE_ADDR' => 'invalid-peer'])->getJson('https://localhost/api/v1/brand')->assertForbidden();
    }

    public function test_unsupported_cache_driver_fails_closed(): void
    {
        $handled = '';
        $raw = '123|'.str_repeat('Q', 64);
        Event::listen(RequestHandled::class, static function (RequestHandled $event) use (&$handled): void {
            if ($event->response->getStatusCode() === 503) {
                $handled = json_encode([$event->request->headers->all(), $event->request->server->all()], JSON_THROW_ON_ERROR);
            }
        });
        config(['cache.default' => 'null', 'cache.stores.null.driver' => 'null']);
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.25']);
        $this->withHeader('Authorization', 'Bearer '.$raw);
        $this->getJson('https://localhost/api/v1/brand')->assertStatus(503)->assertHeader('Cache-Control', 'no-store, private');
        self::assertNotSame('', $handled);
        self::assertStringNotContainsString($raw, $handled);
    }
}

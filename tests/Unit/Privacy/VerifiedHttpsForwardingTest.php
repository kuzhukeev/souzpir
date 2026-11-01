<?php

namespace Tests\Unit\Privacy;

use App\Privacy\VerifiedHttps;
use App\Shell\Auth\MfaRecoveryTransport;
use App\Shell\Auth\SecureAccountContext;
use App\Shell\Integrations\SecretTransport;
use App\Shell\Support\Http\SupportController;
use App\Tenancy\TokenApi\BearerBoundary;
use Illuminate\Http\Request;
use ReflectionMethod;
use Tests\TestCase;

final class VerifiedHttpsForwardingTest extends TestCase
{
    public function test_spoofed_forwarding_cannot_turn_http_into_trusted_tls(): void
    {
        config(['trustedproxy.proxies' => null]);
        // OSPanel's FastCGI config can set HTTPS=on from this client header.
        $request = Request::create('http://cabinet.example.test/profile', 'GET', [], [], [], [
            'HTTPS' => 'on', 'REMOTE_ADDR' => '192.0.2.10', 'SERVER_ADDR' => '192.0.2.20',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);
        $request->server->set('HTTPS', 'on');

        self::assertFalse(VerifiedHttps::allows($request));
        self::assertFalse(SecureAccountContext::allows($request));
        self::assertFalse(MfaRecoveryTransport::allows($request));
    }

    public function test_spoofed_tls_is_rejected_by_integrations_support_and_token_api(): void
    {
        config(['trustedproxy.proxies' => null]);
        $request = Request::create('http://cabinet.example.test/help/support', 'GET', [], [], [], [
            'REMOTE_ADDR' => '192.0.2.10', 'SERVER_ADDR' => '192.0.2.20',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);
        $request->server->set('HTTPS', 'on');
        self::assertFalse(SecretTransport::allows($request));
        $supportGuard = new ReflectionMethod(SupportController::class, 'secureContext');
        self::assertFalse($supportGuard->invoke(app(SupportController::class), $request));

        $api = Request::create('http://cabinet.example.test/api/v1/brand', 'GET', [], [], [], [
            'REMOTE_ADDR' => '192.0.2.10', 'SERVER_ADDR' => '192.0.2.20',
            'HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_AUTHORIZATION' => 'Bearer 1|'.str_repeat('A', 64),
        ]);
        $api->server->set('HTTPS', 'on');
        $response = app(BearerBoundary::class)->handle($api, fn () => response('', 204));
        self::assertSame(403, $response->getStatusCode());
        self::assertFalse($api->headers->has('Authorization'));
    }

    public function test_original_server_headers_remain_evidence_if_middleware_removed_the_header(): void
    {
        $request = Request::create('https://cabinet.example.test/', 'GET', [], [], [], [
            'REMOTE_ADDR' => '198.51.100.8', 'SERVER_ADDR' => '192.0.2.20',
            'HTTP_X_FORWARDED_HOST' => 'synthetic.example.test',
        ]);
        $request->headers->remove('X-Forwarded-Host');
        config(['trustedproxy.proxies' => null]);
        self::assertFalse(VerifiedHttps::allows($request));
        self::assertFalse(SecureAccountContext::allows($request));
    }

    public function test_sensitive_guards_allow_direct_tls_and_explicit_trusted_proxy(): void
    {
        config(['trustedproxy.proxies' => null]);
        $direct = Request::create('https://cabinet.example.test/', 'GET', [], [], [], [
            'REMOTE_ADDR' => '192.0.2.10', 'SERVER_ADDR' => '192.0.2.20',
        ]);
        $this->assertSensitiveGuards($direct, true);

        config(['trustedproxy.proxies' => '192.0.2.10']);
        $proxy = Request::create('http://cabinet.example.test/', 'GET', [], [], [], [
            'REMOTE_ADDR' => '192.0.2.10', 'SERVER_ADDR' => '192.0.2.20',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);
        $proxy->server->set('HTTPS', 'on');
        $this->assertSensitiveGuards($proxy, true);

        config(['trustedproxy.proxies' => '192.0.2.11']);
        $this->assertSensitiveGuards($proxy, false);
    }

    private function assertSensitiveGuards(Request $request, bool $allowed): void
    {
        self::assertSame($allowed, SecretTransport::allows($request));
        $supportGuard = new ReflectionMethod(SupportController::class, 'secureContext');
        self::assertSame($allowed, $supportGuard->invoke(app(SupportController::class), $request));

        $api = Request::create('http://cabinet.example.test/api/v1/brand', 'GET', [], [], [], $request->server->all());
        $api->server->set('HTTPS', $request->server('HTTPS'));
        $api->headers->set('Authorization', 'Bearer 1|'.str_repeat('A', 64));
        $response = app(BearerBoundary::class)->handle($api, fn () => response('', 204));
        self::assertSame($allowed ? 204 : 403, $response->getStatusCode());
    }

    public function test_direct_tls_and_explicitly_trusted_proxy_are_accepted(): void
    {
        config(['trustedproxy.proxies' => ['192.0.2.0/24']]);
        $direct = Request::create('https://cabinet.example.test/', 'GET', [], [], [], [
            'HTTPS' => 'on', 'REMOTE_ADDR' => '198.51.100.8', 'SERVER_ADDR' => '192.0.2.20',
        ]);
        $proxied = Request::create('http://cabinet.example.test/', 'GET', [], [], [], [
            'HTTPS' => 'on', 'REMOTE_ADDR' => '192.0.2.10', 'SERVER_ADDR' => '192.0.2.20',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);
        $proxied->server->set('HTTPS', 'on');
        foreach ([$direct, $proxied] as $request) {
            self::assertTrue(VerifiedHttps::allows($request));
            self::assertTrue(SecureAccountContext::allows($request));
        }
        $proxied->headers->set('X-Forwarded-Proto', 'https,http');
        self::assertFalse(VerifiedHttps::allows($proxied));
        self::assertFalse(SecureAccountContext::allows($proxied));
    }

    public function test_other_forwarding_headers_also_require_a_trusted_proxy(): void
    {
        config(['trustedproxy.proxies' => null]);
        foreach (['Forwarded', 'X-Forwarded-Host', 'X-Forwarded-Port', 'X-Forwarded-Custom', 'X-Real-IP', 'CF-Connecting-IP'] as $header) {
            $request = Request::create('https://cabinet.example.test/', 'GET', [], [], [], [
                'REMOTE_ADDR' => '198.51.100.8', 'SERVER_ADDR' => '192.0.2.20',
            ]);
            $request->headers->set($header, 'synthetic-value');
            self::assertFalse(VerifiedHttps::allows($request), $header);
            self::assertFalse(SecureAccountContext::allows($request), $header);
        }
    }

    public function test_approved_local_http_still_works_without_forwarding(): void
    {
        $previous = app()->environment();
        app()->instance('env', 'local');
        try {
            $request = Request::create('http://souzpir.local/', 'GET', [], [], [], [
                'REMOTE_ADDR' => '127.0.0.1', 'SERVER_ADDR' => '127.0.0.1',
            ]);
            self::assertTrue(MfaRecoveryTransport::allows($request));
            $request->headers->set('X-Forwarded-Host', 'other.example.test');
            self::assertFalse(MfaRecoveryTransport::allows($request));
        } finally {
            app()->instance('env', $previous);
        }
    }
}

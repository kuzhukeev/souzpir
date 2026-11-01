<?php

namespace Tests\Unit\Privacy;

use App\Privacy\VerifiedHttps;
use App\Shell\Auth\MfaRecoveryTransport;
use App\Shell\Auth\SecureAccountContext;
use Illuminate\Http\Request;
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

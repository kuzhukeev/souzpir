<?php

namespace Tests\Feature\Launch\L1_08;

use App\Privacy\VerifiedHttps;
use Illuminate\Http\Request;
use Tests\TestCase;

final class TrustedProxyConfigTest extends TestCase
{
    public function test_trusted_proxies_defaults_to_empty(): void
    {
        self::assertNull(config('trustedproxy.proxies'));
    }

    public function test_forwarded_headers_are_rejected_without_a_configured_proxy(): void
    {
        config(['trustedproxy.proxies' => null]);
        $request = Request::create('https://souzpir.example.com/login', 'GET');
        $request->headers->set('X-Forwarded-Proto', 'https');
        $request->server->set('REMOTE_ADDR', '203.0.113.9');

        self::assertFalse(VerifiedHttps::allows($request));
    }

    public function test_forwarded_proto_is_accepted_from_a_configured_trusted_proxy(): void
    {
        config(['trustedproxy.proxies' => ['203.0.113.9/32']]);
        $request = Request::create('https://souzpir.example.com/login', 'GET');
        $request->headers->set('X-Forwarded-Proto', 'https');
        $request->server->set('REMOTE_ADDR', '203.0.113.9');

        self::assertTrue(VerifiedHttps::allows($request));
    }
}

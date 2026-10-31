<?php

namespace Tests\Feature\Shell\Integrations;

use App\Shell\Integrations\SecretTransport;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Tests\TestCase;

final class SecretTransportTest extends TestCase
{
    protected function tearDown(): void
    {
        Request::setTrustedProxies([], 0);
        parent::tearDown();
    }

    public function test_real_trust_proxies_middleware_cannot_promote_spoofed_forge_host(): void
    {
        config(['trustedproxy.proxies' => null]);
        $request = $this->request('attacker.on-forge.com', '203.0.113.22', '192.0.2.33', ['HTTP_X_FORWARDED_PROTO' => 'https']);
        $result = (new TrustProxies)->handle($request, fn (Request $checked): array => [$checked->isSecure(), SecretTransport::allows($checked)]);
        self::assertSame([true, false], $result); // Laravel's automatic Host trust is reproduced.
    }

    public function test_native_tls_does_not_depend_on_host(): void
    {
        $request = $this->request('cabinet.example.test', '203.0.113.22', '192.0.2.33', ['HTTPS' => 'on']);
        self::assertTrue(SecretTransport::allows($request));
    }

    public function test_proxy_tls_needs_explicit_nonuniversal_ip_or_cidr_and_unambiguous_https(): void
    {
        $request = $this->request('cabinet.example.test', '192.0.2.8', '192.0.2.33', ['HTTP_X_FORWARDED_PROTO' => 'https']);
        foreach ([null, '*', '0.0.0.0/0', '::/0', '192.0.2.9', '192.0.2.8,garbage'] as $unsafe) {
            config(['trustedproxy.proxies' => $unsafe]);
            self::assertFalse(SecretTransport::allows($request));
        }
        config(['trustedproxy.proxies' => '192.0.2.0/24']);
        self::assertTrue(SecretTransport::allows($request));
        $request->headers->set('X-Forwarded-Proto', 'https,http');
        self::assertFalse(SecretTransport::allows($request));
        $request->headers->set('X-Forwarded-Proto', 'https');
        $request->headers->set('Forwarded', 'proto=http');
        self::assertFalse(SecretTransport::allows($request));
    }

    public function test_local_domain_is_allowed_only_with_two_physical_loopback_addresses_without_proxy_headers(): void
    {
        config(['trustedproxy.proxies' => null]);
        $this->app->instance('env', 'local');
        self::assertTrue(SecretTransport::allows($this->request('souzpir.local', '127.0.0.1', '127.0.0.1')));
        self::assertTrue(SecretTransport::allows($this->request('souzpir.local', '::1', '::1')));
        self::assertFalse(SecretTransport::allows($this->request('souzpir.local', '203.0.113.22', '127.0.0.1')));
        self::assertFalse(SecretTransport::allows($this->request('souzpir.local', '127.0.0.1', '192.0.2.33')));
        self::assertFalse(SecretTransport::allows($this->request('souzpir.local', '127.0.0.1', '127.0.0.1', ['HTTP_X_REAL_IP' => '203.0.113.22'])));
        $spoof = $this->request('attacker.on-forge.com', '127.0.0.1', '127.0.0.1', ['HTTP_X_FORWARDED_HOST' => 'souzpir.local']);
        $accepted = (new TrustProxies)->handle($spoof, fn (Request $checked): bool => SecretTransport::allows($checked));
        self::assertFalse($accepted); // Middleware removes the header, but the original server variable survives.

        $this->app->instance('env', 'production');
        self::assertFalse(SecretTransport::allows($this->request('souzpir.local', '127.0.0.1', '127.0.0.1')));
    }

    private function request(string $host, string $peer, string $server, array $extra = []): Request
    {
        return Request::create((isset($extra['HTTPS']) ? 'https://' : 'http://').$host.'/integrations/api/secrets', 'POST', server: [
            'REMOTE_ADDR' => $peer, 'SERVER_ADDR' => $server, ...$extra,
        ]);
    }
}

<?php

namespace Tests\Feature\Vision\SiteAudit;

use App\Vision\SiteAudit\CrawlerClient;
use App\Vision\SiteAudit\PublicAddressResolver;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class CrawlerClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config(['features.E8-01B.defaults.fetch_enabled' => true]);
        $this->bindAddresses(['93.184.216.34']);
    }

    public function test_fetch_is_disabled_by_default_before_dns_or_http(): void
    {
        $defaults = require base_path('config/features/E8-01B.php');
        self::assertFalse($defaults['defaults']['fetch_enabled']);
        config(['features.E8-01B.defaults.fetch_enabled' => false]);
        $this->app->instance(PublicAddressResolver::class, new class implements PublicAddressResolver
        {
            public function addresses(string $host): array
            {
                TestCase::fail('Disabled fetch must not resolve DNS.');
            }
        });
        Http::fake();

        try {
            $this->app->make(CrawlerClient::class)->fetch('https://example.com/');
            self::fail('Disabled fetch was accepted.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('не включена', $error->getMessage());
        }

        Http::assertNothingSent();
    }

    #[DataProvider('pinnedAddresses')]
    public function test_request_pins_dns_preserves_hostname_and_disables_redirects_proxy_and_connection_reuse(string $address, string $pin): void
    {
        $this->bindAddresses([$address]);
        $captured = [];
        Http::fake(function (Request $request, array $options) use (&$captured) {
            $captured = $options;
            self::assertSame('https://example.com/catalog', $request->url());
            self::assertTrue($request->hasHeader('Accept-Encoding', 'identity'));
            self::assertFalse($request->hasHeader('Authorization'));
            self::assertFalse($request->hasHeader('Cookie'));

            return Http::response('<h1>Каталог</h1>', 200, [
                'Content-Type' => 'text/html; charset=UTF-8', 'X-Robots-Tag' => 'noindex',
            ]);
        });

        $result = $this->app->make(CrawlerClient::class)->fetch('https://example.com/catalog#section', 100);

        self::assertFalse($captured['allow_redirects']);
        self::assertSame('', $captured['proxy']);
        self::assertTrue($captured['verify']);
        self::assertFalse($captured['cookies']);
        self::assertFalse($captured['decode_content']);
        self::assertEquals(48, $captured['timeout']);
        self::assertEquals(3, $captured['connect_timeout']);
        self::assertSame([$pin], $captured['curl'][CURLOPT_RESOLVE]);
        self::assertSame('', $captured['curl'][CURLOPT_PROXY]);
        self::assertFalse($captured['curl'][CURLOPT_FOLLOWLOCATION]);
        self::assertTrue($captured['curl'][CURLOPT_FRESH_CONNECT]);
        self::assertTrue($captured['curl'][CURLOPT_FORBID_REUSE]);
        self::assertSame(CURLPROTO_HTTP | CURLPROTO_HTTPS, $captured['curl'][CURLOPT_PROTOCOLS]);
        self::assertSame('https://example.com/catalog', $result['url']);
        self::assertSame('<h1>Каталог</h1>', $result['body']);
        self::assertSame('noindex', $result['x_robots_tag']);
        self::assertSame('text/html', $result['content_type']);
        self::assertNotEmpty($result['fetched_at']);
        Http::assertSentCount(1);
    }

    public static function pinnedAddresses(): array
    {
        return [
            ['93.184.216.34', 'example.com:443:93.184.216.34'],
            ['2606:4700:4700::1111', 'example.com:443:[2606:4700:4700::1111]'],
        ];
    }

    public function test_short_timeout_is_bounded_and_byte_limits_apply_during_transfer(): void
    {
        $captured = [];
        Http::fake(function (Request $request, array $options) use (&$captured) {
            $captured = $options;

            return Http::response('ok', 200, ['Content-Type' => 'text/plain']);
        });
        $this->app->make(CrawlerClient::class)->fetch('https://example.com/', .01);

        self::assertSame(.1, $captured['timeout']);
        self::assertSame(.1, $captured['connect_timeout']);
        foreach ([
            fn () => $captured['on_headers'](new Response(200, ['Content-Length' => (string) (CrawlerClient::MAX_BYTES + 1)])),
            fn () => $captured['progress'](0, CrawlerClient::MAX_BYTES + 1, 0, 0),
        ] as $tooLarge) {
            try {
                $tooLarge();
                self::fail('Streaming size guard did not reject the response.');
            } catch (RuntimeException $error) {
                self::assertStringContainsString('размер', $error->getMessage());
            }
        }
    }

    #[DataProvider('unsafeResponses')]
    public function test_unsafe_responses_are_rejected_even_when_http_is_faked(string $body, array $headers): void
    {
        Http::fake(['https://example.com/*' => Http::response($body, 200, $headers)]);

        $this->expectException(RuntimeException::class);
        $this->app->make(CrawlerClient::class)->fetch('https://example.com/');
    }

    public static function unsafeResponses(): array
    {
        return [
            'body too large' => [str_repeat('a', 600001), ['Content-Type' => 'text/plain']],
            'declared size too large' => ['ok', ['Content-Type' => 'text/plain', 'Content-Length' => '600001']],
            'gzip' => ['compressed', ['Content-Type' => 'text/html', 'Content-Encoding' => 'gzip']],
            'brotli' => ['compressed', ['Content-Type' => 'text/html', 'Content-Encoding' => 'br']],
            'binary MIME' => ['binary', ['Content-Type' => 'image/png']],
            'missing MIME' => ['untyped', []],
            'malformed UTF-8' => ["\xff\xfe", ['Content-Type' => 'text/plain']],
        ];
    }

    public function test_maximum_sized_text_and_empty_missing_optional_resource_are_distinct(): void
    {
        Http::fake([
            'https://example.com/robots.txt' => Http::response('', 404),
            'https://example.com/' => Http::response(str_repeat('a', 600000), 200, ['Content-Type' => 'text/plain']),
        ]);
        $crawler = $this->app->make(CrawlerClient::class);

        self::assertSame(600000, strlen($crawler->fetch('https://example.com/')['body']));
        $missing = $crawler->fetch('https://example.com/robots.txt');
        self::assertSame(404, $missing['status']);
        self::assertSame('', $missing['body']);
    }

    public function test_redirect_is_returned_as_one_hop_without_fetching_a_private_destination(): void
    {
        Http::fake([
            'https://example.com/' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
        ]);

        $result = $this->app->make(CrawlerClient::class)->fetch('https://example.com/');

        self::assertSame(302, $result['status']);
        self::assertSame('http://169.254.169.254/latest/meta-data/', $result['location']);
        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '169.254.169.254'));
    }

    public function test_changed_dns_answers_are_validated_before_each_new_request(): void
    {
        $resolver = new class implements PublicAddressResolver
        {
            public int $calls = 0;

            public function addresses(string $host): array
            {
                return ++$this->calls === 1 ? ['93.184.216.34'] : ['93.184.216.34', '127.0.0.1'];
            }
        };
        $this->app->instance(PublicAddressResolver::class, $resolver);
        Http::fake(['https://example.com/*' => Http::response('ok', 200, ['Content-Type' => 'text/plain'])]);
        $crawler = $this->app->make(CrawlerClient::class);
        $crawler->fetch('https://example.com/');

        try {
            $crawler->fetch('https://example.com/second');
            self::fail('Rebinding answer was accepted.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('сетевой адрес', $error->getMessage());
        }

        self::assertSame(2, $resolver->calls);
        Http::assertSentCount(1);
    }

    public function test_declared_windows_1251_is_decoded_before_evidence_extraction(): void
    {
        $html = '<h1>Доставка товара по России</h1>';
        Http::fake([
            'https://example.com/' => Http::response(mb_convert_encoding($html, 'Windows-1251', 'UTF-8'), 200, [
                'Content-Type' => 'text/html; charset="windows-1251"',
            ]),
        ]);

        $response = $this->app->make(CrawlerClient::class)->fetch('https://example.com/');

        self::assertSame($html, $response['body']);
        self::assertTrue(mb_check_encoding($response['body'], 'UTF-8'));
        self::assertSame('text/html', $response['content_type']);
    }

    public function test_unknown_declared_charset_is_rejected_without_guessing_text(): void
    {
        Http::fake([
            'https://example.com/' => Http::response('ambiguous text', 200, [
                'Content-Type' => 'text/html; charset=x-unsupported-encoding',
            ]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->app->make(CrawlerClient::class)->fetch('https://example.com/');
    }

    public function test_charset_conversion_cannot_expand_text_beyond_the_byte_limit(): void
    {
        $body = str_repeat("\xe0", 300001);
        self::assertLessThan(CrawlerClient::MAX_BYTES, strlen($body));
        Http::fake([
            'https://example.com/' => Http::response($body, 200, [
                'Content-Type' => 'text/plain; charset=windows-1251',
            ]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->app->make(CrawlerClient::class)->fetch('https://example.com/');
    }

    private function bindAddresses(array $answers): void
    {
        $this->app->instance(PublicAddressResolver::class, new class($answers) implements PublicAddressResolver
        {
            public function __construct(private array $answers) {}

            public function addresses(string $host): array
            {
                return $this->answers;
            }
        });
    }
}

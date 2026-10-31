<?php

namespace Tests\Unit\Vision\SiteAudit;

use App\Vision\SiteAudit\PublicAddressResolver;
use App\Vision\SiteAudit\PublicUrlPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PublicUrlPolicyTest extends TestCase
{
    #[DataProvider('invalidUrls')]
    public function test_invalid_urls_are_rejected_before_dns(string $url): void
    {
        $resolver = new class implements PublicAddressResolver
        {
            public function addresses(string $host): array
            {
                TestCase::fail('Invalid URLs must not reach DNS.');
            }
        };

        $this->expectException(InvalidArgumentException::class);
        (new PublicUrlPolicy($resolver))->target($url);
    }

    public static function invalidUrls(): array
    {
        return array_map(fn (string $url): array => [$url], [
            'file:///etc/passwd', 'ftp://example.com/data', '//example.com/',
            'https://user:password@example.com/', 'https://example.com:8443/',
            'http://127.0.0.1/', 'http://[::1]/', 'http://2130706433/',
            'http://127.0.0.1./', 'http://localhost/', 'https://site.local/',
            'https://site.localhost/', 'https://site.internal/', 'https://site.test/',
            'https://site.invalid/', 'https://hidden.onion/', 'https://site.internal./',
            "https://example.com/\r\nHost:localhost", 'https://example.com\\@localhost/',
            'https://example.com/'.str_repeat('x', 2048),
        ]);
    }

    #[DataProvider('nonPublicAnswers')]
    public function test_every_dns_answer_must_be_public(array $answers): void
    {
        $this->expectException(RuntimeException::class);
        $this->policy($answers)->target('https://example.com/');
    }

    public static function nonPublicAnswers(): array
    {
        return array_map(fn (array $answers): array => [$answers], [
            [], ['127.0.0.1'], ['10.0.0.1'], ['172.16.0.1'], ['192.168.1.1'],
            ['169.254.169.254'], ['100.64.0.1'], ['0.0.0.0'], ['192.0.2.1'],
            ['198.18.0.1'], ['198.51.100.1'], ['203.0.113.1'], ['224.0.0.1'],
            ['240.0.0.1'], ['::1'], ['::'], ['fc00::1'], ['fe80::1'],
            ['::ffff:127.0.0.1'], ['2001:db8::1'], ['2002:7f00:1::'], ['3fff::1'],
            ['93.184.216.34', '127.0.0.1'], ['93.184.216.34', 'fd00::1'],
            ['2606:4700:4700::1111', '192.168.1.1'], ['not-an-address'],
        ]);
    }

    public function test_public_ipv4_and_ipv6_keep_the_requested_origin_for_pinning(): void
    {
        $policy = $this->policy(['2606:4700:4700::1111', '93.184.216.34']);
        $target = $policy->target('https://EXAMPLE.COM/path?key=value#section');

        self::assertSame([
            'url' => 'https://example.com/path?key=value',
            'host' => 'example.com',
            'address' => '2606:4700:4700::1111',
            'port' => 443,
        ], $target);
        self::assertSame(80, $policy->target('http://example.com/path')['port']);
    }

    public function test_idn_is_converted_before_dns_and_curl_pinning(): void
    {
        $resolver = new class implements PublicAddressResolver
        {
            public array $hosts = [];

            public function addresses(string $host): array
            {
                $this->hosts[] = $host;

                return ['93.184.216.34'];
            }
        };

        $target = (new PublicUrlPolicy($resolver))->target('https://пример.рф/');

        self::assertSame(['xn--e1afmkfd.xn--p1ai'], $resolver->hosts);
        self::assertSame('https://xn--e1afmkfd.xn--p1ai/', $target['url']);
    }

    private function policy(array $answers): PublicUrlPolicy
    {
        return new PublicUrlPolicy(new class($answers) implements PublicAddressResolver
        {
            public function __construct(private array $answers) {}

            public function addresses(string $host): array
            {
                return $this->answers;
            }
        });
    }
}

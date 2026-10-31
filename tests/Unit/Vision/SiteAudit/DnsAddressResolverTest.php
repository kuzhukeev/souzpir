<?php

namespace Tests\Unit\Vision\SiteAudit;

use App\Vision\SiteAudit\DnsAddressResolver;
use App\Vision\SiteAudit\PublicUrlPolicy;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DnsAddressResolverTest extends TestCase
{
    public function test_cname_chain_is_resolved_and_all_addresses_are_returned_once(): void
    {
        $resolver = $this->resolver([
            'example.com' => [
                ['type' => 'CNAME', 'target' => 'CDN.EXAMPLE.COM.'],
                ['type' => 'A', 'ip' => '93.184.216.34'],
            ],
            'cdn.example.com' => [
                ['type' => 'A', 'ip' => '93.184.216.34'],
                ['type' => 'AAAA', 'ipv6' => '2606:4700:4700::1111'],
            ],
        ]);

        self::assertSame(['93.184.216.34', '2606:4700:4700::1111'], $resolver->addresses('example.com'));
        self::assertSame(['example.com', 'cdn.example.com'], $resolver->queries);
    }

    public function test_private_answer_behind_cname_invalidates_public_addresses_in_the_same_response(): void
    {
        $resolver = $this->resolver([
            'example.com' => [
                ['type' => 'A', 'ip' => '93.184.216.34'],
                ['type' => 'CNAME', 'target' => 'cdn.example.com'],
            ],
            'cdn.example.com' => [['type' => 'AAAA', 'ipv6' => 'fd00::1']],
        ]);

        $this->expectException(RuntimeException::class);
        (new PublicUrlPolicy($resolver))->target('https://example.com/');
    }

    public function test_cname_cannot_enter_a_private_hostname_even_when_a_public_address_already_exists(): void
    {
        $resolver = $this->resolver([
            'example.com' => [
                ['type' => 'A', 'ip' => '93.184.216.34'],
                ['type' => 'CNAME', 'target' => 'metadata.internal.'],
            ],
        ]);

        try {
            $resolver->addresses('example.com');
            self::fail('Private CNAME target was accepted.');
        } catch (RuntimeException) {
            self::assertSame(['example.com'], $resolver->queries);
        }
    }

    public function test_dns_cycle_is_rejected_without_using_partial_public_results(): void
    {
        $resolver = $this->resolver([
            'example.com' => [
                ['type' => 'A', 'ip' => '93.184.216.34'],
                ['type' => 'CNAME', 'target' => 'cdn.example.com'],
            ],
            'cdn.example.com' => [['type' => 'CNAME', 'target' => 'example.com']],
        ]);

        try {
            $resolver->addresses('example.com');
            self::fail('DNS cycle was accepted.');
        } catch (RuntimeException) {
            self::assertSame(['example.com', 'cdn.example.com'], $resolver->queries);
        }
    }

    public function test_dns_lookup_budget_prevents_unbounded_alias_walk(): void
    {
        $records = [];
        for ($i = 0; $i < 10; $i++) {
            $records['alias'.$i.'.example.com'] = [['type' => 'CNAME', 'target' => 'alias'.($i + 1).'.example.com']];
        }
        $resolver = $this->resolver($records);

        try {
            $resolver->addresses('alias0.example.com');
            self::fail('Unbounded DNS alias chain was accepted.');
        } catch (RuntimeException) {
            self::assertCount(8, $resolver->queries);
            self::assertNotContains('alias8.example.com', $resolver->queries);
        }
    }

    private function resolver(array $records): DnsAddressResolver
    {
        return new class($records) extends DnsAddressResolver
        {
            public array $queries = [];

            public function __construct(private array $answers) {}

            protected function records(string $host): array
            {
                $this->queries[] = $host;
                TestCase::assertArrayHasKey($host, $this->answers, 'Unexpected DNS lookup must stay offline.');

                return $this->answers[$host];
            }
        };
    }
}

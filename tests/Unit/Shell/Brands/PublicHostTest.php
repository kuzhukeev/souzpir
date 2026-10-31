<?php

namespace Tests\Unit\Shell\Brands;

use App\Shell\Brands\Network\PublicHost;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PublicHostTest extends TestCase
{
    #[DataProvider('globalAddresses')]
    public function test_accepts_global_ipv4_and_ipv6_addresses(string $address): void
    {
        self::assertTrue(PublicHost::isPublicAddress($address), $address);
    }

    public static function globalAddresses(): array
    {
        return [
            ['93.184.216.34'], ['1.1.1.1'], ['8.8.8.8'],
            ['2606:4700::1111'], ['2606:4700:4700::1111'], ['2001:4860:4860::8888'],
        ];
    }

    #[DataProvider('unsafeAddresses')]
    public function test_rejects_local_documentation_and_transition_addresses(string $address): void
    {
        self::assertFalse(PublicHost::isPublicAddress($address), $address);
    }

    public static function unsafeAddresses(): array
    {
        return [
            'IPv4 loopback' => ['127.0.0.1'],
            'IPv4 unspecified' => ['0.0.0.0'],
            'RFC1918 A' => ['10.0.0.1'],
            'RFC1918 B' => ['172.16.0.1'],
            'RFC1918 C' => ['192.168.255.254'],
            'link local metadata' => ['169.254.169.254'],
            'CGNAT first' => ['100.64.0.0'],
            'CGNAT last' => ['100.127.255.255'],
            'protocol assignment' => ['192.0.0.8'],
            'deprecated relay first' => ['192.88.99.0'],
            'deprecated relay last' => ['192.88.99.255'],
            'IPv4 documentation one' => ['192.0.2.1'],
            'IPv4 documentation two' => ['198.51.100.1'],
            'IPv4 documentation three' => ['203.0.113.1'],
            'benchmark first' => ['198.18.0.0'],
            'benchmark last' => ['198.19.255.255'],
            'IPv4 multicast' => ['224.0.0.1'],
            'IPv4 reserved' => ['240.0.0.1'],
            'IPv4 broadcast' => ['255.255.255.255'],
            'IPv6 unspecified' => ['::'],
            'IPv6 loopback' => ['::1'],
            'IPv4 compatible loopback' => ['::127.0.0.1'],
            'IPv4 mapped loopback' => ['::ffff:127.0.0.1'],
            'IPv4 mapped private' => ['::ffff:10.0.0.1'],
            'IPv4 mapped public still special' => ['::ffff:93.184.216.34'],
            'well known NAT64 loopback' => ['64:ff9b::7f00:1'],
            'local NAT64 private' => ['64:ff9b:1::a00:1'],
            'discard only' => ['100::1'],
            'Teredo' => ['2001::1'],
            'IPv6 documentation first' => ['2001:db8::'],
            'IPv6 documentation last' => ['2001:db8:ffff:ffff:ffff:ffff:ffff:ffff'],
            '6to4 loopback' => ['2002:7f00:1::'],
            '6to4 private' => ['2002:a00:1::'],
            'additional documentation first' => ['3fff::'],
            'additional documentation last' => ['3fff:fff:ffff:ffff:ffff:ffff:ffff:ffff'],
            'outside global unicast' => ['4000::1'],
            'ULA first' => ['fc00::1'],
            'ULA last' => ['fdff:ffff:ffff:ffff:ffff:ffff:ffff:ffff'],
            'IPv6 link local' => ['fe80::1'],
            'deprecated site local first' => ['fec0::'],
            'deprecated site local interior' => ['fedf:ffff::1'],
            'deprecated site local last' => ['feff:ffff:ffff:ffff:ffff:ffff:ffff:ffff'],
            'IPv6 multicast' => ['ff02::1'],
            'IPv6 zone identifier' => ['fe80::1%eth0'],
            'not an address' => ['example.com'],
            'empty input' => [''],
        ];
    }

    public function test_normalizes_case_whitespace_trailing_dot_and_idn_without_dns(): void
    {
        self::assertSame('shop.example.com', PublicHost::normalize('  SHOP.Example.COM.  '));
        self::assertSame('xn--e1afmkfd.xn--p1ai', PublicHost::normalize('пример.рф'));
    }

    #[DataProvider('unsafeHosts')]
    public function test_rejects_host_syntax_that_could_change_the_destination(string $host): void
    {
        $this->expectException(InvalidArgumentException::class);

        PublicHost::normalize($host);
    }

    public static function unsafeHosts(): array
    {
        return [
            ['https://example.com'], ['user@example.com'], ['example.com:443'], ['example.com/path'],
            ['example.com?next=127.0.0.1'], ['example.com#fragment'], ['*.example.com'],
            ['127.0.0.1'], ['[::1]'], ['localhost'], ['a.localhost'], ['a.local'], ['a.internal'],
            ['a.invalid'], ['a.test'], ['a.example'], ['a.onion'], ['2130706433'], ['0x7f000001'],
            ['0177.0.0.1'], ['-bad.example.com'], ["example.com\r\nHost: 127.0.0.1"],
        ];
    }
}

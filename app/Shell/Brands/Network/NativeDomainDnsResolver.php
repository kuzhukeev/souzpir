<?php

namespace App\Shell\Brands\Network;

use App\Shell\Brands\Contracts\DomainDnsResolver;
use RuntimeException;

final class NativeDomainDnsResolver implements DomainDnsResolver
{
    public function addresses(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if ($records === false) {
            throw new RuntimeException('dns_unavailable');
        }

        return array_values(array_unique(array_filter(array_map(fn ($record) => $record['ip'] ?? $record['ipv6'] ?? null, $records))));
    }

    public function txtRecords(string $host): array
    {
        $records = @dns_get_record($host, DNS_TXT);
        if ($records === false) {
            throw new RuntimeException('dns_unavailable');
        }

        return array_values(array_map(fn ($record) => isset($record['entries']) ? implode('', $record['entries']) : ($record['txt'] ?? ''), $records));
    }
}

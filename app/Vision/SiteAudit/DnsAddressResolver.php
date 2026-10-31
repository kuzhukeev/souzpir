<?php

namespace App\Vision\SiteAudit;

use RuntimeException;

class DnsAddressResolver implements PublicAddressResolver
{
    public function addresses(string $host): array
    {
        $pending = [$host];
        $seen = [];
        $addresses = [];
        while ($pending !== []) {
            $name = strtolower(rtrim(array_shift($pending), '.'));
            if (isset($seen[$name])) {
                throw new RuntimeException('Циклическая цепочка DNS не разрешена.');
            }
            if (count($seen) >= 8 || ! filter_var($name, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
                || ! str_contains($name, '.') || preg_match('/(?:^|\.)(?:localhost|local|internal|invalid|test|onion)$/i', $name)) {
                throw new RuntimeException('Цепочка DNS не разрешена для безопасного обхода.');
            }
            $seen[$name] = true;
            foreach ($this->records($name) as $record) {
                if (isset($record['ip']) || isset($record['ipv6'])) {
                    $addresses[] = $record['ip'] ?? $record['ipv6'];
                }
                if (($record['type'] ?? '') === 'CNAME' && isset($record['target'])) {
                    $pending[] = $record['target'];
                }
            }
        }

        return array_values(array_unique($addresses));
    }

    /** Kept separate so DNS behavior can be tested without querying a resolver. */
    protected function records(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA | DNS_CNAME);
        if ($records === false) {
            throw new RuntimeException('Не удалось безопасно разрешить адрес сайта.');
        }

        return $records;
    }
}

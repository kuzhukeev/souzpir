<?php

namespace App\Shell\Brands\Contracts;

interface DomainDnsResolver
{
    /** @return list<string> All returned A/AAAA addresses, including non-public addresses. */
    public function addresses(string $host): array;

    /** @return list<string> TXT values, with chunks of each record joined. */
    public function txtRecords(string $host): array;
}

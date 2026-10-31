<?php

namespace App\Vision\SiteAudit;

interface PublicAddressResolver
{
    /** @return list<string> All A/AAAA answers, not just the first address. */
    public function addresses(string $host): array;
}

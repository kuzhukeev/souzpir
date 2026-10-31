<?php

use App\Vision\SiteAudit\DnsAddressResolver;
use App\Vision\SiteAudit\PublicAddressResolver;

return [
    'bindings' => [PublicAddressResolver::class => DnsAddressResolver::class],
    'defaults' => ['crawl_pages' => 5, 'fetch_enabled' => env('SITE_AUDIT_FETCH_ENABLED', false)],
];

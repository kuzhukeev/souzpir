<?php

use App\Shell\Brands\BrandAppearance;
use App\Shell\Brands\Contracts\DomainDnsResolver;
use App\Shell\Brands\Network\NativeDomainDnsResolver;

return [
    'defaults' => ['external_checks' => env('BRAND_EXTERNAL_CHECKS_ENABLED', false) === true],
    'bindings' => [DomainDnsResolver::class => NativeDomainDnsResolver::class],
    'shared_props' => [BrandAppearance::class],
];

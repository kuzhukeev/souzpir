<?php

use App\Shell\Brands\BrandAppearance;
use App\Shell\Brands\Contracts\DomainDnsResolver;
use App\Shell\Brands\Network\NativeDomainDnsResolver;

return [
    'bindings' => [DomainDnsResolver::class => NativeDomainDnsResolver::class],
    'shared_props' => [BrandAppearance::class],
];

<?php

use App\Models\Brand;
use App\Models\Membership;
use App\Shell\Permissions\PermissionEvaluator;
use App\Shell\Permissions\PermissionSharedProps;
use App\Shell\Permissions\Policies\BrandPolicy;
use App\Shell\Permissions\Policies\MembershipPolicy;

$matrix = require __DIR__.'/../permissions.php';

return [
    'permissions' => array_fill_keys(array_keys($matrix['abilities']), PermissionEvaluator::class),
    'policies' => [Brand::class => BrandPolicy::class, Membership::class => MembershipPolicy::class],
    'shared_props' => [PermissionSharedProps::class],
];

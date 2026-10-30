<?php

namespace App\Shell\Permissions\Policies;

use App\Models\Brand;
use App\Models\User;
use App\Shell\Permissions\PermissionEvaluator;

final class BrandPolicy
{
    public function viewAny(User $user): bool
    {
        return app(PermissionEvaluator::class)->allows($user, 'brand.read');
    }

    public function create(User $user): bool
    {
        return app(PermissionEvaluator::class)->allows($user, 'brand.write');
    }

    public function view(User $user, Brand $brand): bool
    {
        return app(PermissionEvaluator::class)->allows($user, 'brand.read', $brand);
    }

    public function update(User $user, Brand $brand): bool
    {
        return app(PermissionEvaluator::class)->allows($user, 'brand.write', $brand);
    }
}

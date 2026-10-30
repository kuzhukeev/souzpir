<?php

namespace App\Providers\Features;

use Illuminate\Contracts\Auth\Authenticatable;

interface PermissionEvaluator
{
    public function allows(Authenticatable $user, string $ability, mixed ...$arguments): bool;
}

<?php

namespace Tests\Feature\Smoke\Fixtures;

use App\Providers\Features\PermissionEvaluator;
use Illuminate\Contracts\Auth\Authenticatable;

class FixturePermission implements PermissionEvaluator
{
    public function allows(Authenticatable $user, string $ability, mixed ...$arguments): bool
    {
        return $ability === 'fixture.read' && $user->getAuthIdentifier() === 7 && $arguments === ['allowed'];
    }
}

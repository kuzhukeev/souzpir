<?php

namespace App\Shell\Permissions;

use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Providers\Features\PermissionEvaluator as PermissionEvaluatorContract;
use App\Seller\Conversations\AssignmentPolicy;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/** Fail closed unless actor, active membership and selected brand still match. */
final class PermissionEvaluator implements PermissionEvaluatorContract
{
    public function allows(Authenticatable $user, string $ability, mixed ...$arguments): bool
    {
        AssignmentPolicy::assertConfiguration();
        // Dotted ability names are literal array keys, not nested config paths.
        $roles = config('permissions.abilities', [])[$ability] ?? null;
        if (! is_array($roles) || ($role = $this->currentRole($user)) === null) {
            return false;
        }
        $context = app(TenantContext::class);
        foreach ($arguments as $argument) {
            if (! $argument instanceof Model) {
                continue;
            }
            if (! $argument->exists) {
                return false;
            }
            if ($argument instanceof Organization) {
                if ($argument->getKey() !== $context->organizationId) {
                    return false;
                }

                continue;
            }
            $attributes = $argument->getAttributes();
            if (! array_key_exists('organization_id', $attributes) || $argument->organization_id !== $context->organizationId ||
                (method_exists($argument, 'tenantUsesBrand') && $argument->tenantUsesBrand() && ! array_key_exists('brand_id', $attributes)) ||
                (array_key_exists('brand_id', $attributes) && $argument->brand_id !== $context->brandId) ||
                ($argument instanceof Brand && $argument->getKey() !== $context->brandId)) {
                return false;
            }
        }

        return in_array($role, $roles, true);
    }

    /** A single membership/brand check per frontend projection, not per permission. */
    public function all(Authenticatable $user): array
    {
        AssignmentPolicy::assertConfiguration();
        $role = $this->currentRole($user);
        $result = [];
        foreach (config('permissions.abilities', []) as $ability => $roles) {
            $result[$ability] = $role !== null && is_array($roles) && in_array($role, $roles, true);
        }

        return $result;
    }

    private function currentRole(Authenticatable $user): ?string
    {
        $context = app(TenantContext::class);
        if ($context->organizationId === null || $context->brandId === null ||
            $context->membershipId === null || $context->actorId === null ||
            (string) $user->getAuthIdentifier() !== $context->actorId) {
            return null;
        }

        $membership = Membership::withoutGlobalScopes()->whereKey($context->membershipId)
            ->where('organization_id', $context->organizationId)->where('user_id', $context->actorId)
            ->where('status', 'active')->first();
        if (! $membership || ! $membership->permitsBrand($context->brandId) ||
            ! Brand::withoutGlobalScopes()->whereKey($context->brandId)->where('organization_id', $context->organizationId)->where('status', 'active')
                ->whereHas('organization', fn ($query) => $query->where('status', 'active'))->exists()) {
            return null;
        }

        return $this->role($membership);
    }

    public function role(Membership $membership): ?string
    {
        if ($membership->status !== 'active') {
            return null;
        }
        $manager = $membership->settings['sales_manager'] ?? false;
        if (! is_bool($manager) || ($manager && $membership->role !== 'member')) {
            return null;
        }

        return match ($membership->role) {
            'owner', 'admin', 'viewer' => $membership->role,
            'member' => $manager ? 'manager' : 'editor',
            default => null,
        };
    }
}

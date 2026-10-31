<?php

namespace App\Shell\Team;

use App\Providers\Features\FeatureRegistry;
use App\Shell\Permissions\PermissionEvaluator;
use LogicException;

/**
 * A read-only explanation of registered, role-based abilities. It is not an
 * authorization decision: active tenant/brand and model policies still apply.
 */
final class RoleMatrix
{
    public function __construct(private readonly FeatureRegistry $features) {}

    public function project(): array
    {
        $labels = config('permissions.labels');
        $abilities = config('permissions.abilities');
        if (! is_array($labels) || ! is_array($abilities)) {
            throw new LogicException('Role permission configuration is unavailable.');
        }

        // The two UI labels share one stored membership role; the flag is not
        // a distinct role or a grant of additional permissions.
        $definitions = [
            'owner' => ['membership_role' => 'owner', 'sales_manager' => false],
            'admin' => ['membership_role' => 'admin', 'sales_manager' => false],
            'editor' => ['membership_role' => 'member', 'sales_manager' => false],
            'manager' => ['membership_role' => 'member', 'sales_manager' => true],
            'viewer' => ['membership_role' => 'viewer', 'sales_manager' => false],
        ];
        $roles = [];
        foreach ($definitions as $key => $definition) {
            $label = $labels[$key] ?? null;
            if (! is_string($label) || trim($label) === '') {
                throw new LogicException("Missing label for role {$key}.");
            }
            $roles[] = ['key' => $key, 'label' => $label, ...$definition];
        }

        $registered = $this->features->permissions();
        $rows = [];
        foreach ($abilities as $ability => $allowedRoles) {
            // Do not claim an unregistered permission, or one evaluated by a
            // different feature, merely because a similarly named config exists.
            if (! is_string($ability) || ($registered[$ability] ?? null) !== PermissionEvaluator::class) {
                continue;
            }
            $values = [];
            foreach ($definitions as $role => $_) {
                $values[$role] = is_array($allowedRoles) && in_array($role, $allowedRoles, true);
            }
            $rows[] = ['ability' => $ability, 'roles' => $values];
        }

        return [
            'roles' => $roles,
            'abilities' => $rows,
            'limitations' => [
                'requires_active_membership_and_brand' => true,
                'model_policies_apply' => true,
                'only_role_based_abilities_shown' => true,
                'custom_roles_available' => false,
                'per_module_grants_available' => false,
            ],
        ];
    }
}

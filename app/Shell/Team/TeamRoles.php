<?php

namespace App\Shell\Team;

use Illuminate\Validation\ValidationException;

final class TeamRoles
{
    public const EDITABLE = ['admin', 'editor', 'manager', 'viewer'];

    public static function name(object $subject): string
    {
        return $subject->role === 'member' ? (($subject->settings['sales_manager'] ?? false) ? 'manager' : 'editor') : $subject->role;
    }

    public static function grant(string $role): array
    {
        if (! in_array($role, self::EDITABLE, true)) {
            throw ValidationException::withMessages(['role' => __('Shell/Team/messages.invalid_role')]);
        }

        return ['role' => in_array($role, ['editor', 'manager'], true) ? 'member' : $role, 'sales_manager' => $role === 'manager'];
    }

    public static function allBrands(object $subject): bool
    {
        return in_array($subject->role, ['owner', 'admin'], true) && $subject->brand_ids === [];
    }

    public static function matrix(): array
    {
        $roles = [];
        foreach (config('permissions.labels') as $key => $label) {
            $roles[] = ['id' => $key, 'name' => $label, 'description' => __('Shell/Team/messages.role_'.$key)];
        }
        $permissions = [];
        foreach (__('Shell/Team/messages.abilities') as $key => $label) {
            $permissions[] = ['key' => $key, 'label' => $label, 'roles' => config('permissions.abilities')[$key] ?? []];
        }

        return ['roles' => $roles, 'permissions' => $permissions];
    }
}

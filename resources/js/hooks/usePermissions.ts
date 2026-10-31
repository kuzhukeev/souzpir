import { usePermissions as useFeaturePermissions } from '@/features/Shell/Permissions/usePermissions';
import type { PermissionAbility } from '@/types/generated/permissions';

/** A presentation hint, never a substitute for server-side Gate and policy checks. */
export function usePermissions() {
  const { permissions } = useFeaturePermissions();

  return {
    can: (ability: PermissionAbility): boolean => permissions[ability] === true,
    permissions,
  };
}

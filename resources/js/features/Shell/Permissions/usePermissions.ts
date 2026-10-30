import { usePage } from '@inertiajs/react';

/** UI hint only. Server-side Gates/policies must authorize every operation. */
export function usePermissions() {
  const permissions = usePage<{ can?: Record<string, boolean> }>().props.can ?? {};

  return {
    can: (ability: string): boolean => permissions[ability] === true,
    permissions,
  };
}

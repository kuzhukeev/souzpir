import { QueryClient, type QueryKey } from '@tanstack/react-query';

export type BrandScope = Readonly<{ organizationId: string; brandId: string }>;

// All tenant-owned keys begin with the complete organization + brand identity.
export function brandQueryKey(scope: BrandScope, ...parts: readonly unknown[]): QueryKey {
  if (!scope.organizationId.trim() || !scope.brandId.trim()) {
    throw new Error('A complete organization and brand scope is required.');
  }
  return ['brand', scope.organizationId, scope.brandId, ...parts] as const;
}

export function createQueryClient(): QueryClient {
  // Construct per browser app / per SSR request, never share a server singleton.
  return new QueryClient({
    defaultOptions: { queries: { staleTime: 0, gcTime: 5 * 60_000, refetchOnWindowFocus: true } },
  });
}

export async function clearBrandQueries(client: QueryClient, scope: BrandScope): Promise<void> {
  const queryKey = brandQueryKey(scope);
  await client.cancelQueries({ queryKey });
  client.removeQueries({ queryKey });
}

/** Drop cached private data when navigation logs out or changes authenticated user. */
export function sessionCacheSync(client: QueryClient, initialProps: Record<string, unknown>) {
  const principal = (props: Record<string, unknown>): string | null => {
    const auth = props.auth;
    if (!auth || typeof auth !== 'object' || !('user' in auth)) return null;
    const user = auth.user;
    if (!user || typeof user !== 'object' || !('id' in user) || typeof user.id !== 'string')
      return null;
    return user.id;
  };
  let current = principal(initialProps);
  return (props: Record<string, unknown>) => {
    const next = principal(props);
    if (next === null || next !== current) client.clear();
    current = next;
  };
}

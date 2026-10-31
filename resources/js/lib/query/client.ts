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

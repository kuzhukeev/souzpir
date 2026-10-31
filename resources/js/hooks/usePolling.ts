import { useQuery, type QueryFunctionContext, type QueryKey } from '@tanstack/react-query';
import { brandQueryKey, type BrandScope } from '@/lib/query/client';

export const POLLING_INTERVALS = {
  dialogue: 5_000,
  dialogueList: 20_000,
  notifications: 60_000,
} as const;

type PollingOptions<T> = {
  scope: BrandScope | null;
  key: readonly unknown[];
  fetch: (context: QueryFunctionContext<QueryKey>) => Promise<T>;
  interval: number;
  enabled?: boolean;
};

/** Query owns the timer, cancellation, focus handling and cleanup on unmount. */
export function usePolling<T>({ scope, key, fetch, interval, enabled = true }: PollingOptions<T>) {
  if (!Number.isFinite(interval) || interval < 1)
    throw new Error('Polling interval must be positive.');
  return useQuery({
    queryKey: scope ? brandQueryKey(scope, ...key) : ['brand-unavailable'],
    queryFn: fetch,
    enabled: enabled && scope !== null,
    refetchInterval: enabled && scope !== null ? interval : false,
    refetchIntervalInBackground: false,
    staleTime: 0,
  });
}

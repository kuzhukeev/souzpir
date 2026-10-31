import { renderHook } from '@testing-library/react';
import { QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { brandQueryKey, createQueryClient } from '@/lib/query/client';
import { useBrandHttp } from '@/lib/http/useBrandHttp';

vi.mock('@inertiajs/react', () => ({
  useHttp: () => ({
    post: async (
      _url: string,
      options: { onSuccess: (response: unknown, http: unknown) => void },
    ) => {
      options.onSuccess({ ok: true }, {});
      return { ok: true };
    },
    put: vi.fn(),
    patch: vi.fn(),
    delete: vi.fn(),
    get: vi.fn(),
    submit: vi.fn(),
  }),
}));

describe('brand HTTP mutations', () => {
  it('invalidates only the active brand after successful mutation', async () => {
    const client = createQueryClient();
    const active = { organizationId: 'org', brandId: 'a' };
    const other = { organizationId: 'org', brandId: 'b' };
    client.setQueryData(brandQueryKey(active, 'items'), 1);
    client.setQueryData(brandQueryKey(other, 'items'), 2);
    const wrapper = ({ children }: { children: ReactNode }) => (
      <QueryClientProvider client={client}>{children}</QueryClientProvider>
    );
    const { result } = renderHook(() => useBrandHttp(active, { text: '' }), { wrapper });
    const onSuccess = vi.fn();
    await result.current.post('/items', { onSuccess });
    expect(onSuccess).toHaveBeenCalledTimes(1);
    expect(client.getQueryState(brandQueryKey(active, 'items'))?.isInvalidated).toBe(true);
    expect(client.getQueryState(brandQueryKey(other, 'items'))?.isInvalidated).toBe(false);
    expect(result.current).not.toHaveProperty('submit');
  });
});

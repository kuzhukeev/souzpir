import { act, renderHook } from '@testing-library/react';
import { http } from '@inertiajs/core';
import { QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { brandQueryKey, createQueryClient } from '@/lib/query/client';
import { useBrandHttp } from '@/lib/http/useBrandHttp';

const originalClient = http.getClient();
afterEach(() => http.setClient(originalClient));

describe('brand HTTP mutations with actual Inertia useHttp', () => {
  it.each(['post', 'put', 'patch', 'delete'] as const)(
    '%s invalidates only the active brand on success and does not expose fluent bypasses',
    async (method) => {
      const request = vi.fn().mockResolvedValue({ status: 200, data: '{"ok":true}', headers: {} });
      http.setClient({ request });
      const client = createQueryClient();
      const active = { organizationId: 'org', brandId: 'a' };
      const other = { organizationId: 'org', brandId: 'b' };
      client.setQueryData(brandQueryKey(active, 'items'), 1);
      client.setQueryData(brandQueryKey(other, 'items'), 2);
      const wrapper = ({ children }: { children: ReactNode }) => (
        <QueryClientProvider client={client}>{children}</QueryClientProvider>
      );
      const { result, unmount } = renderHook(() => useBrandHttp(active, { text: '' }), { wrapper });
      const onSuccess = vi.fn();
      for (const name of ['submit', 'dontRemember', 'optimistic', 'withAllErrors', 'withPrecognition']) {
        expect(result.current).not.toHaveProperty(name);
      }
      await act(async () => { await result.current[method]('/items', { onSuccess }); });
      expect(request).toHaveBeenCalledOnce();
      expect(onSuccess).toHaveBeenCalledTimes(1);
      expect(client.getQueryState(brandQueryKey(active, 'items'))?.isInvalidated).toBe(true);
      expect(client.getQueryState(brandQueryKey(other, 'items'))?.isInvalidated).toBe(false);
      unmount();
      client.clear();
    },
  );

  it('does not invalidate after validation error or cancellation', async () => {
    const request = vi.fn().mockResolvedValueOnce({ status: 422, data: '{"errors":{"text":"invalid"}}', headers: {} });
    http.setClient({ request });
    const client = createQueryClient();
    const scope = { organizationId: 'org', brandId: 'a' };
    client.setQueryData(brandQueryKey(scope, 'items'), 1);
    const wrapper = ({ children }: { children: ReactNode }) => (
      <QueryClientProvider client={client}>{children}</QueryClientProvider>
    );
    const { result, unmount } = renderHook(() => useBrandHttp(scope, { text: '' }), { wrapper });
    await act(async () => { await result.current.post('/items'); });
    expect(client.getQueryState(brandQueryKey(scope, 'items'))?.isInvalidated).toBe(false);
    request.mockImplementationOnce(({ signal }) => new Promise((_resolve, reject) => {
      signal.addEventListener('abort', () => reject(new DOMException('Cancelled', 'AbortError')));
    }));
    await act(async () => {
      const pending = result.current.post('/items').catch(() => undefined);
      result.current.cancel();
      await pending;
    });
    expect(client.getQueryState(brandQueryKey(scope, 'items'))?.isInvalidated).toBe(false);
    unmount();
    client.clear();
  });
});

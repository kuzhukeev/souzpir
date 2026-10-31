import { act, renderHook, waitFor } from '@testing-library/react';
import { QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import {
  brandQueryKey,
  clearBrandQueries,
  createQueryClient,
  sessionCacheSync,
} from '@/lib/query/client';
import { usePolling } from '@/hooks/usePolling';

const a = { organizationId: 'org-a', brandId: 'brand-a' };
const b = { organizationId: 'org-a', brandId: 'brand-b' };
const other = { organizationId: 'org-b', brandId: 'brand-a' };

describe('brand-scoped live data', () => {
  it('rejects incomplete scope and removes only the previous organization and brand', async () => {
    const client = createQueryClient();
    expect(() => brandQueryKey({ ...a, brandId: '' })).toThrow();
    client.setQueryData(brandQueryKey(a, 'dialogue', '1'), 'private A');
    client.setQueryData(brandQueryKey(b, 'dialogue', '1'), 'private B');
    client.setQueryData(brandQueryKey(other, 'dialogue', '1'), 'private other');
    await clearBrandQueries(client, a);
    expect(client.getQueryData(brandQueryKey(a, 'dialogue', '1'))).toBeUndefined();
    expect(client.getQueryData(brandQueryKey(b, 'dialogue', '1'))).toBe('private B');
    expect(client.getQueryData(brandQueryKey(other, 'dialogue', '1'))).toBe('private other');
  });

  it('clears private cache on logout or a different authenticated account', () => {
    const client = createQueryClient();
    const sync = sessionCacheSync(client, { auth: { user: { id: 'user-a' } } });
    client.setQueryData(brandQueryKey(a, 'private'), 'secret A');
    sync({ auth: { user: { id: 'user-a' } } });
    expect(client.getQueryData(brandQueryKey(a, 'private'))).toBe('secret A');
    sync({ auth: { user: null } });
    expect(client.getQueryData(brandQueryKey(a, 'private'))).toBeUndefined();
    client.setQueryData(brandQueryKey(a, 'private'), 'secret B');
    sync({ auth: { user: { id: 'user-b' } } });
    expect(client.getQueryData(brandQueryKey(a, 'private'))).toBeUndefined();
  });

  it('cancels an in-flight request before dropping its old-brand result', async () => {
    const client = createQueryClient();
    let aborted = false;
    const pending = client.fetchQuery({
      queryKey: brandQueryKey(a, 'slow'),
      queryFn: ({ signal }) =>
        new Promise<string>((resolve, reject) => {
          signal.addEventListener('abort', () => {
            aborted = true;
            reject(new Error('aborted'));
          });
          void resolve;
        }),
    });
    const handled = pending.catch(() => undefined);
    await clearBrandQueries(client, a);
    await handled;
    expect(aborted).toBe(true);
    expect(client.getQueryData(brandQueryKey(a, 'slow'))).toBeUndefined();
  });

  it('never shows the previous brand while a new brand request is pending', async () => {
    const client = createQueryClient();
    let finish: ((value: string) => void) | undefined;
    const fetch = vi.fn(({ queryKey }: { queryKey: readonly unknown[] }) =>
      queryKey[2] === 'brand-a'
        ? Promise.resolve('private A')
        : new Promise<string>((resolve) => {
            finish = resolve;
          }),
    );
    const wrapper = ({ children }: { children: ReactNode }) => (
      <QueryClientProvider client={client}>{children}</QueryClientProvider>
    );
    const hook = renderHook(
      ({ scope }) => usePolling({ scope, key: ['dialogue'], fetch, interval: 60_000 }),
      {
        wrapper,
        initialProps: { scope: a },
      },
    );
    await waitFor(() => expect(hook.result.current.data).toBe('private A'));
    hook.rerender({ scope: b });
    expect(hook.result.current.data).toBeUndefined();
    await act(async () => {
      finish?.('private B');
    });
    await waitFor(() => expect(hook.result.current.data).toBe('private B'));
    hook.unmount();
    client.clear();
  });

  it('does not poll without a brand and cleans up on disable and unmount', async () => {
    const client = createQueryClient();
    const fetch = vi.fn().mockResolvedValue('ok');
    const wrapper = ({ children }: { children: ReactNode }) => (
      <QueryClientProvider client={client}>{children}</QueryClientProvider>
    );
    const hook = renderHook(
      ({ scope, enabled }: { scope: typeof a | null; enabled: boolean }) =>
        usePolling({ scope, key: ['dialogues'], fetch, interval: 50, enabled }),
      { wrapper, initialProps: { scope: null as typeof a | null, enabled: true } },
    );
    expect(fetch).not.toHaveBeenCalled();
    hook.rerender({ scope: a, enabled: true });
    await waitFor(() => expect(fetch).toHaveBeenCalledTimes(1));
    await waitFor(() => expect(fetch.mock.calls.length).toBeGreaterThan(1), { timeout: 1000 });
    hook.rerender({ scope: a, enabled: false });
    const afterDisable = fetch.mock.calls.length;
    await act(() => new Promise((resolve) => setTimeout(resolve, 120)));
    expect(fetch).toHaveBeenCalledTimes(afterDisable);
    hook.unmount();
    client.clear();
  });

  it('detaches a disabled observer and aborts its request only when no observer remains', async () => {
    const client = createQueryClient();
    let signal: AbortSignal | undefined;
    const fetch = vi.fn(({ signal: current }: { signal: AbortSignal }) => {
      signal = current;
      return new Promise<string>(() => undefined);
    });
    const wrapper = ({ children }: { children: ReactNode }) => (
      <QueryClientProvider client={client}>{children}</QueryClientProvider>
    );
    const first = renderHook(
      ({ enabled }) => usePolling({ scope: a, key: ['pending'], fetch, interval: 60_000, enabled }),
      { wrapper, initialProps: { enabled: true } },
    );
    const second = renderHook(
      ({ enabled }) => usePolling({ scope: a, key: ['pending'], fetch, interval: 60_000, enabled }),
      { wrapper, initialProps: { enabled: true } },
    );
    await waitFor(() => expect(signal).toBeDefined());
    first.rerender({ enabled: false });
    expect(signal?.aborted).toBe(false);
    expect(first.result.current.data).toBeUndefined();
    second.rerender({ enabled: false });
    await waitFor(() => expect(signal?.aborted).toBe(true));
    first.unmount();
    second.unmount();
    client.clear();
  });

  it('switching brands detaches only the switching observer', async () => {
    const client = createQueryClient();
    const signals: Record<string, AbortSignal> = {};
    const fetch = vi.fn(
      ({ queryKey, signal }: { queryKey: readonly unknown[]; signal: AbortSignal }) => {
        signals[String(queryKey[2])] = signal;
        return new Promise<string>(() => undefined);
      },
    );
    const wrapper = ({ children }: { children: ReactNode }) => (
      <QueryClientProvider client={client}>{children}</QueryClientProvider>
    );
    const switching = renderHook(
      ({ scope }) => usePolling({ scope, key: ['pending'], fetch, interval: 60_000 }),
      { wrapper, initialProps: { scope: a } },
    );
    const staying = renderHook(
      () => usePolling({ scope: a, key: ['pending'], fetch, interval: 60_000 }),
      { wrapper },
    );
    await waitFor(() => expect(signals['brand-a']).toBeDefined());
    switching.rerender({ scope: b });
    await waitFor(() => expect(signals['brand-b']).toBeDefined());
    expect(signals['brand-a'].aborted).toBe(false);
    expect(switching.result.current.data).toBeUndefined();
    staying.unmount();
    await waitFor(() => expect(signals['brand-a'].aborted).toBe(true));
    switching.unmount();
    client.clear();
  });
});

import { act, cleanup, renderHook, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import {
  beginProfileContextChange,
  finishProfileContextChange,
} from '@/features/Shell/Profile/contextFence';
import { useRecovery } from '@/features/Shell/Billing/AiRecovery/useRecovery';

const first = '01J00000000000000000000001';
const second = '01J00000000000000000000002';
const brand = '01J00000000000000000000003';
const revision = 'a'.repeat(64);
const payload = (id = first, nextCursor: string | null = null) => ({
  calls: [{ id, status: 'uncertain', family: 'seller', createdAt: null }],
  nextCursor,
});
const response = (data: unknown, ok = true) => ({ ok, redirected: false, json: async () => data });
function deferred<T>() {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>((done) => {
    resolve = done;
  });
  return { promise, resolve };
}
beforeEach(() => {
  localStorage.clear();
});
afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
  vi.useRealTimers();
  localStorage.clear();
});

it('проверяет ответ повторным запросом и не показывает данные отозванной сессии', async () => {
  const fetchMock = vi
    .fn()
    .mockResolvedValueOnce(response(payload()))
    .mockResolvedValueOnce(response({}, false));
  vi.stubGlobal('fetch', fetchMock);
  const { result } = renderHook(() => useRecovery(brand, revision));
  await waitFor(() => expect(result.current.view.error).not.toBe(''));
  expect(result.current.view.data).toBeNull();
  expect(fetchMock).toHaveBeenCalledTimes(2);
  expect(fetchMock.mock.calls[0][1]).toMatchObject({
    cache: 'no-store',
    credentials: 'same-origin',
  });
  expect(fetchMock.mock.calls[0][1].headers).toMatchObject({
    'X-Brand-Id': brand,
    'X-Brand-Revision': revision,
  });
});

it('сразу скрывает данные при смене контекста и игнорирует задержанный ответ', async () => {
  const late = deferred<ReturnType<typeof response>>();
  const fetchMock = vi
    .fn()
    .mockResolvedValueOnce(response(payload()))
    .mockReturnValueOnce(late.promise);
  vi.stubGlobal('fetch', fetchMock);
  const { result } = renderHook(() => useRecovery(brand, revision));
  await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));
  let marker: string | null = null;
  act(() => {
    marker = beginProfileContextChange();
  });
  expect(result.current.view.data).toBeNull();
  expect(result.current.view.error).toContain('Контекст изменился');
  await act(async () => {
    late.resolve(response(payload()));
    await late.promise;
  });
  expect(result.current.view.data).toBeNull();
  act(() => finishProfileContextChange(marker));
});

it('скрывает уже видимый список синхронно при начале переключения бренда', async () => {
  vi.stubGlobal('fetch', vi.fn().mockResolvedValue(response(payload())));
  const privateArea = document.createElement('div');
  privateArea.dataset.privateProfile = '';
  document.body.append(privateArea);
  const { result } = renderHook(() => useRecovery(brand, revision));
  await waitFor(() => expect(result.current.view.data?.calls[0].id).toBe(first));
  let marker: string | null = null;
  act(() => {
    marker = beginProfileContextChange();
  });
  expect(privateArea.hidden).toBe(true);
  expect(result.current.view.data).toBeNull();
  act(() => finishProfileContextChange(marker));
  privateArea.remove();
});

it('не переносит поздний ответ старого бренда в новый', async () => {
  const late = deferred<ReturnType<typeof response>>();
  const fetchMock = vi
    .fn()
    .mockReturnValueOnce(late.promise)
    .mockResolvedValue(response(payload(second)));
  vi.stubGlobal('fetch', fetchMock);
  const { result, rerender } = renderHook(({ id, rev }) => useRecovery(id, rev), {
    initialProps: { id: brand, rev: revision },
  });
  await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));
  rerender({ id: '01J00000000000000000000004', rev: 'b'.repeat(64) });
  await waitFor(() => expect(result.current.view.data?.calls[0].id).toBe(second));
  await act(async () => {
    late.resolve(response(payload(first)));
    await late.promise;
  });
  expect(result.current.view.data?.calls[0].id).toBe(second);
});

it('не восстанавливает старый список при возврате к прежнему бренду до новой проверки', async () => {
  const lateB = deferred<ReturnType<typeof response>>();
  const lateA = deferred<ReturnType<typeof response>>();
  const fetchMock = vi
    .fn()
    .mockResolvedValueOnce(response(payload(first)))
    .mockResolvedValueOnce(response(payload(first)))
    .mockReturnValueOnce(lateB.promise)
    .mockReturnValueOnce(lateA.promise)
    .mockResolvedValue(response(payload(first)));
  vi.stubGlobal('fetch', fetchMock);
  const { result, rerender } = renderHook(({ id, rev }) => useRecovery(id, rev), {
    initialProps: { id: brand, rev: revision },
  });
  await waitFor(() => expect(result.current.view.data?.calls[0].id).toBe(first));
  rerender({ id: '01J00000000000000000000004', rev: 'b'.repeat(64) });
  await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(3));
  rerender({ id: brand, rev: revision });
  expect(result.current.view.data).toBeNull();
  await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(4));
  await act(async () => {
    lateB.resolve(response(payload(second)));
    await lateB.promise;
  });
  expect(result.current.view.data).toBeNull();
  await act(async () => {
    lateA.resolve(response(payload(first)));
    await lateA.promise;
  });
  await waitFor(() => expect(result.current.view.data?.calls[0].id).toBe(first));
});

it('при отзыве сессии очищает уже показанный список при следующей проверке', async () => {
  const fetchMock = vi
    .fn()
    .mockResolvedValueOnce(response(payload()))
    .mockResolvedValueOnce(response(payload()))
    .mockResolvedValue(response({}, false));
  vi.stubGlobal('fetch', fetchMock);
  const { result } = renderHook(() => useRecovery(brand, revision));
  await waitFor(() => expect(result.current.view.data?.calls[0].id).toBe(first));
  await waitFor(() => expect(result.current.view.error).not.toBe(''), { timeout: 13_000 });
  expect(result.current.view.data).toBeNull();
}, 15_000);

it('закрывает список по сроку проверки при зависшей сети и игнорирует поздний ответ', async () => {
  const late = deferred<ReturnType<typeof response>>();
  const fetchMock = vi
    .fn()
    .mockResolvedValueOnce(response(payload()))
    .mockResolvedValueOnce(response(payload()))
    .mockReturnValue(late.promise);
  vi.stubGlobal('fetch', fetchMock);
  const { result } = renderHook(() => useRecovery(brand, revision));
  await waitFor(() => expect(result.current.view.data?.calls[0].id).toBe(first));
  await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(3), { timeout: 7_000 });
  await waitFor(() => expect(result.current.view.error).toContain('вовремя'), { timeout: 7_000 });
  expect(result.current.view.data).toBeNull();
  await act(async () => {
    late.resolve(response(payload()));
    await late.promise;
  });
  expect(result.current.view.data).toBeNull();
}, 15_000);

it('скрывает список при уходе вкладки в фон', async () => {
  vi.stubGlobal('fetch', vi.fn().mockResolvedValue(response(payload())));
  const privateArea = document.createElement('div');
  privateArea.dataset.privateProfile = '';
  document.body.append(privateArea);
  const { result } = renderHook(() => useRecovery(brand, revision));
  await waitFor(() => expect(result.current.view.data?.calls[0].id).toBe(first));
  Object.defineProperty(document, 'visibilityState', { configurable: true, value: 'hidden' });
  act(() => document.dispatchEvent(new Event('visibilitychange')));
  expect(privateArea.hidden).toBe(true);
  expect(result.current.view.data).toBeNull();
  Reflect.deleteProperty(document, 'visibilityState');
  privateArea.remove();
});

it('начинает перепроверку через 5 секунд и закрывает зависший список ровно через 10', async () => {
  vi.useFakeTimers();
  const late = deferred<ReturnType<typeof response>>();
  const fetchMock = vi
    .fn()
    .mockResolvedValueOnce(response(payload()))
    .mockResolvedValueOnce(response(payload()))
    .mockReturnValue(late.promise);
  vi.stubGlobal('fetch', fetchMock);
  const { result } = renderHook(() => useRecovery(brand, revision));
  await act(async () => {
    for (let i = 0; i < 12; i++) await Promise.resolve();
  });
  expect(result.current.view.data?.calls[0].id).toBe(first);
  await act(async () => {
    await vi.advanceTimersByTimeAsync(4_999);
  });
  expect(fetchMock).toHaveBeenCalledTimes(2);
  await act(async () => {
    await vi.advanceTimersByTimeAsync(1);
  });
  expect(fetchMock).toHaveBeenCalledTimes(3);
  await act(async () => {
    await vi.advanceTimersByTimeAsync(4_999);
  });
  expect(result.current.view.data?.calls[0].id).toBe(first);
  await act(async () => {
    await vi.advanceTimersByTimeAsync(1);
  });
  expect(result.current.view.data).toBeNull();
  expect(result.current.view.error).toContain('вовремя');
  await act(async () => {
    late.resolve(response(payload()));
    await late.promise;
  });
  expect(result.current.view.data).toBeNull();
});

it('переходит по курсору и возвращается на предыдущую страницу', async () => {
  const fetchMock = vi
    .fn()
    .mockImplementation(async (url: string) =>
      response(url.includes('after=') ? payload(second) : payload(first, first)),
    );
  vi.stubGlobal('fetch', fetchMock);
  const { result } = renderHook(() => useRecovery(brand, revision));
  await waitFor(() => expect(result.current.view.data?.calls[0].id).toBe(first));
  act(() => result.current.next());
  expect(result.current.view.data).toBeNull();
  await waitFor(() => expect(result.current.view.data?.calls[0].id).toBe(second));
  expect(result.current.page).toBe(2);
  act(() => result.current.previous());
  await waitFor(() => expect(result.current.view.data?.calls[0].id).toBe(first));
  expect(result.current.page).toBe(1);
});

import { act, cleanup, render, renderHook, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import {
  beginProfileContextChange,
  finishProfileContextChange,
} from '@/features/Shell/Profile/contextFence';
import { LastLogin } from '@/features/Shell/MemberActivity/LastLogin';
import { parseLastLogins } from '@/features/Shell/MemberActivity/parseLastLogins';
import { useMemberLastLogins } from '@/features/Shell/MemberActivity/useMemberLastLogins';

const first = '01J00000000000000000000001';
const second = '01J00000000000000000000002';
const brand = '01J00000000000000000000003';
const revision = 'a'.repeat(64);
const when = '2026-11-01T09:34:00.000000Z';
const payload = (id = first, time: string | null = when, rev = revision) => ({
  revision: rev,
  available: true,
  members: [{ id, lastLoginAt: time }],
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

it('отличает отсутствие истории от загрузки и ошибки, дату показывает в московском времени', () => {
  const { rerender } = render(<LastLogin status="loading" value={undefined} />);
  expect(screen.getByText('Загрузка…')).toBeTruthy();
  rerender(<LastLogin status="unavailable" value={null} />);
  expect(screen.getByText('Недоступно')).toBeTruthy();
  rerender(<LastLogin status="ready" value={null} />);
  expect(screen.getByText('Нет данных')).toBeTruthy();
  rerender(<LastLogin status="ready" value={undefined} />);
  expect(screen.getByText('Недоступно')).toBeTruthy();
  rerender(<LastLogin status="ready" value={when} />);
  const time = screen.getByText(/12:34/);
  expect(time.getAttribute('datetime')).toBe(when);
  expect(time.hasAttribute('data-private-profile')).toBe(true);
  rerender(<LastLogin status="ready" value="invalid" />);
  expect(screen.getByText('Недоступно')).toBeTruthy();
});

it('отклоняет чужие, неполные и повреждённые результаты без раскрытия', () => {
  expect(parseLastLogins(payload(), [first], revision)).toEqual({ [first]: when });
  expect(parseLastLogins(payload(first, null), [first], revision)).toEqual({ [first]: null });
  for (const value of [
    payload(second),
    payload(first, 'invalid'),
    payload(first, when, 'b'.repeat(64)),
    { revision, available: false, members: [] },
    { revision, available: true, members: [] },
  ]) {
    expect(() => parseLastLogins(value, [first], revision)).toThrow();
  }
});

it('запрашивает только видимые membership и проверяет ревизию до показа', async () => {
  const confirm = deferred<ReturnType<typeof response>>();
  const fetchMock = vi
    .fn()
    .mockResolvedValueOnce(response(payload()))
    .mockReturnValueOnce(confirm.promise);
  vi.stubGlobal('fetch', fetchMock);
  const { result } = renderHook(() => useMemberLastLogins([first], brand, revision));
  await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));
  expect(result.current.status).toBe('loading');
  expect(fetchMock.mock.calls[0][0]).toContain(encodeURIComponent(first));
  expect(fetchMock.mock.calls[0][1]).toMatchObject({
    cache: 'no-store',
    credentials: 'same-origin',
    headers: { 'X-Brand-Id': brand, 'X-Brand-Revision': revision },
  });
  await act(async () => {
    confirm.resolve(response(payload()));
    await confirm.promise;
  });
  await waitFor(() => expect(result.current.status).toBe('ready'));
  expect(result.current.values[first]).toBe(when);
});

it('не показывает просроченный ответ при смене контекста', async () => {
  const pending = deferred<ReturnType<typeof response>>();
  vi.stubGlobal(
    'fetch',
    vi.fn().mockResolvedValueOnce(response(payload())).mockReturnValueOnce(pending.promise),
  );
  const { result } = renderHook(() => useMemberLastLogins([first], brand, revision));
  let marker: string | null = null;
  await act(async () => {
    await Promise.resolve();
    await Promise.resolve();
  });
  act(() => {
    marker = beginProfileContextChange();
  });
  expect(result.current.status).toBe('unavailable');
  await act(async () => {
    pending.resolve(response(payload()));
    await pending.promise;
  });
  expect(result.current.values).toEqual({});
  act(() => finishProfileContextChange(marker));
});

it('при возврате к прежнему набору не показывает старое значение до новой проверки', async () => {
  const lateSecond = deferred<ReturnType<typeof response>>();
  const lateFirst = deferred<ReturnType<typeof response>>();
  const fetchMock = vi
    .fn()
    .mockResolvedValueOnce(response(payload()))
    .mockResolvedValueOnce(response(payload()))
    .mockReturnValueOnce(lateSecond.promise)
    .mockReturnValueOnce(lateFirst.promise)
    .mockResolvedValue(response(payload()));
  vi.stubGlobal('fetch', fetchMock);
  const { result, rerender } = renderHook(({ ids }) => useMemberLastLogins(ids, brand, revision), {
    initialProps: { ids: [first] },
  });
  await waitFor(() => expect(result.current.values[first]).toBe(when));
  rerender({ ids: [second] });
  await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(3));
  rerender({ ids: [first] });
  expect(result.current.values).toEqual({});
  expect(result.current.status).toBe('loading');
  await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(4));
  await act(async () => {
    lateSecond.resolve(response(payload(second)));
    await lateSecond.promise;
  });
  expect(result.current.values).toEqual({});
  await act(async () => {
    lateFirst.resolve(response(payload()));
    await lateFirst.promise;
  });
  await waitFor(() => expect(result.current.values[first]).toBe(when));
});

it('без схемы или при отказе не подменяет неизвестные данные отсутствующей историей', async () => {
  vi.stubGlobal(
    'fetch',
    vi.fn().mockResolvedValue(response({ revision, available: false, members: [] })),
  );
  const { result } = renderHook(() => useMemberLastLogins([first], brand, revision));
  await waitFor(() => expect(result.current.status).toBe('unavailable'));
  expect(result.current.values).toEqual({});
});

it('при зависшей сети скрывает данные не позже 10 секунд от начала проверки', async () => {
  vi.useFakeTimers();
  const late = deferred<ReturnType<typeof response>>();
  const fetchMock = vi
    .fn()
    .mockResolvedValueOnce(response(payload()))
    .mockResolvedValueOnce(response(payload()))
    .mockReturnValue(late.promise);
  vi.stubGlobal('fetch', fetchMock);
  const { result } = renderHook(() => useMemberLastLogins([first], brand, revision));
  await act(async () => {
    for (let i = 0; i < 12; i++) await Promise.resolve();
  });
  expect(result.current.status).toBe('ready');
  await act(async () => {
    await vi.advanceTimersByTimeAsync(5_000);
  });
  expect(fetchMock).toHaveBeenCalledTimes(3);
  await act(async () => {
    await vi.advanceTimersByTimeAsync(5_000);
  });
  expect(result.current.status).toBe('unavailable');
  expect(result.current.values).toEqual({});
  await act(async () => {
    late.resolve(response(payload()));
    await late.promise;
  });
  expect(result.current.values).toEqual({});
});

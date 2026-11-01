import { act, cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import Index from '@/pages/Vision/Measurements/Index';

const pageContext = vi.hoisted(() => ({ brandId: 'brand-alpha' }));
vi.mock('@inertiajs/react', () => ({
  Head: () => null,
  usePage: () => ({ props: { brand: { id: pageContext.brandId } } }),
}));
vi.mock('@/layouts/CabinetLayout', () => ({
  default: ({ children }: { children: ReactNode }) => <main>{children}</main>,
}));
vi.mock('@/features/Shell/Profile/contextFence', () => ({
  profileContextIsStable: () => true,
  subscribeProfileContext: () => () => {},
}));

const firstRevision = 'a'.repeat(64);
const secondRevision = 'b'.repeat(64);
const format = (value: string) =>
  new Intl.DateTimeFormat('ru-RU', { dateStyle: 'medium', timeStyle: 'short' }).format(
    new Date(value),
  );
const firstDate = format('2026-10-10T12:30:00Z');
const secondDate = format('2026-10-20T12:30:00Z');

function pending<T>() {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>((done) => {
    resolve = done;
  });
  return { promise, resolve };
}

function response(value: unknown) {
  return { ok: true, json: async () => value };
}

function listing(revision: string, page: number, timestamp: string) {
  return { revision, data: [{ created_at: timestamp }], page, last_page: 2, total: 26 };
}

beforeEach(() => {
  pageContext.brandId = 'brand-alpha';
  vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('visible');
});
afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

it('показывает загрузку и скрывает старую страницу до подтверждения новой', async () => {
  const nextPage = pending<ReturnType<typeof response>>();
  const fetchMock = vi.fn((url: string) => {
    if (url === '/vision/runs/data?page=1')
      return Promise.resolve(response(listing(firstRevision, 1, '2026-10-10T12:30:00Z')));
    if (url === '/vision/runs/data?page=2') return nextPage.promise;
    return Promise.resolve(response({ revision: firstRevision }));
  });
  vi.stubGlobal('fetch', fetchMock);
  render(<Index measurementBrandRevision={firstRevision} />);
  await screen.findAllByText(firstDate);
  const section = screen.getByRole('heading', { name: 'История замеров' }).closest('section')!;
  fireEvent.click(screen.getByRole('button', { name: 'Вперёд' }));
  expect(section.getAttribute('aria-busy')).toBe('true');
  expect(screen.getByRole('status').textContent).toContain('Загружаем замеры');
  const skeleton = screen.getByRole('status').querySelector('[aria-hidden="true"]');
  expect(skeleton?.children.length).toBe(3);
  expect(skeleton?.classList.contains('animate-pulse')).toBe(true);
  expect(skeleton?.classList.contains('motion-reduce:animate-none')).toBe(true);
  expect(screen.queryByText(firstDate)).toBeNull();
  await act(async () =>
    nextPage.resolve(response(listing(firstRevision, 2, '2026-10-20T12:30:00Z'))),
  );
  await screen.findAllByText(secondDate);
  await waitFor(() => expect(section.getAttribute('aria-busy')).toBe('false'));
  expect(screen.queryByText(firstDate)).toBeNull();
});

it('сохраняет понятное место фокуса при клавиатурном переходе страницы', async () => {
  const nextPage = pending<ReturnType<typeof response>>();
  vi.stubGlobal(
    'fetch',
    vi.fn((url: string) => {
      if (url === '/vision/runs/data?page=1')
        return Promise.resolve(response(listing(firstRevision, 1, '2026-10-10T12:30:00Z')));
      if (url === '/vision/runs/data?page=2') return nextPage.promise;
      return Promise.resolve(response({ revision: firstRevision }));
    }),
  );
  render(<Index measurementBrandRevision={firstRevision} />);
  await screen.findAllByText(firstDate);
  const button = screen.getByRole('button', { name: 'Вперёд' });
  button.focus();
  await userEvent.keyboard('{Enter}');
  expect(document.activeElement).toBe(screen.getByRole('heading', { name: 'История замеров' }));
  expect(screen.getByRole('status').textContent).toContain('Загружаем замеры');
  await act(async () =>
    nextPage.resolve(response(listing(firstRevision, 2, '2026-10-20T12:30:00Z'))),
  );
  await screen.findAllByText(secondDate);
  expect(document.activeElement).toBe(screen.getByRole('heading', { name: 'История замеров' }));
  screen.getByRole('button', { name: 'Назад' }).focus();
  await userEvent.keyboard('{Enter}');
  await screen.findAllByText(firstDate);
  expect(document.activeElement).toBe(screen.getByRole('heading', { name: 'История замеров' }));
});

it('не перехватывает фокус при смене бренда и фоновом ответе', async () => {
  const nextBrand = pending<ReturnType<typeof response>>();
  vi.stubGlobal(
    'fetch',
    vi.fn((url: string, init?: RequestInit) => {
      const headers = init?.headers as Record<string, string>;
      if (url.startsWith('/vision/runs/data'))
        return headers['X-Brand-Id'] === 'brand-alpha'
          ? Promise.resolve(response(listing(firstRevision, 1, '2026-10-10T12:30:00Z')))
          : nextBrand.promise;
      return Promise.resolve(response({ revision: headers['X-Brand-Revision'] }));
    }),
  );
  const page = (revision: string) => (
    <>
      <button type="button">Другое действие</button>
      <Index measurementBrandRevision={revision} />
    </>
  );
  const view = render(page(firstRevision));
  await screen.findAllByText(firstDate);
  const outside = screen.getByRole('button', { name: 'Другое действие' });
  outside.focus();
  pageContext.brandId = 'brand-beta';
  view.rerender(page(secondRevision));
  expect(document.activeElement).toBe(outside);
  await act(async () =>
    nextBrand.resolve(response(listing(secondRevision, 1, '2026-10-20T12:30:00Z'))),
  );
  await screen.findAllByText(secondDate);
  expect(document.activeElement).toBe(outside);
});

it('сохраняет фокус в истории при клавиатурном повторе после ошибки', async () => {
  const retry = pending<ReturnType<typeof response>>();
  let requests = 0;
  vi.stubGlobal(
    'fetch',
    vi.fn((url: string) => {
      if (url.startsWith('/vision/runs/data')) {
        requests++;
        return requests === 1 ? Promise.reject(new Error('synthetic failure')) : retry.promise;
      }
      return Promise.resolve(response({ revision: firstRevision }));
    }),
  );
  render(<Index measurementBrandRevision={firstRevision} />);
  await screen.findByRole('alert');
  screen.getByRole('button', { name: 'Повторить' }).focus();
  await userEvent.keyboard('{Enter}');
  expect(document.activeElement).toBe(screen.getByRole('heading', { name: 'История замеров' }));
  expect(screen.getByRole('status').textContent).toContain('Загружаем замеры');
  await act(async () => retry.resolve(response(listing(firstRevision, 1, '2026-10-10T12:30:00Z'))));
  await screen.findAllByText(firstDate);
  expect(document.activeElement).toBe(screen.getByRole('heading', { name: 'История замеров' }));
});

it('при смене бренда сразу показывает загрузку и не раскрывает прежние строки', async () => {
  const nextBrand = pending<ReturnType<typeof response>>();
  const fetchMock = vi.fn((url: string, init?: RequestInit) => {
    const headers = init?.headers as Record<string, string>;
    if (url.startsWith('/vision/runs/data')) {
      return headers['X-Brand-Id'] === 'brand-alpha'
        ? Promise.resolve(response(listing(firstRevision, 1, '2026-10-10T12:30:00Z')))
        : nextBrand.promise;
    }
    return Promise.resolve(response({ revision: headers['X-Brand-Revision'] }));
  });
  vi.stubGlobal('fetch', fetchMock);
  const view = render(<Index measurementBrandRevision={firstRevision} />);
  await screen.findAllByText(firstDate);
  const section = screen.getByRole('heading', { name: 'История замеров' }).closest('section')!;
  pageContext.brandId = 'brand-beta';
  view.rerender(<Index measurementBrandRevision={secondRevision} />);
  expect(section.getAttribute('aria-busy')).toBe('true');
  expect(screen.getByRole('status').textContent).toContain('Загружаем замеры');
  expect(screen.queryByText(firstDate)).toBeNull();
  await act(async () =>
    nextBrand.resolve(response(listing(secondRevision, 1, '2026-10-20T12:30:00Z'))),
  );
  await screen.findAllByText(secondDate);
  expect(screen.queryByText(firstDate)).toBeNull();
});

it('не показывает строки другого бренда даже при неизменённой ревизии', async () => {
  const nextBrand = pending<ReturnType<typeof response>>();
  vi.stubGlobal(
    'fetch',
    vi.fn((url: string, init?: RequestInit) => {
      const headers = init?.headers as Record<string, string>;
      if (url.startsWith('/vision/runs/data')) {
        return headers['X-Brand-Id'] === 'brand-alpha'
          ? Promise.resolve(response(listing(firstRevision, 1, '2026-10-10T12:30:00Z')))
          : nextBrand.promise;
      }
      return Promise.resolve(response({ revision: firstRevision }));
    }),
  );
  const view = render(<Index measurementBrandRevision={firstRevision} />);
  await screen.findAllByText(firstDate);
  pageContext.brandId = 'brand-beta';
  view.rerender(<Index measurementBrandRevision={firstRevision} />);
  expect(
    screen
      .getByRole('heading', { name: 'История замеров' })
      .closest('section')!
      .getAttribute('aria-busy'),
  ).toBe('true');
  expect(screen.queryByText(firstDate)).toBeNull();
  await act(async () =>
    nextBrand.resolve(response(listing(firstRevision, 1, '2026-10-20T12:30:00Z'))),
  );
  await screen.findAllByText(secondDate);
});

it('при быстром возврате к бренду заново загружает первую страницу', async () => {
  const otherBrand = pending<ReturnType<typeof response>>();
  const returnToFirst = pending<ReturnType<typeof response>>();
  let alphaRequests = 0;
  vi.stubGlobal(
    'fetch',
    vi.fn((url: string, init?: RequestInit) => {
      const headers = init?.headers as Record<string, string>;
      if (url.startsWith('/vision/runs/data')) {
        if (headers['X-Brand-Id'] === 'brand-beta') return otherBrand.promise;
        alphaRequests++;
        return alphaRequests === 1
          ? Promise.resolve(response(listing(firstRevision, 1, '2026-10-10T12:30:00Z')))
          : returnToFirst.promise;
      }
      return Promise.resolve(response({ revision: firstRevision }));
    }),
  );
  const view = render(<Index measurementBrandRevision={firstRevision} />);
  await screen.findAllByText(firstDate);
  pageContext.brandId = 'brand-beta';
  view.rerender(<Index measurementBrandRevision={firstRevision} />);
  pageContext.brandId = 'brand-alpha';
  view.rerender(<Index measurementBrandRevision={firstRevision} />);
  expect(
    screen
      .getByRole('heading', { name: 'История замеров' })
      .closest('section')!
      .getAttribute('aria-busy'),
  ).toBe('true');
  expect(screen.queryByText(firstDate)).toBeNull();
  await act(async () =>
    returnToFirst.resolve(response(listing(firstRevision, 1, '2026-10-20T12:30:00Z'))),
  );
  await screen.findAllByText(secondDate);
});

it('игнорирует поздний ответ старого бренда при переключении страницы и контекста', async () => {
  const oldPage = pending<ReturnType<typeof response>>();
  const nextBrand = pending<ReturnType<typeof response>>();
  const fetchMock = vi.fn((url: string, init?: RequestInit) => {
    const headers = init?.headers as Record<string, string>;
    if (url === '/vision/runs/data?page=1' && headers['X-Brand-Id'] === 'brand-alpha')
      return Promise.resolve(response(listing(firstRevision, 1, '2026-10-10T12:30:00Z')));
    if (url === '/vision/runs/data?page=2' && headers['X-Brand-Id'] === 'brand-alpha')
      return oldPage.promise;
    if (url.startsWith('/vision/runs/data')) return nextBrand.promise;
    return Promise.resolve(response({ revision: firstRevision }));
  });
  vi.stubGlobal('fetch', fetchMock);
  const view = render(<Index measurementBrandRevision={firstRevision} />);
  await screen.findAllByText(firstDate);
  fireEvent.click(screen.getByRole('button', { name: 'Вперёд' }));
  pageContext.brandId = 'brand-beta';
  view.rerender(<Index measurementBrandRevision={firstRevision} />);
  await waitFor(() =>
    expect(fetchMock).toHaveBeenCalledWith(
      '/vision/runs/data?page=1',
      expect.objectContaining({
        headers: expect.objectContaining({ 'X-Brand-Id': 'brand-beta' }),
      }),
    ),
  );
  await act(async () =>
    nextBrand.resolve(response(listing(firstRevision, 1, '2026-10-20T12:30:00Z'))),
  );
  await screen.findAllByText(secondDate);
  await act(async () =>
    oldPage.resolve(response(listing(firstRevision, 2, '2026-10-10T12:30:00Z'))),
  );
  expect(screen.queryByText(firstDate)).toBeNull();
  expect(screen.getAllByText(secondDate).length).toBeGreaterThan(0);
});

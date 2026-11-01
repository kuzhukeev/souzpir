import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import Index from '@/pages/Shell/Team/Index';
import type { TeamPage } from '@/types/Shell/Team/members';

const calls = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));
vi.mock('@inertiajs/react', () => ({
  Head: () => null,
  Link: ({ href, children, ...props }: { href: string; children: ReactNode }) => (
    <a href={href} {...props}>
      {children}
    </a>
  ),
  router: { get: calls.get, post: calls.post, on: () => () => {} },
  usePage: () => ({ props: { brand: { id: '01J00000000000000000000003' } } }),
}));
vi.mock('@/layouts/CabinetLayout', () => ({
  default: ({ children }: { children: ReactNode }) => <main>{children}</main>,
}));
const brandId = '01J00000000000000000000003';
const firstId = '01J00000000000000000000001';
const secondId = '01J00000000000000000000002';
const revision = 'a'.repeat(64);
const nextRevision = 'b'.repeat(64);

beforeEach(() => {
  localStorage.clear();
  vi.stubGlobal(
    'fetch',
    vi.fn(async (input: string, init: RequestInit) => {
      const url = new URL(input, 'http://localhost');
      const headers = init.headers as Record<string, string>;
      if (
        url.pathname !== '/team/member-last-logins' ||
        headers['X-Brand-Id'] !== brandId ||
        !/^[a-f0-9]{64}$/.test(headers['X-Brand-Revision'])
      )
        throw new Error('Unexpected request');
      return {
        ok: true,
        redirected: false,
        json: async () => ({
          revision: headers['X-Brand-Revision'],
          available: true,
          members: url.searchParams.getAll('ids[]').map((id) => ({
            id,
            lastLoginAt: id === firstId ? '2026-11-01T09:34:00.000000Z' : null,
          })),
        }),
      };
    }),
  );
});
afterEach(() => {
  cleanup();
  vi.clearAllMocks();
  vi.unstubAllGlobals();
  localStorage.clear();
});

const members: TeamPage = {
  current_page: 1,
  last_page: 1,
  total: 2,
  data: [
    {
      id: firstId,
      name: 'Иван Петров',
      email: 'ivan@example.test',
      role: 'owner',
      role_label: 'Владелец',
      status: 'active',
      brands: [{ id: brandId, name: 'Длинное название разрешённого бренда' }],
    },
    {
      id: secondId,
      name: 'Анна Иванова',
      email: 'anna@example.test',
      role: 'manager',
      role_label: 'Менеджер',
      status: 'disabled',
      brands: [],
    },
  ],
};
const emptyInvitations = {
  as_of: '2026-11-01T12:00:00.000Z',
  data: [],
  current_page: 1,
  last_page: 1,
  total: 0,
};

it('независимо применяет права на матрицу и выпуск приглашений', () => {
  const props = {
    teamBrandRevision: revision,
    members,
    invitations: emptyInvitations,
    filterBrands: [{ id: brandId, name: 'Бренд А' }],
  };
  const view = render(<Index {...props} canViewRoles canIssueInvitations={false} />);
  expect(screen.getByRole('link', { name: 'Роли и доступ' })).toBeTruthy();
  expect(screen.queryByRole('button', { name: 'Пригласить участника' })).toBeNull();
  view.rerender(<Index {...props} canViewRoles={false} canIssueInvitations />);
  expect(screen.queryByRole('link', { name: 'Роли и доступ' })).toBeNull();
  fireEvent.click(screen.getByRole('button', { name: 'Пригласить участника' }));
  expect(screen.getByRole('dialog', { name: 'Пригласить участника' })).toBeTruthy();
  expect((screen.getByRole('button', { name: 'Продолжить' }) as HTMLButtonElement).disabled).toBe(
    false,
  );
});

it('загружает последние входы показанных участников с текущим брендом и ревизией', async () => {
  render(
    <Index
      teamBrandRevision={revision}
      members={members}
      canViewRoles
      canIssueInvitations
      invitations={emptyInvitations}
    />,
  );
  const table = screen.getByRole('region', { name: 'Таблица участников' });
  await waitFor(() => expect(within(table).getByText(/12:34/)).toBeTruthy());
  expect(within(table).getByText('Нет данных')).toBeTruthy();
  expect(fetch).toHaveBeenCalledTimes(2);
  const [url, options] = vi.mocked(fetch).mock.calls[0];
  const query = new URL(String(url), 'http://localhost');
  expect(query.pathname).toBe('/team/member-last-logins');
  expect(query.searchParams.getAll('ids[]')).toEqual([firstId, secondId]);
  expect(options?.headers).toMatchObject({ 'X-Brand-Id': brandId, 'X-Brand-Revision': revision });
});

it('не предлагает отзыв при недоступном сроке или серверном времени', () => {
  const item = {
    id: 'invalid',
    email: 'invalid@example.test',
    role_label: 'Наблюдатель',
    brands: [],
    expires_at: 'invalid',
    opened: false,
    can_revoke: true,
  };
  const { rerender } = render(
    <Index
      teamBrandRevision={revision}
      members={members}
      canViewRoles
      canIssueInvitations
      invitations={{ ...emptyInvitations, data: [item], total: 1 }}
    />,
  );
  expect(screen.getByText('Срок недоступен')).toBeTruthy();
  expect((screen.getByRole('button', { name: 'Отозвать' }) as HTMLButtonElement).disabled).toBe(
    true,
  );
  rerender(
    <Index
      teamBrandRevision={revision}
      members={members}
      canViewRoles
      canIssueInvitations
      invitations={{
        ...emptyInvitations,
        as_of: 'invalid',
        data: [{ ...item, expires_at: '2027-02-02T12:00:00.000Z' }],
        total: 1,
      }}
    />,
  );
  expect(screen.getByText('Срок недоступен')).toBeTruthy();
  expect((screen.getByRole('button', { name: 'Отозвать' }) as HTMLButtonElement).disabled).toBe(
    true,
  );
  expect(calls.post).not.toHaveBeenCalled();
});

it('показывает только переданные данные без неработающих действий и без вымышленных брендов', () => {
  render(
    <Index
      teamBrandRevision={revision}
      members={members}
      canViewRoles
      canIssueInvitations
      invitations={emptyInvitations}
    />,
  );
  const table = screen.getByRole('region', { name: 'Таблица участников' });
  expect(within(table).getByText('Иван Петров')).toBeTruthy();
  expect(
    within(table).getByRole('link', { name: 'Открыть карточку: Иван Петров' }).getAttribute('href'),
  ).toBe('/team/' + firstId);
  expect(within(table).getByText('ivan@example.test')).toBeTruthy();
  expect(within(table).getByText('Длинное название разрешённого бренда')).toBeTruthy();
  expect(within(table).getByText('Нет общих доступных брендов')).toBeTruthy();
  expect(within(table).getByText('Отключён')).toBeTruthy();
  expect(screen.getByRole('link', { name: 'Роли и доступ' }).getAttribute('href')).toBe(
    '/team/roles',
  );
  expect(screen.getByRole('button', { name: 'Пригласить участника' })).toBeTruthy();
  expect(screen.queryByRole('button', { name: /изменить роль|отключить/i })).toBeNull();
  expect(screen.getByText('Приглашений в ожидании нет')).toBeTruthy();
});

it('показывает честное пустое состояние и не предлагает матрицу неуполномоченному участнику', () => {
  render(
    <Index
      teamBrandRevision={revision}
      members={{ data: [], current_page: 1, last_page: 1, total: 0 }}
      canViewRoles={false}
      canIssueInvitations={false}
      invitations={emptyInvitations}
    />,
  );
  expect(screen.getByText('Участников пока нет')).toBeTruthy();
  expect(screen.queryByRole('link', { name: 'Роли и доступ' })).toBeNull();
  expect(screen.queryByRole('button', { name: 'Пригласить участника' })).toBeNull();
  expect(fetch).not.toHaveBeenCalled();
});

it('владелец открывает и закрывает настоящее окно приглашения', () => {
  render(
    <Index
      teamBrandRevision={revision}
      members={members}
      canViewRoles
      canIssueInvitations
      invitations={emptyInvitations}
      filterBrands={[{ id: brandId, name: 'Бренд А' }]}
    />,
  );
  fireEvent.click(screen.getByRole('button', { name: 'Пригласить участника' }));
  const dialog = screen.getByRole('dialog', { name: 'Пригласить участника' });
  expect(within(dialog).getByLabelText('Электронная почта')).toBeTruthy();
  expect(
    (within(dialog).getByRole('checkbox', { name: 'Бренд А' }) as HTMLInputElement).checked,
  ).toBe(true);
  fireEvent.click(within(dialog).getByRole('button', { name: 'Закрыть' }));
  expect(screen.queryByRole('dialog', { name: 'Пригласить участника' })).toBeNull();
});

it('наблюдатель с непустым списком не видит выпуска приглашений', () => {
  render(
    <Index
      teamBrandRevision={revision}
      members={members}
      canViewRoles={false}
      canIssueInvitations={false}
      invitations={emptyInvitations}
      filterBrands={[{ id: brandId, name: 'Бренд А' }]}
    />,
  );
  expect(screen.getByRole('region', { name: 'Таблица участников' })).toBeTruthy();
  expect(screen.queryByRole('button', { name: 'Пригласить участника' })).toBeNull();
  expect(screen.queryByRole('dialog', { name: 'Пригласить участника' })).toBeNull();
});

it('сохраняет предупреждение при закрытии во время выпуска и не повторяет POST', async () => {
  let resolve!: (response: Response) => void;
  const pending = new Promise<Response>((done) => {
    resolve = done;
  });
  const fallback = vi.mocked(fetch).getMockImplementation()!;
  const fetcher = vi.fn((input: RequestInfo | URL, init?: RequestInit) =>
    input === '/team/invitations' ? pending : fallback(input, init),
  );
  vi.stubGlobal('fetch', fetcher);
  document.cookie = 'XSRF-TOKEN=synthetic; path=/';
  render(
    <Index
      teamBrandRevision={revision}
      members={members}
      canViewRoles
      canIssueInvitations
      invitations={emptyInvitations}
      filterBrands={[{ id: brandId, name: 'Бренд А' }]}
    />,
  );
  fireEvent.click(screen.getByRole('button', { name: 'Пригласить участника' }));
  fireEvent.change(screen.getByLabelText('Электронная почта'), {
    target: { value: 'person@example.test' },
  });
  fireEvent.click(screen.getByRole('button', { name: 'Продолжить' }));
  fireEvent.click(screen.getByRole('button', { name: 'Подтвердить выпуск' }));
  expect(fetcher.mock.calls.filter(([input]) => input === '/team/invitations')).toHaveLength(1);
  fireEvent.click(screen.getByRole('button', { name: 'Закрыть' }));
  fireEvent.click(screen.getByRole('button', { name: 'Пригласить участника' }));
  expect(screen.getByText(/Результат неизвестен/)).toBeTruthy();
  await act(async () => {
    resolve(
      new Response(
        JSON.stringify({ link: 'http://localhost/invitations/open#' + 'A'.repeat(64) }),
        { status: 201 },
      ),
    );
    await pending;
  });
  expect(screen.queryByLabelText('Ссылка приглашения')).toBeNull();
  expect(fetcher.mock.calls.filter(([input]) => input === '/team/invitations')).toHaveLength(1);
});

it('отличает пустую страницу от отсутствия участников', () => {
  render(
    <Index
      teamBrandRevision={revision}
      members={{ data: [], current_page: 3, last_page: 2, total: 27 }}
      canViewRoles={false}
      canIssueInvitations={false}
      invitations={emptyInvitations}
    />,
  );
  expect(screen.getByText('На этой странице нет участников')).toBeTruthy();
  expect(screen.getByText('Вернитесь на предыдущую страницу.')).toBeTruthy();
});

it('показывает загрузку, ошибку перехода и повторяет нужную страницу', () => {
  render(
    <Index
      teamBrandRevision={revision}
      members={{ ...members, last_page: 2, total: 27 }}
      canViewRoles
      canIssueInvitations
      invitations={emptyInvitations}
      roleFilter="manager"
      brandFilter="brand-a"
      statusFilter="disabled"
      filterBrands={[{ id: 'brand-a', name: 'Бренд А' }]}
    />,
  );
  fireEvent.click(screen.getByRole('button', { name: 'Вперёд' }));
  expect(
    within(screen.getByRole('region', { name: 'Участники' })).getByRole('status').textContent,
  ).toContain('Загружаем');
  expect(calls.get.mock.calls[0][0]).toBe('/team');
  expect(calls.get.mock.calls[0][1]).toEqual({
    page: 2,
    invitation_page: 1,
    role: 'manager',
    brand: 'brand-a',
    status: 'disabled',
  });
  const options = calls.get.mock.calls[0][2];
  act(() => {
    options.onNetworkError();
    options.onFinish();
  });
  expect(screen.getByRole('alert').textContent).toContain('Не удалось загрузить');
  fireEvent.click(screen.getByRole('button', { name: 'Повторить' }));
  expect(calls.get.mock.calls[1][1]).toEqual({
    page: 2,
    invitation_page: 1,
    role: 'manager',
    brand: 'brand-a',
    status: 'disabled',
  });
});

it('показывает данные приглашения и повторяет страницу после ошибки без изменения страницы участников', () => {
  const invitations = {
    as_of: '2026-11-01T12:00:00.000Z',
    current_page: 1,
    last_page: 2,
    total: 27,
    data: [
      {
        id: 'invite',
        email: 'new@example.test',
        role_label: 'Менеджер',
        brands: [{ id: 'a', name: 'Разрешённый бренд' }],
        expires_at: '2027-02-02T12:00:00.000Z',
        opened: false,
        can_revoke: false,
      },
    ],
  };
  render(
    <Index
      teamBrandRevision={revision}
      members={{ ...members, current_page: 2, last_page: 2 }}
      canViewRoles
      canIssueInvitations
      invitations={invitations}
      roleFilter="viewer"
      brandFilter="brand-a"
      statusFilter="invited"
      filterBrands={[{ id: 'brand-a', name: 'Бренд А' }]}
    />,
  );
  const section = screen.getByRole('region', { name: 'Приглашения в ожидании' });
  expect(within(section).getByText('new@example.test')).toBeTruthy();
  expect(within(section).getByText('Разрешённый бренд')).toBeTruthy();
  expect(within(section).getByText('Ссылка не открыта')).toBeTruthy();
  expect(within(section).getByText(/не подтверждает доставку/)).toBeTruthy();
  fireEvent.click(within(section).getByRole('button', { name: 'Вперёд' }));
  expect(within(section).getByRole('status').textContent).toContain('Загружаем приглашения');
  expect(calls.get.mock.calls[0][1]).toEqual({
    page: 2,
    invitation_page: 2,
    role: 'viewer',
    brand: 'brand-a',
    status: 'invited',
  });
  const options = calls.get.mock.calls[0][2];
  act(() => {
    options.onHttpException();
    options.onFinish();
  });
  expect(within(section).getByRole('alert').textContent).toContain('Не удалось загрузить');
  fireEvent.click(within(section).getByRole('button', { name: 'Повторить' }));
  expect(calls.get.mock.calls[1][1]).toEqual({
    page: 2,
    invitation_page: 2,
    role: 'viewer',
    brand: 'brand-a',
    status: 'invited',
  });
});

it('обновляет приглашения с первой страницы, сохраняя страницу участников и фильтры, и позволяет повторить при ошибке', () => {
  render(
    <Index
      teamBrandRevision={revision}
      members={{ ...members, current_page: 2, last_page: 2 }}
      canViewRoles
      canIssueInvitations
      invitations={{ ...emptyInvitations, current_page: 3, last_page: 1 }}
      roleFilter="viewer"
      brandFilter="brand-a"
      statusFilter="invited"
      filterBrands={[{ id: 'brand-a', name: 'Бренд А' }]}
    />,
  );
  const section = screen.getByRole('region', { name: 'Приглашения в ожидании' });
  const refresh = within(section).getByRole('button', { name: 'Обновить приглашения' });
  fireEvent.click(refresh);
  const expected = { page: 2, invitation_page: 1, role: 'viewer', brand: 'brand-a', status: 'invited' };
  expect(calls.get.mock.calls[0][1]).toEqual(expected);
  expect((refresh as HTMLButtonElement).disabled).toBe(true);
  expect(within(section).getByText('Загружаем приглашения…').getAttribute('role')).toBe('status');
  act(() => {
    calls.get.mock.calls[0][2].onNetworkError();
    calls.get.mock.calls[0][2].onFinish();
  });
  expect(within(section).getByRole('alert').textContent).toContain('Не удалось загрузить');
  fireEvent.click(within(section).getByRole('button', { name: 'Повторить' }));
  expect(calls.get.mock.calls[1][1]).toEqual(expected);
  expect(calls.post).not.toHaveBeenCalled();
});

it('подтверждает отзыв и при неизвестном результате требует обновить страницу', () => {
  const invitations = {
    ...emptyInvitations,
    total: 1,
    data: [
      {
        id: 'invite',
        email: 'new@example.test',
        role_label: 'Редактор',
        brands: [],
        expires_at: '2027-02-02T12:00:00.000Z',
        opened: false,
        can_revoke: true,
      },
    ],
  };
  render(
    <Index
      teamBrandRevision={revision}
      members={members}
      canViewRoles
      canIssueInvitations
      invitations={invitations}
    />,
  );
  fireEvent.click(screen.getByRole('button', { name: 'Отозвать' }));
  const dialog = screen.getByRole('dialog', { name: 'Отозвать приглашение' });
  expect(within(dialog).getByText(/new@example.test/)).toBeTruthy();
  fireEvent.click(within(dialog).getByRole('button', { name: 'Отмена' }));
  expect(calls.post).not.toHaveBeenCalled();
  fireEvent.click(screen.getByRole('button', { name: 'Отозвать' }));
  fireEvent.click(
    within(screen.getByRole('dialog')).getByRole('button', { name: 'Отозвать приглашение' }),
  );
  expect(calls.post.mock.calls[0][0]).toBe('/team/invitations/invite/revoke');
  expect(calls.post.mock.calls[0][2].headers).toEqual({ 'X-Brand-Revision': revision });
  const options = calls.post.mock.calls[0][2];
  act(() => {
    options.onNetworkError();
    options.onFinish();
  });
  const modal = screen.getByRole('dialog');
  expect(within(modal).getByRole('alert').textContent).toContain(
    'Не удалось подтвердить результат',
  );
  expect(
    (within(modal).getByRole('button', { name: 'Отозвать приглашение' }) as HTMLButtonElement)
      .disabled,
  ).toBe(true);
});

it('привязывает подтверждение к ревизии в момент открытия диалога', () => {
  const invitations = {
    ...emptyInvitations,
    total: 1,
    data: [
      {
        id: 'invite',
        email: 'new@example.test',
        role_label: 'Редактор',
        brands: [],
        expires_at: '2027-02-02T12:00:00.000Z',
        opened: false,
        can_revoke: true,
      },
    ],
  };
  const view = render(
    <Index
      members={members}
      canViewRoles
      canIssueInvitations
      invitations={invitations}
      teamBrandRevision={revision}
    />,
  );
  fireEvent.click(screen.getByRole('button', { name: 'Отозвать' }));
  view.rerender(
    <Index
      members={members}
      canViewRoles
      canIssueInvitations
      invitations={invitations}
      teamBrandRevision={nextRevision}
    />,
  );
  fireEvent.click(
    within(screen.getByRole('dialog')).getByRole('button', { name: 'Отозвать приглашение' }),
  );
  expect(calls.post.mock.calls[0][2].headers).toEqual({ 'X-Brand-Revision': revision });
});

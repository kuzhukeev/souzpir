import { act, cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, expect, it, vi } from 'vitest';
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
  router: { get: calls.get, post: calls.post },
}));
vi.mock('@/layouts/CabinetLayout', () => ({
  default: ({ children }: { children: ReactNode }) => <main>{children}</main>,
}));
afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

const members: TeamPage = {
  current_page: 1,
  last_page: 1,
  total: 2,
  data: [
    {
      id: 'one',
      name: 'Иван Петров',
      email: 'ivan@example.test',
      role: 'owner',
      role_label: 'Владелец',
      status: 'active',
      brands: [{ id: 'a', name: 'Длинное название разрешённого бренда' }],
    },
    {
      id: 'two',
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
      teamBrandRevision="test"
      members={members}
      canViewRoles
      invitations={{ ...emptyInvitations, data: [item], total: 1 }}
    />,
  );
  expect(screen.getByText('Срок недоступен')).toBeTruthy();
  expect((screen.getByRole('button', { name: 'Отозвать' }) as HTMLButtonElement).disabled).toBe(
    true,
  );
  rerender(
    <Index
      teamBrandRevision="test"
      members={members}
      canViewRoles
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
      teamBrandRevision="test-revision"
      members={members}
      canViewRoles
      invitations={emptyInvitations}
    />,
  );
  const table = screen.getByRole('region', { name: 'Таблица участников' });
  expect(within(table).getByText('Иван Петров')).toBeTruthy();
  expect(
    within(table).getByRole('link', { name: 'Открыть карточку: Иван Петров' }).getAttribute('href'),
  ).toBe('/team/one');
  expect(within(table).getByText('ivan@example.test')).toBeTruthy();
  expect(within(table).getByText('Длинное название разрешённого бренда')).toBeTruthy();
  expect(within(table).getByText('Нет общих доступных брендов')).toBeTruthy();
  expect(within(table).getByText('Отключён')).toBeTruthy();
  expect(screen.getByRole('link', { name: 'Роли и доступ' }).getAttribute('href')).toBe(
    '/team/roles',
  );
  expect(screen.queryByRole('button', { name: /пригласить|изменить роль|отключить/i })).toBeNull();
  expect(screen.getByText('Приглашений в ожидании нет')).toBeTruthy();
});

it('показывает честное пустое состояние и не предлагает матрицу неуполномоченному участнику', () => {
  render(
    <Index
      teamBrandRevision="test-revision"
      members={{ data: [], current_page: 1, last_page: 1, total: 0 }}
      canViewRoles={false}
      invitations={emptyInvitations}
    />,
  );
  expect(screen.getByText('Участников пока нет')).toBeTruthy();
  expect(screen.queryByRole('link', { name: 'Роли и доступ' })).toBeNull();
});

it('отличает пустую страницу от отсутствия участников', () => {
  render(
    <Index
      teamBrandRevision="test-revision"
      members={{ data: [], current_page: 3, last_page: 2, total: 27 }}
      canViewRoles={false}
      invitations={emptyInvitations}
    />,
  );
  expect(screen.getByText('На этой странице нет участников')).toBeTruthy();
  expect(screen.getByText('Вернитесь на предыдущую страницу.')).toBeTruthy();
});

it('показывает загрузку, ошибку перехода и повторяет нужную страницу', () => {
  render(
    <Index
      teamBrandRevision="test-revision"
      members={{ ...members, last_page: 2, total: 27 }}
      canViewRoles
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
      teamBrandRevision="test-revision"
      members={{ ...members, current_page: 2, last_page: 2 }}
      canViewRoles
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
      teamBrandRevision="test-revision"
      members={members}
      canViewRoles
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
  expect(calls.post.mock.calls[0][2].headers).toEqual({ 'X-Brand-Revision': 'test-revision' });
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
      invitations={invitations}
      teamBrandRevision="old-revision"
    />,
  );
  fireEvent.click(screen.getByRole('button', { name: 'Отозвать' }));
  view.rerender(
    <Index
      members={members}
      canViewRoles
      invitations={invitations}
      teamBrandRevision="new-revision"
    />,
  );
  fireEvent.click(
    within(screen.getByRole('dialog')).getByRole('button', { name: 'Отозвать приглашение' }),
  );
  expect(calls.post.mock.calls[0][2].headers).toEqual({ 'X-Brand-Revision': 'old-revision' });
});

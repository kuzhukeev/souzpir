import { act, cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, expect, it, vi } from 'vitest';
import Index from '@/pages/Shell/Team/Index';
import type { TeamPage } from '@/types/Shell/Team/members';

const calls = vi.hoisted(() => ({ get: vi.fn() }));
vi.mock('@inertiajs/react', () => ({
  Head: () => null,
  Link: ({ href, children, ...props }: { href: string; children: ReactNode }) => (
    <a href={href} {...props}>
      {children}
    </a>
  ),
  router: { get: calls.get },
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

it('показывает только переданные данные без неработающих действий и без вымышленных брендов', () => {
  render(<Index members={members} canViewRoles />);
  const table = screen.getByRole('region', { name: 'Таблица участников' });
  expect(within(table).getByText('Иван Петров')).toBeTruthy();
  expect(within(table).getByText('ivan@example.test')).toBeTruthy();
  expect(within(table).getByText('Длинное название разрешённого бренда')).toBeTruthy();
  expect(within(table).getByText('Нет общих доступных брендов')).toBeTruthy();
  expect(within(table).getByText('Отключён')).toBeTruthy();
  expect(screen.getByRole('link', { name: 'Роли и доступ' }).getAttribute('href')).toBe(
    '/team/roles',
  );
  expect(screen.queryByRole('button', { name: /пригласить|изменить роль|отключить/i })).toBeNull();
  expect(screen.getByText(/Приглашения пока не отображаются/)).toBeTruthy();
});

it('показывает честное пустое состояние и не предлагает матрицу неуполномоченному участнику', () => {
  render(
    <Index members={{ data: [], current_page: 1, last_page: 1, total: 0 }} canViewRoles={false} />,
  );
  expect(screen.getByText('Участников пока нет')).toBeTruthy();
  expect(screen.queryByRole('link', { name: 'Роли и доступ' })).toBeNull();
});

it('отличает пустую страницу от отсутствия участников', () => {
  render(
    <Index members={{ data: [], current_page: 3, last_page: 2, total: 27 }} canViewRoles={false} />,
  );
  expect(screen.getByText('На этой странице нет участников')).toBeTruthy();
  expect(screen.getByText('Вернитесь на предыдущую страницу.')).toBeTruthy();
});

it('показывает загрузку, ошибку перехода и повторяет нужную страницу', () => {
  render(<Index members={{ ...members, last_page: 2, total: 27 }} canViewRoles />);
  fireEvent.click(screen.getByRole('button', { name: 'Вперёд' }));
  expect(screen.getByRole('status').textContent).toContain('Загружаем');
  expect(calls.get.mock.calls[0][0]).toBe('/team');
  expect(calls.get.mock.calls[0][1]).toEqual({ page: 2 });
  const options = calls.get.mock.calls[0][2];
  act(() => {
    options.onNetworkError();
    options.onFinish();
  });
  expect(screen.getByRole('alert').textContent).toContain('Не удалось загрузить');
  fireEvent.click(screen.getByRole('button', { name: 'Повторить' }));
  expect(calls.get.mock.calls[1][1]).toEqual({ page: 2 });
});

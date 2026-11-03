import { cleanup, render, screen, within } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, expect, it, vi } from 'vitest';
import Show from '@/pages/Shell/Team/Show';
import type { TeamMemberDetail } from '@/types/Shell/Team/members';

vi.mock('@inertiajs/react', () => ({
  Head: () => null,
  useForm: (data: unknown) => ({ data, errors: {}, processing: false, post: vi.fn(), setData: vi.fn(), reset: vi.fn() }),
  Link: ({ href, children, ...props }: { href: string; children: ReactNode }) => (
    <a href={href} {...props}>
      {children}
    </a>
  ),
}));
vi.mock('@/layouts/CabinetLayout', () => ({
  default: ({ children }: { children: ReactNode }) => <main>{children}</main>,
}));
afterEach(cleanup);

it('показывает контакты и фактический доступ без действий изменения', () => {
  const member: TeamMemberDetail = {
    id: 'id',
    name: 'Анна',
    email: 'anna@example.test',
    phone: '+7 999 123-45-67',
    role: 'manager',
    role_label: 'Менеджер',
    status: 'disabled',
    brands: [{ id: 'a', name: 'Разрешённый бренд', modules: ['seller'] }],
  };
  render(<Show member={member} activityUrl="/journal?actor=user-id" />);
  expect(screen.getByRole('heading', { name: 'Анна' })).toBeTruthy();
  expect(screen.getByText('Отключён')).toBeTruthy();
  expect(
    within(screen.getByRole('region', { name: 'Роль и доступ' })).getByText('Разрешённый бренд'),
  ).toBeTruthy();
  expect(screen.getByText('+7 999 123-45-67')).toBeTruthy();
  expect(screen.queryByRole('button', { name: /сохранить|отключить|пригласить/i })).toBeNull();
  const activity = screen.getByRole('region', { name: 'Активность' });
  expect(
    within(activity)
      .getByRole('link', { name: 'Посмотреть активность участника' })
      .getAttribute('href'),
  ).toBe('/journal?actor=user-id');
  expect(
    within(activity).getByText(/только текущего бренда и общие действия организации/),
  ).toBeTruthy();
});

it('не выдумывает отсутствующие контакт, роль и бренды', () => {
  render(
    <Show
      activityUrl={null}
      member={{
        id: 'id',
        name: null,
        email: null,
        phone: null,
        role: null,
        role_label: null,
        status: 'unknown',
        brands: [],
      }}
    />,
  );
  expect(screen.getByRole('heading', { name: 'Имя недоступно' })).toBeTruthy();
  expect(screen.getByText('Статус недоступен')).toBeTruthy();
  expect(screen.getByText('Роль не определена')).toBeTruthy();
  expect(screen.getByText('Нет общих доступных брендов')).toBeTruthy();
  expect(screen.getByText('Не указан')).toBeTruthy();
  expect(screen.queryByRole('link', { name: 'Посмотреть активность участника' })).toBeNull();
});

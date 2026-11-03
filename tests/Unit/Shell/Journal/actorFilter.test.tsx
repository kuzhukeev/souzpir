import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, expect, it, vi } from 'vitest';
import Actions from '@/pages/Shell/Journal/Actions';

const calls = vi.hoisted(() => ({ get: vi.fn() }));
vi.mock('@inertiajs/react', () => ({
  Head: () => null,
  usePage: () => ({ props: { brand: null } }),
  Link: ({ href, children, ...props }: { href: string; children: ReactNode }) => (
    <a href={href} {...props}>
      {children}
    </a>
  ),
  router: { get: calls.get, on: () => () => {} },
}));
vi.mock('@/layouts/CabinetLayout', () => ({
  default: ({ children }: { children: ReactNode }) => <main>{children}</main>,
}));
afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

const rows = { data: [], current_page: 1, last_page: 1, total: 0 };
const actors = [{ id: '01J00000000000000000000001', name: 'Имя из журнала' }];
const hidden = '01J00000000000000000000002';

it('показывает нейтральную подпись отсутствующего участника и сбрасывает фильтр', () => {
  render(
    <Actions
      rows={rows}
      filters={{ actor: hidden }}
      actors={actors}
      actions={[]}
      canNotifications={false}
    />,
  );
  const filter = screen.getByRole('combobox', { name: 'Участник' });
  expect(filter.textContent).toContain('Выбранный участник');
  expect(filter.textContent).not.toContain(hidden);
  expect(screen.getByText('По выбранным фильтрам действий нет')).toBeTruthy();
  fireEvent.click(screen.getByRole('button', { name: 'Сбросить' }));
  expect(calls.get).toHaveBeenCalledWith(
    '/journal',
    {},
    expect.objectContaining({ preserveState: true }),
  );
});

it('сохраняет известное имя и подпись всех участников при отсутствии фильтра', () => {
  const { rerender } = render(
    <Actions
      rows={rows}
      filters={{ actor: actors[0].id }}
      actors={actors}
      actions={[]}
      canNotifications={false}
    />,
  );
  expect(screen.getByRole('combobox', { name: 'Участник' }).textContent).toContain(
    'Имя из журнала',
  );
  expect(screen.queryByText('Выбранный участник')).toBeNull();
  rerender(
    <Actions rows={rows} filters={{}} actors={actors} actions={[]} canNotifications={false} />,
  );
  expect(screen.getByRole('combobox', { name: 'Участник' }).textContent).toContain('Все');
});

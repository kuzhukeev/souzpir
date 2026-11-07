import { cleanup, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { ReactNode } from 'react';
import Attention from './Attention';
import Notifications from '../Notifications/Index';

vi.mock('@/layouts/CabinetLayout', () => ({ default: ({ children }: { children: ReactNode }) => <div>{children}</div> }));
vi.mock('@inertiajs/react', () => ({ Head: () => null, Link: ({ children, href }: { children: ReactNode; href: string }) => <a href={href}>{children}</a>, router: { post: vi.fn() } }));
afterEach(cleanup);

describe('home attention and notification center', () => {
  it('filters attention by severity and search and resets', async () => {
    const user = userEvent.setup();
    render(<Attention seller={{ attention: [{ kind: 'tasks', title: 'Просрочено', count: 2, severity: 'high', href: '/seller/tasks' }, { kind: 'channels', title: 'Канал выключен', count: 1, severity: 'normal', href: '/seller/channels' }] }} />);
    await user.selectOptions(screen.getByLabelText('Важность'), 'high');
    expect(screen.getByText(/Просрочено/)).toBeTruthy();
    expect(screen.queryByText(/Канал выключен/)).toBeNull();
    await user.click(screen.getByRole('button', { name: 'Сбросить' }));
    expect(screen.getByText(/Канал выключен/)).toBeTruthy();
  });

  it('filters notifications and offers an empty state', async () => {
    const user = userEvent.setup();
    render(<Notifications items={[{ id: 'one', kind: 'event', group: 'seller', title: 'Задача', read: false, created_at: '2026-11-07T00:00:00Z' }]} unread={1} />);
    await user.click(screen.getByRole('button', { name: 'Видение' }));
    expect(screen.queryByText('Задача')).toBeNull();
    expect(screen.getByText('Уведомлений пока нет.')).toBeTruthy();
    await user.click(screen.getByRole('button', { name: 'Показать все уведомления' }));
    expect(screen.getByText('Задача')).toBeTruthy();
  });
});

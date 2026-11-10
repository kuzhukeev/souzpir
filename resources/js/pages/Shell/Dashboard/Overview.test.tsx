import { cleanup, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { ReactNode } from 'react';
import Attention from './Attention';
import Notifications from '../Notifications/Index';

vi.mock('@/layouts/CabinetLayout', () => ({ default: ({ children }: { children: ReactNode }) => <div>{children}</div> }));
vi.mock('@inertiajs/react', () => ({ Head: () => null, Link: ({ children, href }: { children: ReactNode; href: string }) => <a href={href}>{children}</a>, router: { post: vi.fn(), get: vi.fn() }, usePage: () => ({ props: { can: {} } }) }));
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

  it('shows mixed digests in both module filters', async () => {
    const user = userEvent.setup();
    render(<Notifications items={[{ id: 'digest', kind: 'digest', group: 'seller', groups: ['seller', 'vision'], title: 'Сводка', read: false, created_at: '2026-11-07T00:00:00Z' }]} unread={1} />);
    await user.click(screen.getByRole('button', { name: 'Видение' }));
    expect(screen.getByText('Сводка')).toBeTruthy();
    await user.click(screen.getByRole('button', { name: 'Продавец' }));
    expect(screen.getByText('Сводка')).toBeTruthy();
  });
});

describe('home overview chart and KPI', () => {
  it('renders axes, problem bars, KPI deltas and groups the chart by weeks', async () => {
    const { default: Index } = await import('./Index');
    const daily = Object.fromEntries(Array.from({ length: 14 }, (_, i) => [`2026-10-${String(15 + i).padStart(2, '0')}`, i + 1]));
    const seller = { days: 14, total: 105, contact: 10, handoff: 3, quality: 4.6, delta: { total: 12, contact: -2, handoff: 0, quality: 0.3 }, daily, daily_contacts: daily, topics: {},
      issue_details: [{ type: 'Цены', share: 32, delta: 6, href: '/seller/quality' }, { type: 'Склад', share: 18, delta: -4, href: '/seller/quality' }], attention: [], channels: [] };
    const user = userEvent.setup();
    render(<Index homeLinks={[]} seller={seller} vision={null} nextMeasurement={null} canVision={false} visionError={false} usage={{}} usageError={false} items={[]} unread={0} />);
    expect(screen.getByText('+0,3')).toBeTruthy();
    expect(screen.getByText('-2')).toBeTruthy();
    expect(screen.getAllByText('К предыдущим 14 дням')).toHaveLength(4);
    expect(screen.getByText('15 окт')).toBeTruthy();
    expect(screen.getByText('+6 п.п.')).toBeTruthy();
    expect(screen.getByText('-4 п.п.')).toBeTruthy();
    await user.selectOptions(screen.getByLabelText('Группировка графика'), 'week');
    expect(screen.getByText('15 окт–21 окт')).toBeTruthy();
    expect(screen.getByText('22 окт–28 окт')).toBeTruthy();
  });
});

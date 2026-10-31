import { cleanup, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { ReactNode } from 'react';
import type { PlannedData } from '@/types/Seller/Channels/Planned';
import Index from '@/pages/Seller/Channels/Planned/Index';

vi.mock('@/layouts/CabinetLayout', () => ({ default: ({ children }: { children: ReactNode }) => <main>{children}</main> }));
vi.mock('@inertiajs/react', () => ({
  Head: () => null,
  Link: ({ children, href, className }: { children: ReactNode; href: string; className?: string }) => <a href={href} className={className}>{children}</a>,
}));

const planned: PlannedData = {
  brandId: 'brand-a',
  items: [
    { key: 'vk', label: 'ВКонтакте', description: 'Канал сообщества', interested: false },
    { key: 'whatsapp', label: 'WhatsApp', description: 'WABA', interested: false },
    { key: 'calendar', label: 'Календарь', description: 'Запись на звонок', interested: false },
    { key: 'amocrm', label: 'amoCRM', description: 'Коннектор', interested: false },
  ],
  canManage: true,
  interestAvailable: false,
  reminders: { enabled: false, available: false, reason: 'Источник даты пока недоступен.' },
};

afterEach(() => { cleanup(); vi.unstubAllGlobals(); });

describe('planned channels and integrations', () => {
  it('marks each future channel as unavailable and does not offer an unavailable interest action', () => {
    const view = render(<Index section="vk" planned={planned} />);
    expect(screen.getByText('Скоро')).toBeTruthy();
    expect(screen.getByText('Подключение канала пока недоступно.')).toBeTruthy();
    expect((screen.getByRole('button', { name: 'Сообщить, когда появится' }) as HTMLButtonElement).disabled).toBe(true);
    expect(screen.getByText('Сохранение интереса пока недоступно.')).toBeTruthy();
    view.rerender(<Index section="whatsapp" planned={planned} />);
    expect(screen.getByText('Скоро')).toBeTruthy();
    expect(screen.getByText(/WhatsApp Business API/)).toBeTruthy();
    expect(screen.queryByText(/подключено/i)).toBeNull();
  });

  it('keeps unavailable reminders disabled with an explanation', () => {
    render(<Index section="calendar" planned={planned} />);
    expect((screen.getByRole('switch', { name: 'Напоминания на почту менеджеру' }) as HTMLButtonElement).disabled).toBe(true);
    expect((screen.getByRole('button', { name: 'Сохранить изменения' }) as HTMLButtonElement).disabled).toBe(true);
    expect(screen.getByText('Источник даты пока недоступен.')).toBeTruthy();
  });

  it('renders planned integrations as future, never as connected', () => {
    render(<Index section="planned" planned={planned} />);
    expect(screen.getByRole('heading', { name: 'amoCRM' })).toBeTruthy();
    expect(screen.getByText('Скоро')).toBeTruthy();
    expect(screen.getByRole('link', { name: /ВКонтакте/ }).getAttribute('href')).toBe('/seller/channels/vk');
    expect(screen.getByRole('link', { name: /WhatsApp/ }).getAttribute('href')).toBe('/seller/channels/whatsapp');
    expect(screen.getByRole('link', { name: /Почта и календарь/ }).getAttribute('href')).toBe('/seller/integrations/calendar');
    expect(screen.queryByText(/подключено/i)).toBeNull();
  });

  it('reports errors and never claims interest saved after a failed or malformed response', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response('<html>login</html>', { status: 200 })));
    render(<Index section="vk" planned={{ ...planned, interestAvailable: true }} />);
    await userEvent.click(screen.getByRole('button', { name: 'Сообщить, когда появится' }));
    await waitFor(() => expect(screen.getByRole('alert').textContent).toContain('Не удалось подтвердить'));
    expect(screen.queryByText(/Интерес сохранён/)).toBeNull();
  });

  it('shows a saved interest only after the server confirms the same integration', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(JSON.stringify({ data: { integration: 'vk', interested: true } }), { status: 200 })));
    render(<Index section="vk" planned={{ ...planned, interestAvailable: true }} />);
    await userEvent.click(screen.getByRole('button', { name: 'Сообщить, когда появится' }));
    await waitFor(() => expect(screen.getByRole('status').textContent).toContain('Уведомления о запуске пока не отправляются'));
    expect(fetch).toHaveBeenCalledWith('/seller/planned/interests', expect.objectContaining({ method: 'POST', credentials: 'same-origin', body: JSON.stringify({ integration: 'vk' }), headers: expect.objectContaining({ 'X-Brand-Id': 'brand-a' }) }));
  });

  it('does not carry a locally saved state into new server props for another brand', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(JSON.stringify({ data: { integration: 'vk', interested: true } }), { status: 200 })));
    const view = render(<Index section="vk" planned={{ ...planned, interestAvailable: true }} />);
    await userEvent.click(screen.getByRole('button', { name: 'Сообщить, когда появится' }));
    await waitFor(() => expect(screen.getByRole('status').textContent).toContain('Интерес сохранён'));
    const otherBrand = { ...planned, brandId: 'brand-b', items: planned.items.map((item) => ({ ...item })), interestAvailable: true };
    view.rerender(<Index section="vk" planned={otherBrand} />);
    expect(screen.getByRole('button', { name: 'Сообщить, когда появится' })).toBeTruthy();
    expect(screen.queryByText(/Интерес сохранён/)).toBeNull();
  });

  it('shows brand mismatch and never claims the interest was saved', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response('', { status: 404 })));
    render(<Index section="vk" planned={{ ...planned, interestAvailable: true }} />);
    await userEvent.click(screen.getByRole('button', { name: 'Сообщить, когда появится' }));
    await waitFor(() => expect(screen.getByRole('alert').textContent).toContain('Бренд или доступ изменились'));
    expect(screen.queryByText(/Интерес сохранён/)).toBeNull();
  });
});

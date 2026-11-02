import { cleanup, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { router } from '@inertiajs/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { ReactNode } from 'react';
import Index from '@/pages/Seller/Dialogs/Index';

vi.mock('@/layouts/CabinetLayout', () => ({ default: ({ children }: { children: ReactNode }) => <main>{children}</main> }));
vi.mock('@inertiajs/react', () => ({
  Head: () => null,
  usePage: () => ({ props: { can: { 'seller.dialogues.operate': true } } }),
  router: { get: vi.fn(), post: vi.fn() },
}));

afterEach(() => { cleanup(); vi.clearAllMocks(); });

describe('лента диалогов', () => {
  it('показывает сохранённые диалоги и передаёт выбранный фильтр в маршрут', async () => {
    render(<Index dialogs={[{ id: '01J00000000000000000000001', name: 'Мария', mode: 'bot', stage: 'open', outcome: null, channel: 'telegram', last_message: 'Добрый день', updated_at: '2026-11-01T12:00:00Z', assigned_user_id: null }]} dialog={null} filters={{ mode: 'all', search: '' }} />);
    expect(screen.getByText('Мария')).toBeTruthy();
    expect(screen.getByText('Добрый день')).toBeTruthy();
    await userEvent.click(screen.getByRole('button', { name: 'У бота' }));
    expect(router.get).toHaveBeenCalledWith('/seller/dialogs', { mode: 'bot', search: '' }, expect.any(Object));
  });

  it('показывает пустое состояние', () => {
    render(<Index dialogs={[]} dialog={null} filters={{ mode: 'all', search: '' }} />);
    expect(screen.getByText('Диалогов пока нет.')).toBeTruthy();
  });
});

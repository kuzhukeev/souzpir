import { cleanup, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { afterEach, expect, it, vi } from 'vitest';
import Notifications from './Notifications';

vi.mock('@/layouts/CabinetLayout', () => ({ default: ({ children }: { children: ReactNode }) => <div>{children}</div> }));
vi.mock('@inertiajs/react', () => ({ Head: () => null, Link: ({ children, href }: { children: ReactNode; href: string }) => <a href={href}>{children}</a>, router: { put: vi.fn(), post: vi.fn() } }));
import { router } from '@inertiajs/react';
afterEach(() => { cleanup(); vi.clearAllMocks(); });

it('adds, validates, removes and saves email chips', async () => {
  const user = userEvent.setup();
  render(<Notifications telegram_configured={false} telegram_mask={null} report_emails={['first@example.ru']} matrix={{}} />);
  const input = screen.getByLabelText('Адреса электронной почты');
  await user.type(input, 'second@example.ru{enter}');
  expect(screen.getByText('second@example.ru')).toBeTruthy();
  await user.type(input, 'SECOND@example.ru{enter}');
  expect(screen.getByRole('alert').textContent).toContain('уже добавлен');
  await user.clear(input);
  await user.click(screen.getByRole('button', { name: 'Удалить first@example.ru' }));
  await user.click(screen.getByRole('button', { name: 'Сохранить' }));
  expect(router.put).toHaveBeenCalledWith('/integrations/notifications', expect.objectContaining({ report_emails: ['second@example.ru'] }), expect.any(Object));
});

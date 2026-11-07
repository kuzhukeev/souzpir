import { cleanup, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { afterEach, expect, it, vi } from 'vitest';
import Crm from './Index';
import { router } from '@inertiajs/react';

vi.mock('@/layouts/CabinetLayout', () => ({ default: ({ children }: { children: ReactNode }) => <div>{children}</div> }));
vi.mock('@inertiajs/react', () => ({ Head: () => null, Link: ({ children, href }: { children: ReactNode; href: string }) => <a href={href}>{children}</a>, router: { post: vi.fn() } }));
afterEach(() => { cleanup(); vi.clearAllMocks(); });

it('saves selected source fields and rejects duplicates', async () => {
  const user = userEvent.setup();
  render(<Crm canManage webhooks={[{ id: 'hook', events: ['seller.contact.captured'], mappings: { name: 'full_name', phone: 'number', email: 'mail' }, deliveries: [] }]} />);
  await user.selectOptions(screen.getByLabelText('Поле EYON 1'), 'phone');
  await user.click(screen.getByRole('button', { name: 'Сохранить сопоставление' }));
  expect(screen.getByText('Выберите разные поля EYON.')).toBeTruthy();
  expect(router.post).not.toHaveBeenCalled();
  await user.selectOptions(screen.getByLabelText('Поле EYON 2'), 'name');
  await user.click(screen.getByRole('button', { name: 'Сохранить сопоставление' }));
  expect(router.post).toHaveBeenCalledWith('/seller/integrations/crm/hook/mappings', { fields: { phone: 'full_name', name: 'number', email: 'mail' } }, expect.any(Object));
});

import { cleanup, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, expect, it, vi } from 'vitest';
import InvitationDialog from './InvitationDialog';

vi.mock('@inertiajs/react', () => ({ usePage: () => ({ version: 'test' }), router: { reload: vi.fn(), on: () => () => {} } }));
afterEach(cleanup);

it('offers role radio buttons and explains unavailable module grants', async () => {
  const user = userEvent.setup();
  render(<InvitationDialog open onClose={vi.fn()} canIssue brandId="brand" revision="revision" brands={[{ id: 'brand', name: 'Бренд' }]} />);
  const manager = screen.getByRole('radio', { name: /^Менеджер/ });
  await user.click(manager);
  expect((manager as HTMLInputElement).checked).toBe(true);
  expect(screen.getByText(/Отдельные разрешения по модулям пока недоступны/)).toBeTruthy();
  expect((screen.getByRole('checkbox', { name: 'Продавец' }) as HTMLInputElement).disabled).toBe(true);
});

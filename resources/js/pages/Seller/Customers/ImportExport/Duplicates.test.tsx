import { cleanup, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { ReactNode } from 'react';
import Duplicates from '@/pages/Seller/Customers/ImportExport/Duplicates';

vi.mock('@/layouts/CabinetLayout', () => ({ default: ({ children }: { children: ReactNode }) => <main>{children}</main> }));
vi.mock('@inertiajs/react', () => ({
  Head: () => null,
  Link: ({ children }: { children: ReactNode }) => <a>{children}</a>,
  router: { post: vi.fn() },
}));

const pairs = [
  { first: { id: 'a', name: 'Алексей Смирнов', phone: '+79991234567', email: null, city: null }, second: { id: 'b', name: 'А. Смирнов', phone: '+79991234567', email: null, city: null }, reason: 'Телефон' },
  { first: { id: 'c', name: 'Ольга Соколова', phone: null, email: 'olga@example.test', city: null }, second: { id: 'd', name: 'Ольга С.', phone: null, email: 'olga@example.test', city: null }, reason: 'Почта' },
];

afterEach(() => { cleanup(); vi.clearAllMocks(); });

describe('дубли клиентов: фильтр совпадений', () => {
  it('показывает плашку и все пары по умолчанию', () => {
    render(<Duplicates pairs={pairs} contacts={[]} status={null} />);
    expect(screen.getByText('Совпадения по телефону, почте или имени и городу')).toBeTruthy();
    expect(screen.getByText('Совпадает: Телефон')).toBeTruthy();
    expect(screen.getByText('Совпадает: Почта')).toBeTruthy();
  });

  it('фильтрует пары по выбранной причине совпадения', async () => {
    render(<Duplicates pairs={pairs} contacts={[]} status={null} />);
    await userEvent.selectOptions(screen.getByLabelText('Причина совпадения'), 'Совпадает телефон');
    expect(screen.getByText('Совпадает: Телефон')).toBeTruthy();
    expect(screen.queryByText('Совпадает: Почта')).toBeNull();
  });

  it('показывает фирменную пустую подсказку без дублей', () => {
    render(<Duplicates pairs={[]} contacts={[]} status={null} />);
    expect(screen.getByText('Совпадений по телефону, почте или имени и городу не найдено.')).toBeTruthy();
    expect(screen.queryByText('Совпадения по телефону, почте или имени и городу')).toBeNull();
  });
});

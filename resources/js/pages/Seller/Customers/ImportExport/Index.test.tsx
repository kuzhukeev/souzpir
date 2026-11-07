import { cleanup, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { ReactNode } from 'react';
import Index from '@/pages/Seller/Customers/ImportExport/Index';

vi.mock('@/layouts/CabinetLayout', () => ({ default: ({ children }: { children: ReactNode }) => <main>{children}</main> }));
vi.mock('@inertiajs/react', async () => {
  const { useState } = await import('react');
  return {
    Head: () => null,
    Link: ({ children }: { children: ReactNode }) => <a>{children}</a>,
    useForm: (initial: Record<string, unknown>) => {
      const [data, setData] = useState(initial);
      return { data, errors: {}, processing: false, setData: (name: string, value: unknown) => setData((previous) => ({ ...previous, [name]: value })), post: vi.fn(), transform: vi.fn() };
    },
  };
});

afterEach(() => { cleanup(); vi.clearAllMocks(); });

describe('импорт клиентов: зона загрузки CSV', () => {
  it('показывает dropzone с кнопкой выбора файла и подпись выбранного файла', async () => {
    render(<Index preview={null} status={null} />);
    expect(screen.getByText('Перетащите CSV-файл сюда или')).toBeTruthy();
    const file = new File(['Имя\nАнна'], 'clients.csv', { type: 'text/csv' });
    const input = document.querySelector('#customer-csv-file') as HTMLInputElement;
    await userEvent.upload(input, file);
    expect(screen.getByText('clients.csv')).toBeTruthy();
  });

  it('открывает выбор файла по кнопке «Выбрать файл»', async () => {
    render(<Index preview={null} status={null} />);
    const input = document.querySelector('#customer-csv-file') as HTMLInputElement;
    const click = vi.spyOn(input, 'click');
    await userEvent.click(screen.getByRole('button', { name: 'Выбрать файл' }));
    expect(click).toHaveBeenCalled();
  });
});

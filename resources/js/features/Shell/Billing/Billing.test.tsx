import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, expect, it, vi } from 'vitest';
import type { ReactNode } from 'react';
import BillingIndex from '@/pages/Shell/Billing/Index';
import type { BillingProps } from '@/types/Shell/Billing';

const calls = vi.hoisted(() => ({ put: vi.fn(), post: vi.fn(), get: vi.fn() }));
vi.mock('@inertiajs/react', async () => {
  const { useState } = await import('react');
  return {
    Head: () => null,
    Link: ({ href, children, ...props }: { href: string; children: ReactNode }) => <a href={href} {...props}>{children}</a>,
    router: { get: calls.get, post: calls.post },
    useForm: <T extends Record<string, string>>(initial: T) => {
      const [data, setDataState] = useState(initial);
      return { data, errors: {}, processing: false, setData: (key: keyof T, value: string) => setDataState((old) => ({ ...old, [key]: value })), put: (url: string) => calls.put(url, data) };
    },
  };
});
vi.mock('@/layouts/CabinetLayout', () => ({ default: ({ children }: { children: ReactNode }) => <main>{children}</main> }));
afterEach(() => { cleanup(); vi.clearAllMocks(); });
const base: BillingProps = {
  tab: 'overview', currentPlan: null, limits: [], usage: [], plans: [],
  payer: { legal_name: '', inn: '', kpp: '', address: '', email: '' },
  canManage: true, requestEnabled: false, period: { from: '2026-10-01', to: '2026-10-31' },
};
it('различает реальный ноль и недоступный источник', () => {
  render(<BillingIndex {...base} tab="usage" usage={[
    { key: 'seller.dialogues', label: 'Диалоги', value: 0, unit: 'count', status: 'ready' },
    { key: 'vision.measurements', label: 'Замеры', value: null, unit: 'count', status: 'unavailable' },
  ]} />);
  expect(within(screen.getByText('Диалоги').parentElement!).getByText('0')).toBeTruthy();
  expect(within(screen.getByText('Замеры').parentElement!).getByText('Недоступно')).toBeTruthy();
});
it('не придумывает тарифы, цены и лимиты и не отправляет отключённую заявку', async () => {
  render(<BillingIndex {...base} tab="plans" plans={[{ id: 'a', name: 'Настроенный план' }]} />);
  expect(screen.getByText('Настроенный план')).toBeTruthy();
  const button = screen.getByRole('button', { name: 'Заявка скоро появится' }) as HTMLButtonElement;
  expect(button.disabled).toBe(true);
  await userEvent.click(button);
  expect(calls.post).not.toHaveBeenCalled();
  expect(screen.queryByText(/₽|руб\.|стоимость/i)).toBeNull();
});
it('передаёт введённые реквизиты и поддерживает клавиатуру', async () => {
  const user = userEvent.setup();
  render(<BillingIndex {...base} tab="payer" />);
  await user.click(screen.getByLabelText('Юридическое название'));
  await user.keyboard('ООО Тест');
  await user.tab();
  expect(document.activeElement).toBe(screen.getByLabelText('ИНН'));
  await user.keyboard('1234567890');
  fireEvent.submit(screen.getByRole('button', { name: 'Сохранить реквизиты' }).closest('form')!);
  expect(calls.put).toHaveBeenCalledWith('/billing/payer', expect.objectContaining({ legal_name: 'ООО Тест', inn: '1234567890' }));
});
it('показывает ненастроенный лимит без ложного прогресса', () => {
  render(<BillingIndex {...base} limits={[{ key: 'x', label: 'Лимит', value: null, unit: 'count', used: null }]} />);
  expect(within(screen.getByText('Лимит').closest('li')!).getByText(/не задано/)).toBeTruthy();
  expect(screen.queryByRole('progressbar')).toBeNull();
});
it('не показывает разделы владельца пользователю с доступом только на чтение', () => {
  render(<BillingIndex {...base} canManage={false} payer={null} />);
  expect(screen.getByRole('link', { name: 'Использование' })).toBeTruthy();
  expect(screen.queryByRole('link', { name: 'Смена тарифа' })).toBeNull();
  expect(screen.queryByRole('link', { name: 'Реквизиты' })).toBeNull();
});
it('показывает ошибку при загрузке периода использования', () => {
  calls.get.mockImplementationOnce((_url, _data, options) => options.onError({ to: 'Дата окончания раньше даты начала.' }));
  render(<BillingIndex {...base} tab="usage" />);
  fireEvent.submit(screen.getByRole('button', { name: 'Показать' }).closest('form')!);
  expect(calls.get).toHaveBeenCalledWith('/billing/usage', { from: '2026-10-01', to: '2026-10-31' }, expect.any(Object));
  expect(screen.getByRole('alert').textContent).toContain('Дата окончания раньше даты начала.');
});

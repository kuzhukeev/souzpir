import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, expect, it, vi } from 'vitest';
import type { ReactNode } from 'react';
import BillingIndex from '@/pages/Shell/Billing/Index';
import type { BillingProps } from '@/types/Shell/Billing';
import { deliveryMessages } from './request';
import { rememberPlanRequest } from './submission';

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
afterEach(() => { cleanup(); sessionStorage.clear(); vi.restoreAllMocks(); vi.unstubAllGlobals(); vi.clearAllMocks(); });
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

const plan1 = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
const plan2 = '01ARZ3NDEKTSV4RRFFQ69G5FAW';
const key1 = '01ARZ3NDEKTSV4RRFFQ69G5FAX';
const key2 = '01ARZ3NDEKTSV4RRFFQ69G5FAY';
const enabled: BillingProps = { ...base, tab: 'plans', requestEnabled: true, submissionScope: 'org-actor-test', submissionKey: key1, plans: [{ id: plan1, name: 'Первый план' }, { id: plan2, name: 'Второй план' }] };
it('после потери ответа повторяет тот же план и ключ даже после перезагрузки', async () => {
  const user = userEvent.setup();
  const fetchMock = vi.fn().mockRejectedValueOnce(new Error('Сеть недоступна')).mockResolvedValueOnce({ ok: true, json: async () => ({ data: { ticket_id: plan1, created: false, delivery_status: 'uncertain', next_submission_key: key2 } }) });
  vi.stubGlobal('fetch', fetchMock);
  const page = render(<BillingIndex {...enabled} />);
  await user.click(within(screen.getByText('Первый план').closest('article')!).getByRole('button'));
  expect(await screen.findByRole('alert')).toBeTruthy();
  expect((within(screen.getByText('Второй план').closest('article')!).getByRole('button') as HTMLButtonElement).disabled).toBe(true);
  page.unmount();
  render(<BillingIndex {...enabled} submissionKey={key2} />);
  await user.click(screen.getByRole('button', { name: 'Повторить заявку' }));
  expect((await screen.findByRole('status')).textContent).toContain('Ранее созданная заявка найдена.');
  expect(screen.getByRole('status').textContent).toContain('Статус отправки уведомления неизвестен.');
  expect(fetchMock).toHaveBeenCalledTimes(2);
  for (const [, options] of fetchMock.mock.calls) expect(JSON.parse(options.body)).toEqual({ plan_id: plan1, submission_key: key1 });
});
it('не отправляет заявку, если браузер не может сохранить ключ повтора', async () => {
  const user = userEvent.setup();
  const fetchMock = vi.fn();
  vi.stubGlobal('fetch', fetchMock);
  vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => { throw new Error('blocked'); });
  render(<BillingIndex {...enabled} />);
  await user.click(within(screen.getByText('Первый план').closest('article')!).getByRole('button'));
  expect(screen.getByRole('alert').textContent).toContain('Не удаётся сохранить попытку');
  expect(fetchMock).not.toHaveBeenCalled();
});
it('при конфликте не сбрасывает попытку и не отправляет заявку на другой тариф', async () => {
  const user = userEvent.setup();
  const fetchMock = vi.fn().mockResolvedValue({ ok: false, status: 409 });
  vi.stubGlobal('fetch', fetchMock);
  render(<BillingIndex {...enabled} />);
  await user.click(within(screen.getByText('Первый план').closest('article')!).getByRole('button'));
  expect((await screen.findByRole('alert')).textContent).toContain('конфликтует');
  expect(screen.getByRole('button', { name: 'Повторить заявку' })).toBeTruthy();
  expect((within(screen.getByText('Второй план').closest('article')!).getByRole('button') as HTMLButtonElement).disabled).toBe(true);
  expect(fetchMock).toHaveBeenCalledTimes(1);
});
it('отличает отправленное уведомление от выключенного, ненастроенного и неизвестного', () => {
  expect(deliveryMessages.sent).toBe('Уведомление отправлено.');
  expect(deliveryMessages.disabled).toContain('отключена');
  expect(deliveryMessages.not_configured).toContain('не настроено');
  expect(deliveryMessages.uncertain).toContain('неизвестен');
});
it.each([{ plans: [] }, { plans: [{ id: plan2, name: 'Другой план' }] }])('позволяет повторить попытку, когда исходный тариф отсутствует в списке', async ({ plans }) => {
  const user = userEvent.setup();
  rememberPlanRequest('org-actor-test', plan1, key1);
  const fetchMock = vi.fn().mockResolvedValue({ ok: true, json: async () => ({ data: { ticket_id: plan1, created: false, delivery_status: 'disabled', next_submission_key: key2 } }) });
  vi.stubGlobal('fetch', fetchMock);
  render(<BillingIndex {...enabled} plans={plans} />);
  await user.click(screen.getByRole('button', { name: 'Повторить заявку' }));
  expect((await screen.findByRole('status')).textContent).toContain('Отправка уведомлений отключена.');
  expect(JSON.parse(fetchMock.mock.calls[0][1].body)).toEqual({ plan_id: plan1, submission_key: key1 });
});
it('сохраняет подтверждение после перезагрузки до явного начала новой заявки', async () => {
  const user = userEvent.setup();
  const fetchMock = vi.fn().mockResolvedValue({ ok: true, json: async () => ({ data: { ticket_id: plan1, created: true, delivery_status: 'sent', next_submission_key: key2, ignored_private: 'не сохранять' } }) });
  vi.stubGlobal('fetch', fetchMock);
  const page = render(<BillingIndex {...enabled} />);
  await user.click(within(screen.getByText('Первый план').closest('article')!).getByRole('button'));
  expect((await screen.findByRole('status')).textContent).toContain('Заявка создана.');
  expect(sessionStorage.getItem('billing-plan-request:org-actor-test')).not.toContain('не сохранять');
  page.unmount();
  render(<BillingIndex {...enabled} submissionKey={key2} />);
  expect(screen.getByRole('status').textContent).toContain('Уведомление отправлено.');
  expect((within(screen.getByText('Первый план').closest('article')!).getByRole('button') as HTMLButtonElement).disabled).toBe(true);
  expect(fetchMock).toHaveBeenCalledTimes(1);
  await user.click(screen.getByRole('button', { name: 'Новая заявка' }));
  expect(screen.queryByRole('status')).toBeNull();
  expect((within(screen.getByText('Первый план').closest('article')!).getByRole('button') as HTMLButtonElement).disabled).toBe(false);
});

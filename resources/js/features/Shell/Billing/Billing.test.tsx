import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import type { ReactNode } from 'react';
import BillingIndex from '@/pages/Shell/Billing/Index';
import type { BillingProps } from '@/types/Shell/Billing';
import { deliveryMessages } from './request';
import { rememberPlanRequest } from './submission';
import { discardPayerDraft } from './payerDraft';

const calls = vi.hoisted(() => ({ post: vi.fn(), get: vi.fn(), on: vi.fn((...args: [string, (event: Event) => void]) => { void args; return () => {}; }) }));
vi.mock('@inertiajs/react', () => {
  return {
    Head: () => null,
    Link: ({ href, children, ...props }: { href: string; children: ReactNode }) => <a href={href} {...props}>{children}</a>,
    router: { get: calls.get, post: calls.post, on: calls.on },
  };
});
vi.mock('@/layouts/CabinetLayout', () => ({ default: ({ children }: { children: ReactNode }) => <main>{children}</main> }));
beforeEach(() => { vi.spyOn(document, 'hasFocus').mockReturnValue(true); });
afterEach(() => { cleanup(); discardPayerDraft(); sessionStorage.clear(); vi.restoreAllMocks(); vi.unstubAllGlobals(); vi.clearAllMocks(); });
const revision = 'a'.repeat(64);
const base: BillingProps = {
  tab: 'overview', currentPlan: null, limits: [], usage: [], plans: [],
  canManage: true, requestEnabled: false, submissionScope: 'org-actor-brand-test', period: { from: '2026-10-01', to: '2026-10-31' },
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
  const fetchMock = vi.fn().mockResolvedValueOnce({ ok: true, json: async () => ({ data: { legal_name: '', inn: '', kpp: '', address: '', email: '' }, revision }) }).mockResolvedValueOnce({ ok: true, status: 204 }).mockResolvedValueOnce({ ok: true, json: async () => ({ data: { legal_name: 'ООО Тест', inn: '1234567890', kpp: '', address: '', email: '' }, revision: 'b'.repeat(64) }) });
  vi.stubGlobal('fetch', fetchMock);
  render(<BillingIndex {...base} tab="payer" />);
  await user.click(await screen.findByLabelText('Юридическое название'));
  await user.keyboard('ООО Тест');
  await user.tab();
  expect(document.activeElement).toBe(screen.getByLabelText('ИНН'));
  await user.keyboard('1234567890');
  fireEvent.submit(screen.getByRole('button', { name: 'Сохранить реквизиты' }).closest('form')!);
  await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(3));
  const [url, options] = fetchMock.mock.calls[1];
  expect(url).toBe('/billing/payer');
  expect(JSON.parse(options.body)).toEqual(expect.objectContaining({ legal_name: 'ООО Тест', inn: '1234567890' }));
  expect(options.headers['X-Billing-Context']).toBe('org-actor-brand-test');
  expect(options.headers['X-Payer-Revision']).toBe(revision);
  expect(options.cache).toBe('no-store');
  expect(JSON.stringify(window.history.state)).not.toContain('ООО Тест');
});
it('не отображает старые payer props из истории до повторной авторизации', async () => {
  vi.stubGlobal('crypto', undefined);
  vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: false, status: 403 }));
  const stale = { ...base, tab: 'payer' as const, payer: { legal_name: 'Чужие реквизиты', inn: '1234567890', kpp: '', address: '', email: '' } };
  render(<BillingIndex {...stale} />);
  expect(screen.queryByDisplayValue('Чужие реквизиты')).toBeNull();
  expect((await screen.findByRole('alert')).textContent).toContain('Не удалось загрузить');
  expect(screen.queryByDisplayValue('Чужие реквизиты')).toBeNull();
});
it('не загружает реквизиты при открытии скрытой вкладки до возвращения фокуса', async () => {
  const visibility = vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('hidden');
  vi.mocked(document.hasFocus).mockReturnValue(false);
  const fetchMock = vi.fn().mockResolvedValue({ ok: true, json: async () => ({ data: { legal_name: 'Только после возврата', inn: '', kpp: '', address: '', email: '' }, revision }) });
  vi.stubGlobal('fetch', fetchMock);
  render(<BillingIndex {...base} tab="payer" />);
  expect(fetchMock).not.toHaveBeenCalled();
  visibility.mockReturnValue('visible');
  act(() => document.dispatchEvent(new Event('visibilitychange')));
  expect(fetchMock).not.toHaveBeenCalled();
  vi.mocked(document.hasFocus).mockReturnValue(true);
  act(() => window.dispatchEvent(new Event('focus')));
  expect(await screen.findByDisplayValue('Только после возврата')).toBeTruthy();
  expect(fetchMock).toHaveBeenCalledTimes(1);
});
it('не загружает реквизиты в видимой, но неактивной вкладке до фокуса', async () => {
  vi.mocked(document.hasFocus).mockReturnValue(false);
  const fetchMock = vi.fn().mockResolvedValue({ ok: true, json: async () => ({ data: { legal_name: 'Текущий владелец', inn: '', kpp: '', address: '', email: '' }, revision }) });
  vi.stubGlobal('fetch', fetchMock);
  render(<BillingIndex {...base} tab="payer" />);
  expect(fetchMock).not.toHaveBeenCalled();
  vi.mocked(document.hasFocus).mockReturnValue(true);
  act(() => window.dispatchEvent(new Event('focus')));
  expect(await screen.findByDisplayValue('Текущий владелец')).toBeTruthy();
});
it('очищает данные до bfcache и не принимает устаревший ответ после восстановления', async () => {
  let complete: (value: unknown) => void = () => {};
  const deferred = new Promise((resolve) => { complete = resolve; });
  const fetchMock = vi.fn().mockReturnValueOnce(deferred).mockResolvedValueOnce({ ok: true, json: async () => ({ data: { legal_name: 'Актуальные данные', inn: '', kpp: '', address: '', email: '' }, revision }) });
  vi.stubGlobal('fetch', fetchMock);
  render(<BillingIndex {...base} tab="payer" />);
  act(() => window.dispatchEvent(new Event('pagehide')));
  await act(async () => complete({ ok: true, json: async () => ({ data: { legal_name: 'Устаревшие данные', inn: '', kpp: '', address: '', email: '' }, revision }) }));
  expect(screen.queryByDisplayValue('Устаревшие данные')).toBeNull();
  const restored = new Event('pageshow');
  Object.defineProperty(restored, 'persisted', { value: true });
  act(() => window.dispatchEvent(restored));
  expect(await screen.findByDisplayValue('Актуальные данные')).toBeTruthy();
  expect(screen.queryByDisplayValue('Устаревшие данные')).toBeNull();
  expect(fetchMock.mock.calls[0][1].cache).toBe('no-store');
  expect(fetchMock.mock.calls[1][1].headers['X-Billing-Context']).toBe('org-actor-brand-test');
});
it('после скрытия или смены контекста не показывает старые реквизиты', async () => {
  const fetchMock = vi.fn().mockResolvedValueOnce({ ok: true, json: async () => ({ data: { legal_name: 'Прежняя организация', inn: '', kpp: '', address: '', email: '' }, revision }) }).mockResolvedValueOnce({ ok: false, status: 409 });
  vi.stubGlobal('fetch', fetchMock);
  const page = render(<BillingIndex {...base} tab="payer" />);
  expect(await screen.findByDisplayValue('Прежняя организация')).toBeTruthy();
  act(() => window.dispatchEvent(new Event('blur')));
  expect(screen.queryByDisplayValue('Прежняя организация')).toBeNull();
  act(() => window.dispatchEvent(new Event('focus')));
  expect((await screen.findByRole('alert')).textContent).toContain('Не удалось загрузить');
  expect(screen.queryByDisplayValue('Прежняя организация')).toBeNull();
  page.rerender(<BillingIndex {...base} tab="payer" canManage={false} />);
  expect(screen.queryByLabelText('Юридическое название')).toBeNull();
});
it('сохраняет черновик только в памяти при blur и восстанавливает после проверки той же ревизии', async () => {
  const user = userEvent.setup();
  const payer = { legal_name: 'Исходное', inn: '', kpp: '', address: '', email: '' };
  const fetchMock = vi.fn().mockResolvedValue({ ok: true, json: async () => ({ data: payer, revision }) });
  vi.stubGlobal('fetch', fetchMock);
  render(<BillingIndex {...base} tab="payer" />);
  const field = await screen.findByLabelText('Юридическое название');
  await user.clear(field); await user.type(field, 'Несохранённый черновик');
  act(() => window.dispatchEvent(new Event('blur')));
  expect(screen.queryByDisplayValue('Несохранённый черновик')).toBeNull();
  expect(JSON.stringify(window.history.state)).not.toContain('Несохранённый черновик');
  expect(JSON.stringify(sessionStorage)).not.toContain('Несохранённый черновик');
  act(() => window.dispatchEvent(new Event('focus')));
  expect(await screen.findByDisplayValue('Несохранённый черновик')).toBeTruthy();
  expect(fetchMock).toHaveBeenCalledTimes(2);
});
it('не восстанавливает черновик если ревизия изменилась, сообщает об этом', async () => {
  const user = userEvent.setup();
  const fetchMock = vi.fn().mockResolvedValueOnce({ ok: true, json: async () => ({ data: { legal_name: 'Исходное', inn: '', kpp: '', address: '', email: '' }, revision }) }).mockResolvedValueOnce({ ok: true, json: async () => ({ data: { legal_name: 'Обновлённое', inn: '', kpp: '', address: '', email: '' }, revision: 'b'.repeat(64) }) });
  vi.stubGlobal('fetch', fetchMock);
  render(<BillingIndex {...base} tab="payer" />);
  const field = await screen.findByLabelText('Юридическое название');
  await user.clear(field); await user.type(field, 'Черновик');
  act(() => window.dispatchEvent(new Event('blur')));
  act(() => window.dispatchEvent(new Event('focus')));
  expect(await screen.findByDisplayValue('Обновлённое')).toBeTruthy();
  expect(screen.queryByDisplayValue('Черновик')).toBeNull();
  expect(screen.getByRole('alert').textContent).toContain('Несохранённые изменения удалены');
});
it('удаляет скрытый черновик при отказе доступа и предупреждает пользователя', async () => {
  const user = userEvent.setup();
  const fetchMock = vi.fn().mockResolvedValueOnce({ ok: true, json: async () => ({ data: { legal_name: 'Исходное', inn: '', kpp: '', address: '', email: '' }, revision }) }).mockResolvedValueOnce({ ok: false, status: 403 });
  vi.stubGlobal('fetch', fetchMock);
  render(<BillingIndex {...base} tab="payer" />);
  const field = await screen.findByLabelText('Юридическое название');
  await user.clear(field); await user.type(field, 'Черновик');
  act(() => window.dispatchEvent(new Event('blur')));
  act(() => window.dispatchEvent(new Event('focus')));
  expect((await screen.findByRole('alert')).textContent).toContain('Несохранённые изменения удалены');
  expect(screen.queryByDisplayValue('Черновик')).toBeNull();
});
it('сбрасывает черновик при pagehide, даже если до этого был blur', async () => {
  const user = userEvent.setup();
  const fetchMock = vi.fn().mockResolvedValue({ ok: true, json: async () => ({ data: { legal_name: 'Исходное', inn: '', kpp: '', address: '', email: '' }, revision }) });
  vi.stubGlobal('fetch', fetchMock);
  render(<BillingIndex {...base} tab="payer" />);
  const field = await screen.findByLabelText('Юридическое название');
  await user.clear(field); await user.type(field, 'Черновик');
  act(() => window.dispatchEvent(new Event('blur')));
  act(() => window.dispatchEvent(new Event('pagehide')));
  const restored = new Event('pageshow'); Object.defineProperty(restored, 'persisted', { value: true });
  act(() => window.dispatchEvent(restored));
  expect(await screen.findByDisplayValue('Исходное')).toBeTruthy();
  expect(screen.queryByDisplayValue('Черновик')).toBeNull();
});
it('сохраняет черновик только в памяти при SPA back и восстанавливает после проверки контекста и ревизии', async () => {
  const user = userEvent.setup();
  const fetchMock = vi.fn().mockResolvedValue({ ok: true, json: async () => ({ data: { legal_name: 'Исходное', inn: '', kpp: '', address: '', email: '' }, revision }) });
  vi.stubGlobal('fetch', fetchMock);
  const page = render(<BillingIndex {...base} tab="payer" />);
  const field = await screen.findByLabelText('Юридическое название');
  await user.clear(field); await user.type(field, 'Черновик при back');
  page.rerender(<BillingIndex {...base} tab="overview" />);
  expect(screen.queryByDisplayValue('Черновик при back')).toBeNull();
  expect(JSON.stringify(window.history.state)).not.toContain('Черновик при back');
  expect(JSON.stringify(sessionStorage)).not.toContain('Черновик при back');
  page.rerender(<BillingIndex {...base} tab="payer" />);
  expect(await screen.findByDisplayValue('Черновик при back')).toBeTruthy();
  expect(fetchMock).toHaveBeenCalledTimes(2);
});
it('при изменении scope стирает память предыдущего черновика с предупреждением', async () => {
  const user = userEvent.setup();
  const fetchMock = vi.fn().mockResolvedValue({ ok: true, json: async () => ({ data: { legal_name: 'Текущие реквизиты', inn: '', kpp: '', address: '', email: '' }, revision }) });
  vi.stubGlobal('fetch', fetchMock);
  const page = render(<BillingIndex {...base} tab="payer" />);
  const field = await screen.findByLabelText('Юридическое название');
  await user.clear(field); await user.type(field, 'Закрытый черновик');
  page.rerender(<BillingIndex {...base} tab="payer" submissionScope="другой-контекст" />);
  expect(await screen.findByDisplayValue('Текущие реквизиты')).toBeTruthy();
  expect(screen.queryByDisplayValue('Закрытый черновик')).toBeNull();
  expect(screen.getByRole('alert').textContent).toContain('Несохранённые изменения удалены');
});
it('предупреждает о несохранённом вводе при уходе и разрешает отменить SPA-навигацию', async () => {
  const user = userEvent.setup();
  const confirm = vi.fn().mockReturnValueOnce(false).mockReturnValueOnce(true);
  vi.stubGlobal('confirm', confirm);
  vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: true, json: async () => ({ data: { legal_name: 'Исходное', inn: '', kpp: '', address: '', email: '' }, revision }) }));
  render(<BillingIndex {...base} tab="payer" />);
  const field = await screen.findByLabelText('Юридическое название');
  await user.clear(field); await user.type(field, 'Черновик');
  const before = calls.on.mock.calls.find(([name]) => name === 'before')?.[1];
  expect(before).toBeTruthy();
  const cancelled = new Event('before', { cancelable: true });
  act(() => before!(cancelled));
  expect(cancelled.defaultPrevented).toBe(true);
  expect(screen.getByDisplayValue('Черновик')).toBeTruthy();
  const unload = new Event('beforeunload', { cancelable: true });
  act(() => window.dispatchEvent(unload));
  expect(unload.defaultPrevented).toBe(true);
  const accepted = new Event('before', { cancelable: true });
  act(() => before!(accepted));
  expect(accepted.defaultPrevented).toBe(false);
  expect(confirm).toHaveBeenCalledTimes(2);
});
it('не прерывает уход, когда в форме нет несохранённых изменений', async () => {
  const confirm = vi.fn(); vi.stubGlobal('confirm', confirm);
  vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: true, json: async () => ({ data: { legal_name: 'Исходное', inn: '', kpp: '', address: '', email: '' }, revision }) }));
  render(<BillingIndex {...base} tab="payer" />);
  await screen.findByDisplayValue('Исходное');
  const before = calls.on.mock.calls.find(([name]) => name === 'before')?.[1];
  const event = new Event('before', { cancelable: true });
  act(() => before!(event));
  const unload = new Event('beforeunload', { cancelable: true });
  act(() => window.dispatchEvent(unload));
  expect(event.defaultPrevented).toBe(false);
  expect(unload.defaultPrevented).toBe(false);
  expect(confirm).not.toHaveBeenCalled();
});
it('восстанавливает ошибки валидации вместе с черновиком при неизменной ревизии', async () => {
  const user = userEvent.setup();
  const payer = { legal_name: 'Исходное', inn: '', kpp: '', address: '', email: '' };
  const fetchMock = vi.fn().mockResolvedValueOnce({ ok: true, json: async () => ({ data: payer, revision }) }).mockResolvedValueOnce({ ok: false, status: 422, json: async () => ({ errors: { inn: ['Введите корректный ИНН.'] } }) }).mockResolvedValueOnce({ ok: true, json: async () => ({ data: payer, revision }) });
  vi.stubGlobal('fetch', fetchMock);
  render(<BillingIndex {...base} tab="payer" />);
  await user.type(await screen.findByLabelText('ИНН'), '123');
  await user.click(screen.getByRole('button', { name: 'Сохранить реквизиты' }));
  await waitFor(() => expect(screen.getAllByText('Введите корректный ИНН.').length).toBeGreaterThan(0));
  act(() => window.dispatchEvent(new Event('blur')));
  act(() => window.dispatchEvent(new Event('focus')));
  expect(await screen.findByDisplayValue('123')).toBeTruthy();
  expect(screen.getAllByText('Введите корректный ИНН.').length).toBeGreaterThan(0);
});
it('предупреждает о неопределённом сохранении, если blur прервал PUT', async () => {
  const user = userEvent.setup();
  let complete: (value: unknown) => void = () => {};
  const delayed = new Promise((resolve) => { complete = resolve; });
  const payer = { legal_name: 'Исходное', inn: '', kpp: '', address: '', email: '' };
  const fetchMock = vi.fn().mockResolvedValueOnce({ ok: true, json: async () => ({ data: payer, revision }) }).mockReturnValueOnce(delayed).mockResolvedValueOnce({ ok: true, json: async () => ({ data: payer, revision }) });
  vi.stubGlobal('fetch', fetchMock);
  render(<BillingIndex {...base} tab="payer" />);
  const field = await screen.findByLabelText('Юридическое название');
  await user.clear(field); await user.type(field, 'Черновик');
  await user.click(screen.getByRole('button', { name: 'Сохранить реквизиты' }));
  act(() => window.dispatchEvent(new Event('blur')));
  act(() => window.dispatchEvent(new Event('focus')));
  expect(await screen.findByDisplayValue('Исходное')).toBeTruthy();
  expect(screen.getByRole('alert').textContent).toContain('Результат сохранения неизвестен');
  await act(async () => complete({ ok: true, status: 204 }));
  expect(screen.queryByText('Реквизиты сохранены.')).toBeNull();
});
it('не показывает результат старого PUT после ухода и отказа повторной авторизации', async () => {
  const user = userEvent.setup();
  let complete: (value: unknown) => void = () => {};
  const delayed = new Promise((resolve) => { complete = resolve; });
  const fetchMock = vi.fn().mockResolvedValueOnce({ ok: true, json: async () => ({ data: { legal_name: 'Частные реквизиты', inn: '', kpp: '', address: '', email: '' }, revision }) }).mockReturnValueOnce(delayed).mockResolvedValueOnce({ ok: false, status: 403 });
  vi.stubGlobal('fetch', fetchMock);
  render(<BillingIndex {...base} tab="payer" />);
  expect(await screen.findByDisplayValue('Частные реквизиты')).toBeTruthy();
  await user.click(screen.getByRole('button', { name: 'Сохранить реквизиты' }));
  act(() => window.dispatchEvent(new Event('pagehide')));
  expect(screen.queryByDisplayValue('Частные реквизиты')).toBeNull();
  const restored = new Event('pageshow');
  Object.defineProperty(restored, 'persisted', { value: true });
  act(() => window.dispatchEvent(restored));
  expect(await screen.findByRole('alert')).toBeTruthy();
  await act(async () => complete({ ok: true, status: 204 }));
  expect(screen.queryByText('Реквизиты сохранены.')).toBeNull();
  expect(screen.queryByDisplayValue('Частные реквизиты')).toBeNull();
});
it('показывает ошибки валидации и не помещает реквизиты в историю', async () => {
  const user = userEvent.setup();
  const fetchMock = vi.fn().mockResolvedValueOnce({ ok: true, json: async () => ({ data: { legal_name: 'Тест', inn: '', kpp: '', address: '', email: '' }, revision }) }).mockResolvedValueOnce({ ok: false, status: 422, json: async () => ({ errors: { inn: ['ИНН должен содержать 10 или 12 цифр.'] } }) });
  vi.stubGlobal('fetch', fetchMock);
  render(<BillingIndex {...base} tab="payer" />);
  const inn = await screen.findByLabelText('ИНН');
  await user.type(inn, '123');
  await user.click(screen.getByRole('button', { name: 'Сохранить реквизиты' }));
  await waitFor(() => expect(inn.getAttribute('aria-invalid')).toBe('true'));
  expect(screen.getAllByText('ИНН должен содержать 10 или 12 цифр.').length).toBeGreaterThan(0);
  expect(JSON.stringify(window.history.state)).not.toContain('Тест');
});
it('при конфликте ревизии очищает черновик и требует повторной загрузки', async () => {
  const user = userEvent.setup();
  const fetchMock = vi.fn().mockResolvedValueOnce({ ok: true, json: async () => ({ data: { legal_name: 'Исходное', inn: '', kpp: '', address: '', email: '' }, revision }) }).mockResolvedValueOnce({ ok: false, status: 409 }).mockResolvedValueOnce({ ok: true, json: async () => ({ data: { legal_name: 'Обновлённое', inn: '', kpp: '', address: '', email: '' }, revision: 'b'.repeat(64) }) });
  vi.stubGlobal('fetch', fetchMock);
  render(<BillingIndex {...base} tab="payer" />);
  const name = await screen.findByLabelText('Юридическое название');
  await user.clear(name); await user.type(name, 'Черновик');
  await user.click(screen.getByRole('button', { name: 'Сохранить реквизиты' }));
  expect((await screen.findByRole('alert')).textContent).toContain('Загрузите реквизиты заново');
  expect(screen.queryByDisplayValue('Черновик')).toBeNull();
  expect(fetchMock.mock.calls[1][1].headers['X-Payer-Revision']).toBe(revision);
  await user.click(screen.getByRole('button', { name: 'Повторить' }));
  expect(await screen.findByDisplayValue('Обновлённое')).toBeTruthy();
});
it('показывает ненастроенный лимит без ложного прогресса', () => {
  render(<BillingIndex {...base} limits={[{ key: 'x', label: 'Лимит', value: null, unit: 'count', used: null }]} />);
  expect(within(screen.getByText('Лимит').closest('li')!).getByText(/не задано/)).toBeTruthy();
  expect(screen.queryByRole('progressbar')).toBeNull();
});
it('не показывает разделы владельца пользователю с доступом только на чтение', () => {
  render(<BillingIndex {...base} canManage={false} />);
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
  for (const [, options] of fetchMock.mock.calls) {
    expect(JSON.parse(options.body)).toEqual({ plan_id: plan1, submission_key: key1 });
    expect(options.headers['X-Billing-Context']).toBe('org-actor-test');
  }
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
it('при неоднозначном 404 сохраняет прежнюю попытку и не утверждает причину', async () => {
  const user = userEvent.setup();
  vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: false, status: 404 }));
  render(<BillingIndex {...enabled} />);
  await user.click(within(screen.getByText('Первый план').closest('article')!).getByRole('button'));
  expect((await screen.findByRole('alert')).textContent).toContain('Не удалось подтвердить заявку');
  expect(screen.getByRole('button', { name: 'Повторить заявку' })).toBeTruthy();
});
it('принимает lowercase ULID и сохраняет подтверждение после перезагрузки', async () => {
  const user = userEvent.setup();
  const fetchMock = vi.fn().mockResolvedValue({ ok: true, json: async () => ({ data: { ticket_id: plan1.toLowerCase(), created: true, delivery_status: 'sent', next_submission_key: key2.toLowerCase() } }) });
  vi.stubGlobal('fetch', fetchMock);
  const page = render(<BillingIndex {...enabled} plans={[{ id: plan1.toLowerCase(), name: 'Первый план' }]} submissionKey={key1.toLowerCase()} />);
  await user.click(screen.getByRole('button', { name: 'Оставить заявку' }));
  expect((await screen.findByRole('status')).textContent).toContain('Заявка создана.');
  page.unmount();
  render(<BillingIndex {...enabled} />);
  expect(screen.getByRole('status').textContent).toContain('Уведомление отправлено.');
  expect(fetchMock.mock.calls[0][1].headers['X-Billing-Context']).toBe('org-actor-test');
});
it('не принимает тот же ключ следующей попытки даже в другом регистре', async () => {
  const user = userEvent.setup();
  vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: true, json: async () => ({ data: { ticket_id: plan1, created: true, delivery_status: 'sent', next_submission_key: key1.toLowerCase() } }) }));
  render(<BillingIndex {...enabled} />);
  await user.click(within(screen.getByText('Первый план').closest('article')!).getByRole('button'));
  expect((await screen.findByRole('alert')).textContent).toContain('Не удалось подтвердить');
  expect(screen.getByRole('button', { name: 'Повторить заявку' })).toBeTruthy();
});
it('не принимает неполный ответ за подтверждённое создание', async () => {
  const user = userEvent.setup();
  vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: true, json: async () => ({ data: { ticket_id: plan1, created: true, delivery_status: 'sent' } }) }));
  render(<BillingIndex {...enabled} />);
  await user.click(within(screen.getByText('Первый план').closest('article')!).getByRole('button'));
  expect((await screen.findByRole('alert')).textContent).toContain('Не удалось подтвердить');
  expect(screen.getByRole('button', { name: 'Повторить заявку' })).toBeTruthy();
  expect(screen.queryByRole('status')).toBeNull();
});
it('не переносит попытку между областями разных брендов', () => {
  rememberPlanRequest('org-actor-brand-one', plan1, key1);
  render(<BillingIndex {...enabled} submissionScope="org-actor-brand-two" />);
  expect(screen.queryByRole('button', { name: 'Повторить заявку' })).toBeNull();
  expect((within(screen.getByText('Первый план').closest('article')!).getByRole('button') as HTMLButtonElement).disabled).toBe(false);
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

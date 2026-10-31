import { expect, it, vi } from 'vitest';
import { discardPayerDraft, markPayerFormMounted, readPayerDraft, writePayerDraft } from './payerDraft';

type VisitEvent = { detail: { visit: { url: URL } }; preventDefault: () => void };
const events = vi.hoisted(() => ({ start: undefined as undefined | ((event: VisitEvent) => void), before: undefined as undefined | ((event: VisitEvent) => void) }));
vi.mock('@inertiajs/react', () => ({ router: { on: (name: 'before' | 'start', callback: (event: VisitEvent) => void) => { events[name] = callback; return () => {}; } } }));

it('стирает временный черновик при выходе или закрытии, даже когда форма размонтирована', () => {
  const value = { scope: 'opaque', data: { legal_name: 'Черновик', inn: '', kpp: '', address: '', email: '' }, revision: 'a'.repeat(64), errors: {} };
  writePayerDraft(value);
  expect(readPayerDraft()).toEqual(value);
  const unload = new Event('beforeunload', { cancelable: true });
  window.dispatchEvent(unload);
  expect(unload.defaultPrevented).toBe(true);
  const visit = { detail: { visit: { url: new URL('https://example.test/logout') } }, preventDefault: vi.fn() };
  events.start?.(visit);
  expect(readPayerDraft()).toBeNull();
  writePayerDraft(value);
  window.dispatchEvent(new Event('pagehide'));
  expect(readPayerDraft()).toBeNull();
  discardPayerDraft();
});
it('после back запрашивает подтверждение выхода и не дублирует диалог на открытой форме', () => {
  const value = { scope: 'opaque', data: { legal_name: 'Черновик', inn: '', kpp: '', address: '', email: '' }, revision: 'a'.repeat(64), errors: {} };
  const confirm = vi.fn().mockReturnValueOnce(false).mockReturnValueOnce(true);
  vi.stubGlobal('confirm', confirm);
  writePayerDraft(value);
  const visit = { detail: { visit: { url: new URL('https://example.test/logout') } }, preventDefault: vi.fn() };
  events.before?.(visit);
  expect(visit.preventDefault).toHaveBeenCalledTimes(1);
  expect(readPayerDraft()).toEqual(value);
  events.before?.(visit);
  expect(visit.preventDefault).toHaveBeenCalledTimes(1);
  events.start?.(visit);
  expect(readPayerDraft()).toBeNull();
  writePayerDraft(value);
  const unmount = markPayerFormMounted();
  events.before?.(visit);
  expect(confirm).toHaveBeenCalledTimes(2);
  unmount(); discardPayerDraft(); vi.unstubAllGlobals();
});

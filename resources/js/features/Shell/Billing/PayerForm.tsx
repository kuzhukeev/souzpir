import { router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState, type FormEvent } from 'react';
import { flushSync } from 'react-dom';
import { Button, Input, Skeleton } from '@/components/ui';
import { Errors } from '@/features/Shell/Organizations/Shared';
import type { Payer } from '@/types/Shell/Billing';
import { Notice, panel } from './Shared';

const blank = (): Payer => ({ legal_name: '', inn: '', kpp: '', address: '', email: '' });
const fields: (keyof Payer)[] = ['legal_name', 'inn', 'kpp', 'address', 'email'];
const headers = (scope: string): Record<string, string> => {
  const result: Record<string, string> = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-Billing-Context': scope };
  const token = document.cookie.split('; ').find((item) => item.startsWith('XSRF-TOKEN='));
  if (token) result['X-XSRF-TOKEN'] = decodeURIComponent(token.slice(11));
  return result;
};
const readPayer = (value: unknown): { payer: Payer; revision: string } | null => {
  if (!value || typeof value !== 'object' || !('data' in value) || !value.data || typeof value.data !== 'object' || !('revision' in value) || typeof value.revision !== 'string' || !/^[a-f0-9]{64}$/i.test(value.revision)) return null;
  const data = value.data as Record<string, unknown>;
  if (!fields.every((field) => typeof data[field] === 'string')) return null;
  return { payer: { legal_name: data.legal_name as string, inn: data.inn as string, kpp: data.kpp as string, address: data.address as string, email: data.email as string }, revision: value.revision };
};
const isVisibleAndFocused = () => document.visibilityState === 'visible' && document.hasFocus();

export function PayerForm({ scope }: { scope: string | null | undefined }) {
  const [data, setData] = useState<Payer>(blank);
  const [status, setStatus] = useState<'loading' | 'ready' | 'error'>('loading');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [saving, setSaving] = useState(false);
  const current = useRef(0);
  const revision = useRef<string | null>(null);
  const request = useRef<AbortController | null>(null);
  const active = useRef(false);
  const suspended = useRef(false);

  const invalidate = useCallback(() => {
    current.current += 1;
    request.current?.abort();
    request.current = null;
    revision.current = null;
    setData(blank()); setErrors({}); setError(''); setNotice(''); setSaving(false); setStatus('loading');
  }, []);

  const load = useCallback((successNotice = '') => {
    if (!active.current || suspended.current) return;
    invalidate();
    if (!scope) { setStatus('error'); setError('Не удалось проверить доступ. Обновите страницу.'); return; }
    const controller = new AbortController();
    request.current = controller;
    const generation = current.current;
    void fetch('/billing/payer/data', { headers: headers(scope), credentials: 'same-origin', cache: 'no-store', signal: controller.signal })
      .then(async (response) => {
        if (!response.ok) throw new Error('load failed');
        const payload = readPayer(await response.json());
        if (!payload) throw new Error('invalid data');
        if (active.current && !suspended.current && !controller.signal.aborted && generation === current.current) { revision.current = payload.revision; setData(payload.payer); setStatus('ready'); setNotice(successNotice); }
      })
      .catch(() => {
        if (active.current && !suspended.current && !controller.signal.aborted && generation === current.current) { setData(blank()); setStatus('error'); setError('Не удалось загрузить реквизиты. Проверьте доступ и попробуйте ещё раз.'); }
      });
  }, [invalidate, scope]);

  useEffect(() => {
    // The private state must be blanked and revalidated on each mount.
    active.current = true; suspended.current = !isVisibleAndFocused(); if (!suspended.current) load();
    const hide = () => {
      if (!active.current) return;
      suspended.current = true;
      // Remove values synchronously before the browser freezes a page for back/forward.
      flushSync(invalidate);
    };
    const show = () => { if (!active.current || !suspended.current || !isVisibleAndFocused()) return; suspended.current = false; load(); };
    const visibility = () => { if (document.visibilityState === 'hidden') hide(); else if (suspended.current) show(); };
    const onPageShow = (event: PageTransitionEvent) => { if (event.persisted) show(); };
    const onStart = router.on('start', () => hide());
    const onFinish = router.on('finish', () => { if (active.current && suspended.current) show(); });
    window.addEventListener('pagehide', hide);
    window.addEventListener('pageshow', onPageShow);
    window.addEventListener('blur', hide);
    window.addEventListener('focus', show);
    document.addEventListener('visibilitychange', visibility);
    return () => {
      active.current = false; current.current += 1; request.current?.abort();
      onStart(); onFinish();
      window.removeEventListener('pagehide', hide); window.removeEventListener('pageshow', onPageShow);
      window.removeEventListener('blur', hide); window.removeEventListener('focus', show);
      document.removeEventListener('visibilitychange', visibility);
    };
  }, [invalidate, load]);

  const save = (event: FormEvent) => {
    event.preventDefault();
    if (status !== 'ready' || saving || !scope || !revision.current || suspended.current) return;
    setErrors({}); setError(''); setNotice(''); setSaving(true);
    const controller = new AbortController();
    request.current?.abort(); request.current = controller;
    const generation = current.current;
    const check = () => active.current && !suspended.current && !controller.signal.aborted && generation === current.current;
    void fetch('/billing/payer', { method: 'PUT', credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
      headers: { ...headers(scope), 'Content-Type': 'application/json', 'X-Payer-Revision': revision.current }, body: JSON.stringify(data) })
      .then(async (response) => {
        if (!check()) return;
        if (response.status === 422) {
          const payload = await response.json().catch(() => null) as { errors?: Record<string, string | string[]> } | null;
          if (!check()) return;
          const next: Record<string, string> = {};
          for (const field of fields) { const value = payload?.errors?.[field]; if (typeof value === 'string') next[field] = value; else if (Array.isArray(value) && typeof value[0] === 'string') next[field] = value[0]; }
          setErrors(next); setError(Object.keys(next).length ? '' : 'Проверьте введённые данные.'); return;
        }
        if ([401, 403, 404, 409, 419].includes(response.status)) { invalidate(); setStatus('error'); setError('Доступ или данные изменились. Загрузите реквизиты заново.'); return; }
        if (!response.ok) throw new Error('save failed');
        load('Реквизиты сохранены.');
      })
      .catch(() => { if (check()) setError('Не удалось сохранить реквизиты. Попробуйте ещё раз.'); })
      .finally(() => { if (check()) setSaving(false); });
  };

  return <><Notice>Реквизиты можно сохранить сейчас. Выставление счетов появится позже.</Notice><section className={panel}><h2 className="mb-5 text-xl font-bold">Данные плательщика</h2>
    {status === 'loading' && <div aria-busy="true"><Skeleton label="Загрузка реквизитов" /></div>}
    {status === 'error' && <><p role="alert" className="mb-4 text-sm text-(--color-danger-ink)">{error}</p><Button className="cursor-pointer" variant="secondary" onClick={() => load()}>Повторить</Button></>}
    {status === 'ready' && <><Errors errors={errors} transport={error} />{notice && <p role="status" className="mb-4 text-sm text-(--color-success-ink)">{notice}</p>}<form onSubmit={save} className="space-y-5"><div className="grid gap-4 sm:grid-cols-2">
      <Input label="Юридическое название" value={data.legal_name} onChange={(event) => setData({ ...data, legal_name: event.target.value })} error={errors.legal_name} disabled={saving} />
      <Input label="ИНН" inputMode="numeric" value={data.inn} onChange={(event) => setData({ ...data, inn: event.target.value })} error={errors.inn} disabled={saving} />
      <Input label="КПП" inputMode="numeric" value={data.kpp} onChange={(event) => setData({ ...data, kpp: event.target.value })} error={errors.kpp} disabled={saving} />
      <Input label="Юридический адрес" value={data.address} onChange={(event) => setData({ ...data, address: event.target.value })} error={errors.address} disabled={saving} />
      <div className="sm:col-span-2"><Input label="Почта для счетов" type="email" value={data.email} onChange={(event) => setData({ ...data, email: event.target.value })} error={errors.email} disabled={saving} /></div>
    </div><Button className="cursor-pointer" type="submit" loading={saving}>Сохранить реквизиты</Button></form></>}
  </section></>;
}

import { router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState, type FormEvent } from 'react';
import { flushSync } from 'react-dom';
import { Button, Input, Skeleton } from '@/components/ui';
import { Errors } from '@/features/Shell/Organizations/Shared';
import type { Payer } from '@/types/Shell/Billing';
import { Notice, panel } from './Shared';
import { discardPayerDraft, markPayerFormMounted, readPayerDraft, writePayerDraft } from './payerDraft';

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
  const live = useRef<{ data: Payer; baseline: Payer; errors: Record<string, string> } | null>(null);
  const draft = useRef<{ data: Payer; revision: string; errors: Record<string, string> } | null>(null);
  const draftLost = useRef(false);
  const scopeChanged = useRef(false);
  const savingRef = useRef(false);
  const request = useRef<AbortController | null>(null);
  const active = useRef(false);
  const suspended = useRef(false);

  const invalidate = useCallback(() => {
    current.current += 1;
    request.current?.abort();
    request.current = null;
    revision.current = null;
    live.current = null;
    savingRef.current = false;
    setData(blank()); setErrors({}); setError(''); setNotice(''); setSaving(false); setStatus('loading');
  }, []);

  const load = useCallback((successNotice = '') => {
    if (!active.current || suspended.current) return;
    const remembered = readPayerDraft();
    if (!draft.current && remembered && remembered.scope === scope) draft.current = { data: remembered.data, revision: remembered.revision, errors: remembered.errors };
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
        if (active.current && !suspended.current && !controller.signal.aborted && generation === current.current) {
          const saved = draft.current;
          const matches = saved !== null && saved.revision === payload.revision;
          const visible = matches ? saved.data : payload.payer;
          const visibleErrors = matches ? saved.errors : {};
          const pendingSave = draftLost.current;
          const discarded = (saved !== null && !matches) || scopeChanged.current;
          if (saved && !matches) discardPayerDraft();
          draft.current = null; draftLost.current = false; scopeChanged.current = false;
          revision.current = payload.revision;
          live.current = { data: visible, baseline: payload.payer, errors: visibleErrors };
          setData(visible); setErrors(visibleErrors); setStatus('ready'); setNotice(successNotice);
          if (pendingSave) setError('Результат сохранения неизвестен. Проверьте актуальные реквизиты перед повторным сохранением.');
          else if (discarded) setError('Доступ или данные изменились. Несохранённые изменения удалены; проверьте текущие реквизиты.');
        }
      })
      .catch(() => {
        if (active.current && !suspended.current && !controller.signal.aborted && generation === current.current) {
          const pendingSave = draftLost.current;
          const discarded = draft.current !== null || scopeChanged.current;
          draft.current = null; draftLost.current = false; scopeChanged.current = false; discardPayerDraft();
          setData(blank()); setStatus('error'); setError(pendingSave ? 'Не удалось подтвердить доступ или результат сохранения.' : discarded ? 'Не удалось подтвердить доступ. Несохранённые изменения удалены.' : 'Не удалось загрузить реквизиты. Проверьте доступ и попробуйте ещё раз.');
        }
      });
  }, [invalidate, scope]);

  useEffect(() => {
    // The private state must be blanked and revalidated on each mount.
    const unmarkMounted = markPayerFormMounted();
    const remembered = readPayerDraft();
    if (remembered && remembered.scope !== scope) { discardPayerDraft(); scopeChanged.current = true; }
    active.current = true; suspended.current = !isVisibleAndFocused(); if (!suspended.current) load();
    const hide = () => {
      if (!active.current) return;
      if (suspended.current) return;
      if (live.current && revision.current && (fields.some((field) => live.current!.data[field] !== live.current!.baseline[field]) || Object.keys(live.current.errors).length > 0)) {
        if (savingRef.current) { draft.current = null; draftLost.current = true; discardPayerDraft(); }
        else draft.current = { data: { ...live.current.data }, revision: revision.current, errors: { ...live.current.errors } };
      }
      suspended.current = true;
      // Hide DOM values while retaining only an in-memory draft until reauthorization.
      flushSync(invalidate);
    };
    const leave = (clearMemory: boolean) => { if (!active.current) return; if (clearMemory) discardPayerDraft(); draft.current = null; draftLost.current = false; suspended.current = true; flushSync(invalidate); };
    const show = () => { if (!active.current || !suspended.current || !isVisibleAndFocused()) return; suspended.current = false; load(); };
    const visibility = () => { if (document.visibilityState === 'hidden') hide(); else if (suspended.current) show(); };
    const onPageShow = (event: PageTransitionEvent) => { if (event.persisted) show(); };
    const hasUnsaved = () => draft.current !== null || (!!scope && readPayerDraft()?.scope === scope) || (live.current !== null && fields.some((field) => live.current!.data[field] !== live.current!.baseline[field]));
    const onBeforeUnload = (event: BeforeUnloadEvent) => { if (hasUnsaved()) { event.preventDefault(); event.returnValue = ''; } };
    const onBefore = router.on('before', (event) => { if (hasUnsaved() && !window.confirm('Есть несохранённые изменения. Покинуть страницу?')) event.preventDefault(); });
    const onStart = router.on('start', (event) => leave(event.detail.visit.url.pathname === '/logout'));
    const onFinish = router.on('finish', () => { if (active.current && suspended.current) show(); });
    const onPageHide = () => leave(true);
    window.addEventListener('pagehide', onPageHide);
    window.addEventListener('beforeunload', onBeforeUnload);
    window.addEventListener('pageshow', onPageShow);
    window.addEventListener('blur', hide);
    window.addEventListener('focus', show);
    document.addEventListener('visibilitychange', visibility);
    return () => {
      active.current = false; current.current += 1; request.current?.abort(); draft.current = null; draftLost.current = false; live.current = null;
      unmarkMounted();
      onBefore(); onStart(); onFinish();
      window.removeEventListener('pagehide', onPageHide); window.removeEventListener('pageshow', onPageShow);
      window.removeEventListener('beforeunload', onBeforeUnload);
      window.removeEventListener('blur', hide); window.removeEventListener('focus', show);
      document.removeEventListener('visibilitychange', visibility);
    };
  }, [invalidate, load, scope]);

  const save = (event: FormEvent) => {
    event.preventDefault();
    if (status !== 'ready' || saving || !scope || !revision.current || suspended.current) return;
    setErrors({}); setError(''); setNotice(''); setSaving(true); savingRef.current = true;
    if (live.current) live.current.errors = {};
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
          if (live.current) live.current.errors = next;
          const remembered = readPayerDraft();
          if (remembered && remembered.scope === scope) writePayerDraft({ ...remembered, errors: next });
          setErrors(next); setError(Object.keys(next).length ? '' : 'Проверьте введённые данные.'); return;
        }
        if ([401, 403, 404, 409, 419].includes(response.status)) { draft.current = null; draftLost.current = false; discardPayerDraft(); invalidate(); setStatus('error'); setError('Доступ или данные изменились. Несохранённые изменения удалены. Загрузите реквизиты заново.'); return; }
        if (!response.ok) throw new Error('save failed');
        discardPayerDraft(); draft.current = null; load('Реквизиты сохранены.');
      })
      .catch(() => { if (check()) setError('Не удалось сохранить реквизиты. Попробуйте ещё раз.'); })
      .finally(() => { if (check()) { savingRef.current = false; setSaving(false); } });
  };
  const edit = (field: keyof Payer, value: string) => {
    if (!live.current) return;
    const updated = { ...live.current.data, [field]: value };
    live.current.data = updated;
    if (scope && revision.current && fields.some((key) => updated[key] !== live.current!.baseline[key])) writePayerDraft({ scope, data: { ...updated }, revision: revision.current, errors: { ...live.current.errors } });
    else discardPayerDraft();
    setData(updated);
  };

  return <><Notice>Реквизиты можно сохранить сейчас. Выставление счетов появится позже.</Notice><section className={panel}><h2 className="mb-5 text-xl font-bold">Данные плательщика</h2>
    {status === 'loading' && <div aria-busy="true"><Skeleton label="Загрузка реквизитов" /></div>}
    {status === 'error' && <><p role="alert" className="mb-4 text-sm text-(--color-danger-ink)">{error}</p><Button className="cursor-pointer" variant="secondary" onClick={() => load()}>Повторить</Button></>}
    {status === 'ready' && <><Errors errors={errors} transport={error} />{notice && <p role="status" className="mb-4 text-sm text-(--color-success-ink)">{notice}</p>}<form onSubmit={save} className="space-y-5"><div className="grid gap-4 sm:grid-cols-2">
      <Input label="Юридическое название" value={data.legal_name} onChange={(event) => edit('legal_name', event.target.value)} error={errors.legal_name} disabled={saving} />
      <Input label="ИНН" inputMode="numeric" value={data.inn} onChange={(event) => edit('inn', event.target.value)} error={errors.inn} disabled={saving} />
      <Input label="КПП" inputMode="numeric" value={data.kpp} onChange={(event) => edit('kpp', event.target.value)} error={errors.kpp} disabled={saving} />
      <Input label="Юридический адрес" value={data.address} onChange={(event) => edit('address', event.target.value)} error={errors.address} disabled={saving} />
      <div className="sm:col-span-2"><Input label="Почта для счетов" type="email" value={data.email} onChange={(event) => edit('email', event.target.value)} error={errors.email} disabled={saving} /></div>
    </div><Button className="cursor-pointer" type="submit" loading={saving}>Сохранить реквизиты</Button></form></>}
  </section></>;
}

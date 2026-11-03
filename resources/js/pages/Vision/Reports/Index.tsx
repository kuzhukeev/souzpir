import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import CabinetLayout from '@/layouts/CabinetLayout';
import { Modal } from '@/components/ui/overlay';
import { QrCode } from '@/features/Shell/Profile/QrCode';
import { ReportSections } from './ReportSections';
import { profileContextIsStable, subscribeProfileContext } from '@/features/Shell/Profile/contextFence';

type Report = { sections: Parameters<typeof ReportSections>[0]['data']; id: string; measurement_id: string; visibility: number | null; visibility_kind: string; coverage: number | null; share_of_voice: number | null; position: number | null; verified_errors: number | null; providers: { name: string; mention_rate: number | null; coverage: number | null }[]; questions: number; evidence_status: string };
type Data = { revision: string; confirmed: { measurement_id: string; confirmed_at: string }[]; reports: { id: string; measurement_id: string; revision: number; created_at: string }[]; report: Report | null };
const button = 'min-h-11 cursor-pointer rounded-lg border border-(--color-border) bg-(--color-surface) px-4 py-2 text-sm font-semibold text-(--color-link) focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--color-focus) disabled:cursor-not-allowed disabled:opacity-50';
const percent = (value: number | null) => value === null ? 'Недостаточно данных' : `${value.toLocaleString('ru-RU')} %`;
const number = (value: number | null) => value === null ? 'Недостаточно данных' : value.toLocaleString('ru-RU');

export default function Index({ reportBrandRevision, status, createdUrl, ttlDays = [] }: { reportBrandRevision: string; status?: string | null; createdUrl?: string | null; ttlDays?: number[] }) {
  const { brand, can } = usePage<{ brand: { id: string } | null; can: Record<string, boolean> }>().props;
  const brandId = brand?.id ?? '';
  const [selected, setSelected] = useState(() => new URLSearchParams(window.location.search).get('report'));
  const [epoch, setEpoch] = useState(0);
  const [state, setState] = useState<{ key: string; data: Data | null; error: string }>({ key: '', data: null, error: '' });
  const [shareOpen, setShareOpen] = useState(!!createdUrl);
  const [ttl, setTtl] = useState(ttlDays[0] ?? 1);
  const [sharing, setSharing] = useState(false);
  const [creating, setCreating] = useState(false);
  const [createError, setCreateError] = useState('');
  const key = JSON.stringify([brandId, reportBrandRevision, selected, epoch]);
  const view = state.key === key ? state : { data: null, error: '' };
  const activeReport = view.data?.report;
  useEffect(() => {
    let active = true;
    const controller = new AbortController();
    const clear = () => { active = false; controller.abort(); setState({ key, data: null, error: 'Контекст бренда изменился. Обновите страницу.' }); };
    const unsubscribe = subscribeProfileContext(clear);
    const hide = () => { if (document.visibilityState === 'hidden') clear(); };
    document.addEventListener('visibilitychange', hide);
    window.addEventListener('pagehide', clear);
    const headers = { Accept: 'application/json', 'X-Brand-Id': brandId, 'X-Brand-Revision': reportBrandRevision };
    const run = async () => {
      if (!brandId || !profileContextIsStable()) throw new Error();
      const query = selected ? `?report=${encodeURIComponent(selected)}` : '';
      const response = await fetch(`/vision/report/data${query}`, { headers, credentials: 'same-origin', cache: 'no-store', signal: controller.signal });
      if (!response.ok) throw new Error();
      const data = await response.json() as Data;
      const revision = await fetch('/vision/runs/revision', { headers, credentials: 'same-origin', cache: 'no-store', signal: controller.signal });
      if (!revision.ok || (await revision.json()).revision !== reportBrandRevision || data.revision !== reportBrandRevision || !profileContextIsStable()) throw new Error();
      if (active) setState({ key, data, error: '' });
    };
    void run().catch(() => { if (active) setState({ key, data: null, error: 'Не удалось загрузить отчёт. Обновите страницу.' }); });
    return () => { active = false; controller.abort(); unsubscribe(); document.removeEventListener('visibilitychange', hide); window.removeEventListener('pagehide', clear); };
  }, [brandId, reportBrandRevision, selected, epoch, key]);
  const create = (id: string) => {
    setCreateError('');
    router.post('/vision/report', { measurement_id: id }, {
      headers: { 'X-Brand-Id': brandId, 'X-Brand-Revision': reportBrandRevision },
      onStart: () => setCreating(true), onFinish: () => setCreating(false),
      onSuccess: (page) => { setSelected(new URL(page.url, window.location.origin).searchParams.get('report')); setEpoch((value) => value + 1); },
      onError: (errors) => setCreateError(Object.values(errors)[0] ?? 'Не удалось подготовить отчёт.'),
      onNetworkError: () => { setCreateError('Связь прервалась. Проверьте список отчётов перед повтором.'); return false; },
    });
  };
  return <CabinetLayout breadcrumbs={[{ label: 'Видение', href: '/vision' }, { label: 'Внутренний отчёт' }]}>
    <Head title="Внутренний отчёт Видения" />
    <div className="mx-auto max-w-[1500px]">
      <header className="mb-6"><h1 className="text-3xl font-bold">Внутренний отчёт</h1><p className="mt-2 text-sm text-(--color-ink-muted)">Расчёты по подтверждённым замерам текущего бренда</p></header>
      {status && <p role="status" className="mb-4 rounded-lg bg-(--color-success-soft) p-3 text-sm">{status}</p>}
      {createError && <p role="alert" className="mb-4 rounded-lg bg-(--color-danger-soft) p-3 text-sm text-(--color-danger-ink)">{createError}</p>}
      {view.error && <div role="alert" className="mb-4 rounded-lg bg-(--color-danger-soft) p-4 text-sm text-(--color-danger-ink)">{view.error} <button type="button" className={button} onClick={() => setEpoch((value) => value + 1)}>Повторить</button></div>}
      {!view.data && !view.error && <p role="status" className="rounded-xl bg-(--color-surface) p-6 text-sm" aria-busy="true">Загружаем отчёт…</p>}
      {view.data && <>
        <section className="mb-6 rounded-2xl border border-(--color-border) bg-(--color-surface) p-5 shadow-(--shadow-card)">
          <h2 className="text-xl font-bold">Замеры и отчёты</h2>
          {view.data.confirmed.length === 0 ? <p className="mt-3 text-sm">Пока нет подтверждённого замера. Завершите и подтвердите замер на странице замеров.</p> : <div className="mt-4 flex flex-wrap gap-2">{view.data.confirmed.map((item, index) => <button key={item.measurement_id} type="button" className={button} disabled={creating || !can?.['vision.measure.run']} onClick={() => create(item.measurement_id)}>Подготовить отчёт · замер {index + 1}</button>)}</div>}
          {view.data.reports.length > 0 && <label className="mt-5 block text-sm font-semibold">Сохранённый отчёт<select className="mt-2 block min-h-11 w-full max-w-md cursor-pointer rounded-lg border border-(--color-border) bg-(--color-surface) px-3 text-sm" value={selected ?? view.data.report?.id ?? ''} onChange={(event) => setSelected(event.target.value)}>{view.data.reports.map((item) => <option key={item.id} value={item.id}>Замер · версия {item.revision} · {new Date(item.created_at).toLocaleDateString('ru-RU')}</option>)}</select></label>}
        </section>
        {activeReport && <div data-private-profile>
          <nav aria-label="Разделы отчёта" className="mb-6 flex flex-wrap gap-2 text-sm"><a className={button} href="#overview">Главное</a><a className={button} href="#models">По моделям</a><a className={button} href="#competitors">Компании</a><a className={button} href="#gaps">Вопросы</a><a className={button} href="#facts">Факты</a><a className={button} href="#sources">Источники</a><a className={button} href="#site">Сайт</a><a className={button} href="#actions">Действия</a><a className={button} href="#changes">Изменения</a><a className={button} href="#method">Методика</a></nav>
          <p className="mb-5 flex flex-wrap items-center gap-4 text-sm"><button type="button" className={button} onClick={() => setShareOpen(true)}>Поделиться отчётом</button><Link href="/vision/publications" className="cursor-pointer font-semibold text-(--color-link)">Публикации отчётов →</Link></p>
          <section id="overview" className="rounded-2xl border border-(--color-border) bg-(--color-surface) p-5 shadow-(--shadow-card)"><h2 className="text-xl font-bold">Главное</h2><p className="mt-2 text-sm text-(--color-ink-muted)">Вопросов в снимке: {activeReport.questions}. Покрытие: {percent(activeReport.coverage)}.</p>
            <div className="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">{[
              ['Видимость', percent(activeReport.visibility)], ['Доля голоса', percent(activeReport.share_of_voice)], ['Средняя позиция', number(activeReport.position)], ['Проверенные ошибки', number(activeReport.verified_errors)],
            ].map(([label, value]) => <div key={label} className="rounded-xl bg-(--color-surface-muted) p-4"><p className="text-sm text-(--color-ink-muted)">{label}</p><p className="mt-2 text-xl font-bold">{value}</p></div>)}</div>
            {activeReport.visibility_kind !== 'full' && <p className="mt-4 text-sm text-(--color-ink-muted)">Видимость рассчитана по доступным моделям; неизвестные ответы не засчитаны как отсутствие бренда.</p>}
          </section>
          <section id="models" className="mt-6 rounded-2xl border border-(--color-border) bg-(--color-surface) p-5 shadow-(--shadow-card)"><h2 className="text-xl font-bold">Видимость по моделям</h2>{activeReport.providers.length === 0 ? <p className="mt-3 text-sm">Данных пока нет.</p> : <ul className="mt-4 grid gap-3">{activeReport.providers.map((provider) => <li key={provider.name} className="flex flex-wrap justify-between gap-2 rounded-lg bg-(--color-surface-muted) p-3 text-sm"><span>{provider.name}</span><strong>{percent(provider.mention_rate)}</strong><span>Покрытие {percent(provider.coverage)}</span></li>)}</ul>}</section>
          <ReportSections data={activeReport.sections} />
          <section id="method" className="mt-6 rounded-2xl border border-(--color-border) bg-(--color-surface) p-5 shadow-(--shadow-card)"><h2 className="text-xl font-bold">Методика</h2><details className="mt-3 text-sm"><summary className="cursor-pointer font-semibold">Как рассчитаны показатели</summary><p className="mt-3">Метрики сохранены в неизменяемом снимке отчёта. Пропущенные и непроверенные ответы не считаются отрицательными. Доля голоса показывается только при полной проверке упоминаний.</p></details></section>
        </div>}
      </>}
      <Modal open={shareOpen && profileContextIsStable()} title="Поделиться отчётом" onClose={() => setShareOpen(false)}>
        {createdUrl ? <div className="space-y-4"><p>Сохраните ссылку: повторно показать её нельзя.</p><input readOnly aria-label="Публичная ссылка" value={createdUrl} className="min-h-11 w-full rounded border border-(--color-border) bg-(--color-surface) px-3"/><button type="button" className={button} onClick={() => void navigator.clipboard.writeText(createdUrl)}>Копировать</button><QrCode value={createdUrl} label="QR-код публичного отчёта" /></div> : <div className="space-y-4"><p>В публичной версии нет полных текстов и внутренних данных.</p><label className="block">Срок действия<select value={ttl} onChange={e=>setTtl(Number(e.target.value))} className="mt-1 block min-h-11 w-full cursor-pointer rounded border border-(--color-border) bg-(--color-surface) px-3">{ttlDays.map(days=><option key={days} value={days}>{days} дней</option>)}</select></label><label className="flex gap-2"><input type="checkbox" disabled /> Разрешить PDF · Скоро</label><button type="button" disabled={!activeReport || !can?.['vision.measure.run'] || sharing} className={button} onClick={() => { if (!activeReport) return; router.post('/vision/publications', { report_id: activeReport.id, ttl_days: ttl, return_to: 'report' }, { headers: { 'X-Brand-Id': brandId, 'X-Brand-Revision': reportBrandRevision }, onStart:()=>setSharing(true), onFinish:()=>setSharing(false), onError:()=>setCreateError('Не удалось создать ссылку.'), onNetworkError:()=>{setCreateError('Нет связи. Проверьте список публикаций.');return false;} }); }}>{sharing ? 'Создаём…' : 'Создать ссылку'}</button></div>}
      </Modal>
    </div>
  </CabinetLayout>;
}


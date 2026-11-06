import VisibilityHistory from './VisibilityHistory';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { Eye, PieChart, BarChart3, CircleAlert, TriangleAlert } from 'lucide-react';
import CabinetLayout from '@/layouts/CabinetLayout';
import { Modal } from '@/components/ui/overlay';
import { QrCode } from '@/features/Shell/Profile/QrCode';
import { ReportSections } from './ReportSections';
import { profileContextIsStable, subscribeProfileContext } from '@/features/Shell/Profile/contextFence';

type Report = { topics?: {name: string; visibility: number; delta?: number | null}[]; visibility_history?: {measurement_id: string; date: string; visibility: number}[]; sections: Parameters<typeof ReportSections>[0]['data']; id: string; measurement_id: string; visibility: number | null; visibility_before: number | null; clarifications_applied: number; visibility_kind: string; coverage: number | null; share_of_voice: number | null; position: number | null; verified_errors: number | null; providers: { name: string; mention_rate: number | null; coverage: number | null }[]; questions: number; evidence_status: string; publication_candidates: {key: string; title: string; why: string; question_id: string; priority?: string | null; steps?: string[]}[] };
type Data = { questionnaire_complete?: boolean; clarifications_pending?: number; revision: string; confirmed: { measurement_id: string; confirmed_at: string }[]; reports: { id: string; measurement_id: string; revision: number; created_at: string; measurement_at?: string }[]; report: Report | null };
const button = 'min-h-11 cursor-pointer rounded-lg border border-(--color-border) bg-(--color-surface) px-4 py-2 text-sm font-semibold text-(--color-link) focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--color-focus) disabled:cursor-not-allowed disabled:opacity-50';
const percent = (value: number | null) => value === null ? 'Недостаточно данных' : `${value.toLocaleString('ru-RU')} %`;
const number = (value: number | null) => value === null ? 'Недостаточно данных' : value.toLocaleString('ru-RU');

export default function Index({ reportBrandRevision, status, createdUrl, ttlDays = [], publications = [], logoAvailable = false }: { logoAvailable?: boolean; reportBrandRevision: string; status?: string | null; createdUrl?: string | null; ttlDays?: number[]; publications?: {id:string;report_id:string;expires_at:string}[] }) {
  const { brand, can } = usePage<{ brand: { id: string } | null; can: Record<string, boolean> }>().props;
  const brandId = brand?.id ?? '';
  const [selected, setSelected] = useState(() => new URLSearchParams(window.location.search).get('report'));
  const [epoch, setEpoch] = useState(0);
  const [state, setState] = useState<{ key: string; data: Data | null; error: string }>({ key: '', data: null, error: '' });
  const [shareOpen, setShareOpen] = useState(!!createdUrl);
  const [ttl, setTtl] = useState(ttlDays[0] ?? 1);
  const [sharing, setSharing] = useState(false);
  const [allowPdf, setAllowPdf] = useState(false);
  const [logoConsent, setLogoConsent] = useState<{brandId:string;reportId:string;checked:boolean}>({brandId:'',reportId:'',checked:false});
  const [creating, setCreating] = useState(false);
  const [createError, setCreateError] = useState('');
  const [review, setReview] = useState<{reportId: string; keys: string[]}>({reportId: '', keys: []});
  const key = JSON.stringify([brandId, reportBrandRevision, selected, epoch]);
  const view = state.key === key ? state : { data: null, error: '' };
  const activeReport = view.data?.report;
  const activeMeasurement = view.data?.reports.find((item) => item.id === activeReport?.id);
  const measurementDate = activeMeasurement?.measurement_at;
  const confirmedActions = review.reportId === activeReport?.id ? review.keys : [];
  const includeLogo = logoAvailable && logoConsent.brandId === brandId && logoConsent.reportId === activeReport?.id && logoConsent.checked;
  const toggleAction = (key: string) => {
    if (!activeReport) return;
    setReview({reportId: activeReport.id, keys: confirmedActions.includes(key) ? confirmedActions.filter((item) => item !== key) : [...confirmedActions, key]});
  };
  const activePublication = publications.find(item => item.report_id === activeReport?.id);
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
      <header className="mb-6"><h1 className="text-3xl font-bold">Отчёт о видимости в нейросетях</h1>{activeReport && <p className="mt-2 text-sm text-(--color-ink-muted)">{measurementDate ? `Замер от ${new Date(measurementDate).toLocaleDateString('ru-RU')}` : 'Дата замера недоступна'} · Вопросов: {activeReport.questions} · Моделей: {activeReport.providers.length}</p>}<Link href="/vision/clarifications" className="mt-2 inline-flex min-h-11 cursor-pointer items-center text-sm font-semibold text-(--color-link)">Уточнения</Link><p className="mt-2 text-sm text-(--color-ink-muted)">Расчёты по подтверждённым замерам текущего бренда</p></header>
      {status && <p role="status" className="mb-4 rounded-lg bg-(--color-success-soft) p-3 text-sm">{status}</p>}
      {createError && <p role="alert" className="mb-4 rounded-lg bg-(--color-danger-soft) p-3 text-sm text-(--color-danger-ink)">{createError}</p>}
      {view.error && <div role="alert" className="mb-4 rounded-lg bg-(--color-danger-soft) p-4 text-sm text-(--color-danger-ink)">{view.error} <button type="button" className={button} onClick={() => setEpoch((value) => value + 1)}>Повторить</button></div>}
      {!view.data && !view.error && <p role="status" className="rounded-xl bg-(--color-surface) p-6 text-sm" aria-busy="true">Загружаем отчёт…</p>}
      {view.data && <>
        <section className="mb-6 rounded-2xl border border-(--color-border) bg-(--color-surface) p-5 shadow-(--shadow-card)">
          <h2 className="text-xl font-bold">Замеры и отчёты</h2>
          {view.data.confirmed.length === 0 ? <p className="mt-3 text-sm">{view.data.questionnaire_complete === false ? 'Откроется после завершения анкеты.' : 'Пока нет подтверждённого замера. Завершите и подтвердите замер на странице замеров.'}</p> : <div className="mt-4 flex flex-wrap gap-2">{view.data.confirmed.map((item, index) => <button key={item.measurement_id} type="button" className={button} disabled={creating || !can?.['vision.measure.run']} onClick={() => create(item.measurement_id)}>Подготовить отчёт · замер {index + 1}</button>)}</div>}
          {view.data.reports.length > 0 && <label className="mt-5 block text-sm font-semibold">Сохранённый отчёт<select className="mt-2 block min-h-11 w-full max-w-md cursor-pointer rounded-lg border border-(--color-border) bg-(--color-surface) px-3 text-sm" value={selected ?? view.data.report?.id ?? ''} onChange={(event) => setSelected(event.target.value)}>{view.data.reports.map((item) => <option key={item.id} value={item.id}>Замер · версия {item.revision} · {new Date(item.measurement_at ?? item.created_at).toLocaleDateString('ru-RU')}</option>)}</select></label>}
        </section>
        {activeReport && <div data-private-profile>
          {(view.data.clarifications_pending ?? 0) > 0 && <div role="status" className="mb-5 flex flex-wrap items-center gap-4 rounded-xl bg-(--color-warning-soft) p-4 text-sm text-(--color-warning-ink)"><TriangleAlert aria-hidden="true" className="size-8 shrink-0"/><div className="min-w-0 flex-1"><p className="text-base font-semibold">Уточнений ждут ответа: {view.data.clarifications_pending}. Отчёт может быть неточным.</p><p className="mt-1">В отчёте учтены текущие данные. Для большей точности ответьте на уточнения.</p></div><Link href={`/vision/runs/${activeReport.measurement_id}/clarify/mentions`} className="inline-flex min-h-11 cursor-pointer items-center font-semibold underline">Вернуться к уточнениям →</Link></div>}
          {activeReport.clarifications_applied > 0 && <div role="status" className="mb-5 rounded-xl bg-(--color-success-soft) p-4 text-sm"><p>Видимость пересчитана по решениям об упоминаниях: {percent(activeReport.visibility_before)} → {percent(activeReport.visibility)}. Сохранённый снимок отчёта не изменён.</p></div>}
          <nav aria-label="Разделы отчёта" className="sticky top-0 z-10 mb-6 flex flex-wrap gap-2 bg-(--color-surface) p-2 text-sm print:hidden"><a className={button} href="#overview">Главное</a><a className={button} href="#models">По моделям</a><a className={button} href="#competitors">Компании</a><a className={button} href="#gaps">Вопросы</a><a className={button} href="#facts">Факты</a><a className={button} href="#sources">Источники</a><a className={button} href="#site">Сайт</a><a className={button} href="#actions">Действия</a><a className={button} href="#changes">Изменения</a><a className={button} href="#method">Методика</a></nav>
          <p className="mb-5 flex flex-wrap items-center gap-4 text-sm"><button type="button" className={button} onClick={() => window.print()}>Печать / PDF</button><button type="button" className={button} onClick={() => setShareOpen(true)}>Поделиться отчётом</button><Link href="/vision/publications" className="cursor-pointer font-semibold text-(--color-link)">Публикации отчётов →</Link></p>
          <section id="overview" className="rounded-2xl border border-(--color-border) bg-(--color-surface) p-5 shadow-(--shadow-card)"><h2 className="text-xl font-bold">Главное</h2><p className="mt-2 text-sm text-(--color-ink-muted)">Вопросов в снимке: {activeReport.questions}. Покрытие: {percent(activeReport.coverage)}.</p>
            <div className="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">{[
              {label:'Видимость', value:activeReport.visibility, formatted:percent(activeReport.visibility), icon:Eye, detail:'Упоминания в проверенных ответах'},
              {label:'Доля голоса', value:activeReport.share_of_voice, formatted:percent(activeReport.share_of_voice), icon:PieChart, detail:'Доля среди упоминаний компаний'},
              {label:'Средняя позиция', value:activeReport.position, formatted:number(activeReport.position), icon:BarChart3, detail:'Позиция в проверенных ответах'},
              {label:'Проверенные ошибки', value:activeReport.verified_errors, formatted:number(activeReport.verified_errors), icon:CircleAlert, detail:'Подтверждённые ошибки в ответах'},
            ].map(({label, value, formatted, icon:Icon, detail}) => <div key={label} className="flex gap-3 rounded-xl bg-(--color-surface-muted) p-4"><span aria-hidden="true" className="grid size-10 shrink-0 place-items-center rounded-full bg-(--color-brand-primary-soft) text-(--color-link)"><Icon className="size-5"/></span><div><p className="text-sm font-semibold">{label}</p><p className="mt-2 text-2xl font-bold">{value === null ? '—' : formatted}</p><p className="mt-2 text-sm text-(--color-ink-muted)">{value === null ? 'Недостаточно данных для расчёта' : detail}</p></div></div>)}</div>
            <div className="mt-5 rounded-xl bg-(--color-surface-muted) p-4 text-sm"><h3 className="font-semibold">Главные выводы</h3><ul className="mt-2 space-y-2"><li><a className="cursor-pointer text-(--color-link) underline" href="#models">Видимость: {percent(activeReport.visibility)} · по моделям</a></li><li><a className="cursor-pointer text-(--color-link) underline" href="#competitors">Доля голоса: {percent(activeReport.share_of_voice)} · компании</a></li><li><a className="cursor-pointer text-(--color-link) underline" href="#facts">Подтверждённых ошибок: {number(activeReport.verified_errors)} · факты</a></li></ul>{activeReport.sections.changes?.rows.filter(row => ['Видимость', 'Доля голоса'].includes(row.label)).map(row => <p key={row.label} className="mt-2">{row.label}: {row.delta > 0 ? '+' : ''}{row.delta} п.п.</p>)}</div>
            <div className="mt-4 rounded-xl bg-(--color-surface-muted) p-4 text-sm"><h3 className="font-semibold">Три действия</h3>{activeReport.sections.actions.length ? <ol className="mt-3 grid gap-3 md:grid-cols-3">{activeReport.sections.actions.slice(0,3).map((item,index)=><li key={index} className="rounded-lg bg-(--color-surface) p-3"><strong>{item.title}</strong>{item.why && <p className="mt-2">{item.why}</p>}</li>)}</ol> : <p className="mt-2">Подтверждённых действий пока нет.</p>}<Link href="/vision/actions" className="mt-3 inline-flex min-h-11 cursor-pointer items-center font-semibold text-(--color-link)">Перейти к действиям →</Link></div>
            {activeReport.visibility_kind !== 'full' && <p className="mt-4 text-sm text-(--color-ink-muted)">Видимость рассчитана по доступным моделям; неизвестные ответы не засчитаны как отсутствие бренда.</p>}
          </section>
          <div className="mt-1 grid gap-4 lg:grid-cols-2"><div className="min-w-0"><VisibilityHistory points={activeReport.visibility_history ?? []}/></div><section id="models" className="min-w-0 mt-5 rounded-2xl border border-(--color-border) bg-(--color-surface) p-5 shadow-(--shadow-card)"><h2 className="text-xl font-bold">Видимость по моделям</h2>{activeReport.providers.length === 0 ? <p className="mt-3 text-sm">Данных пока нет.</p> : <ul className="mt-4 grid gap-3">{activeReport.providers.map((provider) => <li key={provider.name} className="rounded-lg border border-(--color-border) p-3 text-sm"><div className="flex flex-wrap items-center justify-between gap-2"><span className="font-semibold">{provider.name}</span><strong>{percent(provider.mention_rate)}</strong></div>{provider.mention_rate !== null && <div aria-hidden="true" className="mt-2 h-2 overflow-hidden rounded-full bg-(--color-surface-muted)"><div className="h-full rounded-full bg-(--color-success-ink)" style={{ width: `${Math.min(100, Math.max(0, provider.mention_rate))}%` }}/></div>}<p className="mt-2 text-(--color-ink-muted)">Покрытие {percent(provider.coverage)}</p></li>)}</ul>}</section></div>
          <section id="topics" className="mt-6 rounded-2xl border border-(--color-border) bg-(--color-surface) p-5 shadow-(--shadow-card)"><h2 className="text-xl font-bold">Видимость по темам</h2><p className="mt-2 text-sm text-(--color-ink-muted)">Подтверждённые упоминания среди ответов с определённым результатом по каждой теме.</p>{activeReport.topics?.length ? <div className="mt-4 overflow-x-auto"><table className="w-full text-left text-sm"><thead><tr><th className="p-2">Тема</th><th className="p-2">Видимость</th><th className="p-2">Динамика</th></tr></thead><tbody>{activeReport.topics.map(topic=><tr key={topic.name} className="border-t border-(--color-border)"><th scope="row" className="p-2">{({choose:'Выбор и сравнение',price:'Цена',buy:'Покупка',information:'Информация'} as Record<string,string>)[topic.name] ?? topic.name}</th><td className="p-2"><span className="flex min-w-28 items-center gap-2"><span>{percent(topic.visibility)}</span><span aria-hidden="true" className="h-2 w-24 overflow-hidden rounded-full bg-(--color-surface-muted)"><span className="block h-full rounded-full bg-(--color-success-ink)" style={{width:`${Math.max(0,Math.min(100,topic.visibility))}%`}}/></span></span></td><td className="p-2">{topic.delta == null ? 'Нет данных' : `${topic.delta > 0 ? '+' : ''}${topic.delta} п.п.`}</td></tr>)}</tbody></table></div> : <p className="mt-4 text-sm">Нет подтверждённых данных по темам.</p>}<p className="mt-3 text-sm text-(--color-ink-muted)">Динамика сравнивает сопоставимые подтверждённые замеры.</p></section>
          <ReportSections data={activeReport.sections} reportId={activeReport.id} brandRevision={reportBrandRevision} />
          <section id="method" className="mt-6 rounded-2xl border border-(--color-border) bg-(--color-surface) p-5 shadow-(--shadow-card)"><h2 className="text-xl font-bold">Методика</h2>{[['Как рассчитаны показатели','Метрики сохранены в неизменяемом снимке отчёта. Отчёт построен по подтверждённому замеру и сохранённому набору вопросов.'],['Видимость','Пропущенные и непроверенные ответы не считаются отрицательными.'],['Доля голоса и позиция','Доля голоса показывается при полной проверке упоминаний.'],['Сравнение замеров','Сравниваются снимки с одинаковыми вопросами, моделями и числом прогонов.']].map(([title,description])=><details key={title} className="mt-3 text-sm"><summary className="cursor-pointer font-semibold">{title}</summary><p className="mt-3">{description}</p></details>)}</section>
        </div>}
      </>}
      <Modal open={shareOpen && profileContextIsStable()} title="Поделиться отчётом" onClose={() => setShareOpen(false)}>
        <div className="mb-4 text-sm"><p>Публичный доступ: {activePublication ? 'включён' : 'выключен'}</p><label className="mt-3 flex cursor-pointer items-center gap-2"><input type="checkbox" role="switch" checked={!!activePublication} disabled={!activeReport || !can?.['vision.measure.run'] || sharing} onChange={() => { if (activePublication) { router.delete(`/vision/publications/${activePublication.id}`, {headers:{'X-Brand-Id':brandId,'X-Brand-Revision':reportBrandRevision},onStart:()=>setSharing(true),onFinish:()=>setSharing(false),onError:()=>setCreateError('Не удалось отозвать ссылку.')}); } else if (activeReport) { router.post('/vision/publications', {report_id:activeReport.id,ttl_days:ttl,return_to:'report',allow_pdf:allowPdf,include_logo:includeLogo,confirmed_actions:confirmedActions}, {headers:{'X-Brand-Id':brandId,'X-Brand-Revision':reportBrandRevision},onStart:()=>setSharing(true),onFinish:()=>setSharing(false),onError:()=>setCreateError('Не удалось создать ссылку.'),onNetworkError:()=>{setCreateError('Нет связи. Проверьте список публикаций.');return false;}}); } }} /> Публичная ссылка</label>{activePublication && can?.['vision.measure.run'] && <button type="button" className={button} onClick={()=>router.delete(`/vision/publications/${activePublication.id}`,{headers:{'X-Brand-Id':brandId,'X-Brand-Revision':reportBrandRevision}})}>Отозвать ссылку</button>}</div>
        {!activePublication && activeReport && logoAvailable && <label className="mb-4 flex cursor-pointer items-start gap-2 text-sm"><input type="checkbox" checked={includeLogo} disabled={sharing} onChange={event=>setLogoConsent({brandId,reportId:activeReport.id,checked:event.target.checked})}/><span>Включить загруженный логотип в публичный отчёт. Логотип станет частью снимка; после отзыва или истечения ссылка недоступна.</span></label>}
        {!activePublication && activeReport && <section className="mb-4 rounded-lg border border-(--color-border) p-3 text-sm" aria-label="Проверка рекомендаций для публикации"><h3 className="font-semibold">Рекомендации для публикации</h3><p className="mt-1 text-(--color-ink-muted)">Выберите проверенные предложения. Без выбора действия не попадут в публичную ссылку.</p>{activeReport.publication_candidates?.length ? <div className="mt-3 space-y-2">{activeReport.publication_candidates.map((item) => <label key={item.key} className="flex min-h-11 cursor-pointer gap-3 rounded-lg bg-(--color-surface-muted) p-3"><input type="checkbox" checked={confirmedActions.includes(item.key)} disabled={sharing || (!confirmedActions.includes(item.key) && confirmedActions.length >= 3)} onChange={() => toggleAction(item.key)} /><span><strong>{item.title}</strong><span className="block text-(--color-ink-muted)">Вопрос {item.question_id} · {item.why}</span><span className="block text-(--color-ink-muted)">Приоритет: {item.priority || 'не указан'}</span>{item.steps?.map((step, index)=><span key={index} className="block text-(--color-ink-muted)">{index + 1}. {step}</span>)}</span></label>)}</div> : <p className="mt-2 text-(--color-ink-muted)">Проверенных предложений для публикации нет.</p>}</section>}
        {createdUrl ? <div className="space-y-4"><p>Сохраните ссылку: повторно показать её нельзя.</p><input readOnly aria-label="Публичная ссылка" value={createdUrl} className="min-h-11 w-full rounded border border-(--color-border) bg-(--color-surface) px-3"/><button type="button" className={button} onClick={() => void navigator.clipboard.writeText(createdUrl)}>Копировать</button><QrCode value={createdUrl} label="QR-код публичного отчёта" /></div> : <div className="space-y-4"><p>В публичной версии нет полных текстов и внутренних данных.</p><label className="block">Срок действия<select value={ttl} onChange={e=>setTtl(Number(e.target.value))} className="mt-1 block min-h-11 w-full cursor-pointer rounded border border-(--color-border) bg-(--color-surface) px-3">{ttlDays.map(days=><option key={days} value={days}>{days} дней</option>)}</select></label><label className="flex gap-2"><input type="checkbox" checked={allowPdf} onChange={event=>setAllowPdf(event.target.checked)} /> Разрешить сохранение в PDF</label><button type="button" disabled={!activeReport || !can?.['vision.measure.run'] || sharing} className={button} onClick={() => { if (!activeReport) return; router.post('/vision/publications', { report_id: activeReport.id, ttl_days: ttl, return_to: 'report', allow_pdf: allowPdf, confirmed_actions: confirmedActions }, { headers: { 'X-Brand-Id': brandId, 'X-Brand-Revision': reportBrandRevision }, onStart:()=>setSharing(true), onFinish:()=>setSharing(false), onError:()=>setCreateError('Не удалось создать ссылку.'), onNetworkError:()=>{setCreateError('Нет связи. Проверьте список публикаций.');return false;} }); }}>{sharing ? 'Создаём…' : 'Создать ссылку'}</button></div>}
        <p className="mt-3 text-sm text-(--color-ink-muted)">Запрос отзыва и просмотры будут доступны позже.</p><button type="button" className={`${button} mt-4`} onClick={()=>setShareOpen(false)}>Готово</button>
      </Modal>
    </div>
  </CabinetLayout>;
}


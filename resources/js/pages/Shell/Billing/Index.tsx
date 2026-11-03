import { Head, Link, router, usePage } from '@inertiajs/react';
import { BrandSwitch } from '@/components/navigation/BrandSwitch';
import type { SharedPropsV1 } from '@/types/generated/data';
import { BarChart3, Crown, FileText, Info } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button, EmptyState, Input } from '@/components/ui';
import { BillingPage, Notice, panel } from '@/features/Shell/Billing/Shared';
import { PlanRequests } from '@/features/Shell/Billing/PlanRequests';
import { PayerForm } from '@/features/Shell/Billing/PayerForm';
import { discardPayerDraft } from '@/features/Shell/Billing/payerDraft';
import { focus } from '@/features/Shell/Organizations/Shared';
import type { BillingLimit, BillingProps } from '@/types/Shell/Billing';

const titles = {
  overview: ['Текущий план и лимиты', 'Тариф и использование модулей'],
  usage: ['Использование', 'Расход по модулям'],
  plans: ['Смена тарифа', 'Выберите подходящий план и оставьте заявку'],
  invoices: ['Счета и оплата', 'Счета и документы по оплате'],
  payer: ['Реквизиты и плательщик', 'Данные юридического лица для выставления счетов'],
} as const;

function LimitRow({ limit }: { limit: BillingLimit }) {
  const ceiling = limit.value === null ? null : Number(limit.value);
  const percent = ceiling !== null && Number.isFinite(ceiling) && ceiling > 0 && limit.used !== null
    ? Math.min(100, Math.max(0, limit.used / ceiling * 100)) : null;
  return <li className="border-t border-(--color-border) py-4 first:border-0">
    <div className="flex flex-wrap justify-between gap-2 text-sm"><span>{limit.label}</span><span className="font-semibold">{limit.used === null ? '—' : limit.used.toLocaleString('ru-RU')} / {limit.value ?? 'не задано'}</span></div>
    {percent === 100 && <p className="mt-2 text-sm text-(--color-danger-ink)">Лимит исчерпан. Доступность действий зависит от тарифа.</p>}
    {percent !== null && <div role="progressbar" aria-label={limit.label} aria-valuemin={0} aria-valuemax={100} aria-valuenow={Math.round(percent)} className="mt-3 h-2.5 overflow-hidden rounded-full bg-(--color-surface-muted)"><div className={`h-full rounded-full ${percent >= 100 ? 'bg-(--color-danger)' : percent >= 80 ? 'bg-(--color-warning)' : 'bg-(--color-action)'}`} style={{ width: `${percent}%` }} /></div>}
  </li>;
}

type Daily = { brand: string | null; limited: boolean; rows: { date: string; key: string; label: string; brand: string; value: number | string; unit: string }[] };
function DailyUsage({ daily, from, to }: { daily: Daily | null; from: string; to: string }) {
  const [page, setPage] = useState(0);
  const { brand, brands } = usePage<SharedPropsV1>().props;
  if (!daily) return null;
  const rows = daily.rows;
  const pages = Math.ceil(rows.length / 10);
  const maximum = (key: string) => Math.max(1, ...rows.filter(row => row.key === key).map(row => Number(row.value)));
  return <><div className="mt-4 flex flex-wrap items-end justify-between gap-3"><div><span className="block text-sm">Бренд</span><BrandSwitch brand={brand} brands={brands ?? []} ready />{(brands?.length ?? 0) < 2 && <p className="mt-1 text-sm">{daily.brand ?? 'Не выбран'}</p>}</div><a className="cursor-pointer text-sm font-semibold text-(--color-link)" href={`/billing/usage/export?${new URLSearchParams({from,to})}`}>Экспорт CSV</a></div>
    {daily.limited ? <p role="alert" className="mt-5 text-sm">Для дневной разбивки выберите период не более 93 дней.</p> : rows.length === 0 ? <EmptyState title="Нет данных за выбранный период" description="Доступные показатели появятся после использования." /> : <>
    <section className="mt-5"><h3 className="font-bold">Использование по дням</h3><p className="mt-1 text-sm text-(--color-ink-muted)">Высота каждого показателя нормирована отдельно: единицы измерения различаются.</p><div className="mt-4 flex flex-wrap items-end gap-4" role="img" aria-label="Столбцы использования по дням">{rows.map((row,i)=><div key={`${row.date}-${row.key}-${i}`} className="flex w-24 flex-col items-center text-center text-sm"><div className="flex h-28 items-end"><div className="w-8 rounded-t bg-(--color-link)" style={{height:`${Math.max(2,Number(row.value)/maximum(row.key)*100)}%`}} title={`${row.label}: ${row.value} ${row.unit}`} /></div><span className="mt-2">{row.date}</span><span>{row.label}</span><span>{row.value} {row.unit}</span></div>)}</div></section>
    <div className="mt-5 overflow-x-auto"><table className="w-full min-w-[600px] text-left text-sm"><thead><tr><th className="p-2">День</th><th className="p-2">Модуль</th><th className="p-2">Показатель</th><th className="p-2">Бренд</th><th className="p-2">Значение</th><th className="p-2">Единица</th><th className="p-2">Стоимость в единицах тарифа</th></tr></thead><tbody>{rows.slice(page*10,page*10+10).map((row,i)=><tr key={`${row.date}-${row.key}-${i}`} className="border-t border-(--color-border)"><td className="p-2">{row.date}</td><td className="p-2">{row.key.startsWith('seller.') ? 'ИИ-продавец' : row.key.startsWith('vision.') ? 'Видение' : 'Общее'}</td><td className="p-2">{row.label}</td><td className="p-2">{row.brand}</td><td className="p-2">{row.value}</td><td className="p-2">{row.unit}</td><td className="p-2">—</td></tr>)}</tbody></table></div>
    {pages > 1 && <div className="mt-3 flex items-center gap-3 text-sm"><button type="button" disabled={page===0} onClick={()=>setPage(page-1)} className="cursor-pointer disabled:opacity-50">Назад</button><span>{page*10+1}–{Math.min((page+1)*10,rows.length)} из {rows.length}</span><button type="button" disabled={page+1>=pages} onClick={()=>setPage(page+1)} className="cursor-pointer disabled:opacity-50">Далее</button></div>}</>}
  </>;
}

export default function BillingIndex({ tab, currentPlan, limits, usage, plans, canManage, requestEnabled, submissionKey, submissionScope, period, daily }: BillingProps & { daily?: Daily | null; currentPlan: (NonNullable<BillingProps['currentPlan']> & { startsAt?: string | null; endsAt?: string | null }) | null }) {
  useEffect(() => { if (!canManage) discardPayerDraft(); }, [canManage]);
  const [from, setFrom] = useState(period.from);
  const [to, setTo] = useState(period.to);
  const [loadingUsage, setLoadingUsage] = useState(false);
  const [failure, setFailure] = useState('');
  const [rangeError, setRangeError] = useState('');
  const [title, subtitle] = titles[tab];
  const transport = () => { setFailure('Не удалось связаться с сервером. Попробуйте ещё раз.'); return false; };
  return <><Head title={title} /><BillingPage active={tab} canManage={canManage} title={title} subtitle={subtitle}>
    {failure && <div role="alert" className="mb-5 rounded-xl bg-(--color-danger-soft) p-4 text-sm text-(--color-danger-ink)">{failure}</div>}
    {tab === 'overview' && <div className="space-y-5">
      {canManage && <section className={panel}><h2 className="text-xl font-bold">Вызовы ИИ с неподтверждённым результатом</h2><p className="mt-2 text-sm text-(--color-ink-muted)">Проверьте вызовы, по которым итоговая стоимость ещё не подтверждена.</p><Link href="/billing/ai-recovery" className={`mt-3 inline-flex min-h-11 cursor-pointer items-center text-sm font-semibold text-(--color-link) ${focus}`}>Посмотреть вызовы ИИ</Link></section>}
      <section className={`${panel} flex flex-wrap items-center gap-4`}><span className="grid size-14 shrink-0 place-items-center rounded-full bg-(--color-info-soft) text-(--color-link)"><Crown size={27} aria-hidden="true" /></span><div className="min-w-0 flex-1"><h2 className="text-xl font-bold">{currentPlan?.name ?? 'План не задан'}</h2><p className="mt-1 text-sm text-(--color-ink-muted)">Текущий план организации</p>{currentPlan?.startsAt && <p className="mt-1 text-sm text-(--color-ink-muted)">Период: с {new Date(currentPlan.startsAt).toLocaleDateString('ru-RU')} · {currentPlan.endsAt ? `до ${new Date(currentPlan.endsAt).toLocaleDateString('ru-RU')}` : 'окончание не задано'}</p>}<p className="mt-1 text-sm text-(--color-ink-muted)">Продление: не задано</p></div>{canManage && <Link href="/billing/plans" className={`cursor-pointer rounded-lg border border-(--color-border) px-4 py-3 text-sm font-semibold text-(--color-link) ${focus}`}>Сменить тариф <span className="ml-2 rounded-full bg-(--color-surface-muted) px-2 py-1">Скоро</span></Link>}</section>
      <section className={panel}><div className="flex items-center gap-3"><BarChart3 aria-hidden="true" className="text-(--color-link)" /><div><h2 className="text-xl font-bold">Лимиты и использование</h2><p className="text-sm text-(--color-ink-muted)">Значения тарифа организации</p></div></div>{limits.length ? <ul className="mt-4 grid gap-x-8 md:grid-cols-2">{limits.map((limit) => <LimitRow key={limit.key} limit={limit} />)}</ul> : <p className="mt-5 text-sm text-(--color-ink-muted)">Лимиты не заданы.</p>}</section>
      <Notice><Info className="mr-2 inline-block align-middle" size={18} aria-hidden="true" /> Если значение не настроено, лимит указан как «не задано».</Notice>
    </div>}
    {tab === 'usage' && <section className={panel}><h2 className="text-xl font-bold">Использование</h2><p className="mt-1 text-sm text-(--color-ink-muted)">Данные по текущему бренду и доступным источникам</p><form className="mt-5 flex flex-wrap items-end gap-3" onSubmit={(event) => { event.preventDefault(); setFailure(''); if (from > to) { setRangeError('Дата начала не может быть позже даты окончания.'); return; } setRangeError(''); router.get('/billing/usage', { from, to }, { preserveState: true, preserveScroll: true, onStart: () => setLoadingUsage(true), onFinish: () => setLoadingUsage(false), onError: (errors) => setFailure(Object.values(errors).join(' ') || 'Не удалось загрузить данные.'), onNetworkError: transport, onHttpException: transport }); }}><div aria-live="polite"><Input type="date" label="С" value={from} error={rangeError} onChange={(event) => { setFrom(event.target.value); setRangeError(''); }} required /></div><Input type="date" label="По" value={to} onChange={(event) => { setTo(event.target.value); setRangeError(''); }} required /><Button className="cursor-pointer" type="submit" loading={loadingUsage}>Показать</Button></form><DailyUsage key={`${period.from}:${period.to}`} daily={daily ?? null} from={period.from} to={period.to} /><div aria-busy={loadingUsage}>{usage.length ? <dl className="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">{usage.map((item) => <div key={item.key} className="rounded-xl border border-(--color-border) p-4"><dt className="text-sm text-(--color-ink-muted)">{item.label}</dt><dd className="mt-2 text-2xl font-bold">{item.status === 'ready' && item.value !== null ? item.value.toLocaleString('ru-RU') : 'Недоступно'}</dd></div>)}</dl> : !(daily?.rows.length) && <EmptyState title="Нет данных об использовании" description="Данные появятся, когда будут доступны источники." />}</div></section>}
    {tab === 'plans' && <PlanRequests key={`${submissionScope ?? ''}:${requestEnabled}`} plans={plans} canManage={canManage} enabled={requestEnabled} scope={submissionScope} initialKey={submissionKey} />}
    {tab === 'invoices' && <><Notice>Раздел появится позже. Здесь будут счета и документы по оплате.</Notice><section className={panel}><h2 className="text-xl font-bold">Счета <span className="ml-2 rounded-full bg-(--color-surface-muted) px-3 py-1 text-sm">Скоро</span></h2><div className="mt-5 overflow-x-auto"><table className="w-full min-w-[620px] text-left text-sm"><thead><tr className="border-b border-(--color-border)"><th className="p-3">Номер</th><th className="p-3">Дата</th><th className="p-3">Сумма</th><th className="p-3">Статус</th><th className="p-3">PDF</th></tr></thead><tbody><tr><td colSpan={5} className="p-8 text-center"><FileText aria-hidden="true" size={42} className="mx-auto text-(--color-ink-muted)" /><p className="mt-3 font-semibold">Счетов пока нет</p><p className="mt-1 text-(--color-ink-muted)">Функция скоро будет доступна</p></td></tr></tbody></table></div></section></>}
    {tab === 'payer' && (canManage ? <PayerForm key={submissionScope ?? ''} scope={submissionScope} /> : <p role="alert" className="text-sm text-(--color-danger-ink)">Недостаточно прав для просмотра реквизитов. Несохранённые изменения удалены.</p>)}
  </BillingPage></>;
}

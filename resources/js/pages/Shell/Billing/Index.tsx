import { Head, Link, router } from '@inertiajs/react';
import { BarChart3, Crown, FileText, Info } from 'lucide-react';
import { useState } from 'react';
import { Button, EmptyState, Input } from '@/components/ui';
import { BillingPage, Notice, panel } from '@/features/Shell/Billing/Shared';
import { PlanRequests } from '@/features/Shell/Billing/PlanRequests';
import { PayerForm } from '@/features/Shell/Billing/PayerForm';
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
    {percent !== null && <div role="progressbar" aria-label={limit.label} aria-valuemin={0} aria-valuemax={100} aria-valuenow={Math.round(percent)} className="mt-3 h-2.5 overflow-hidden rounded-full bg-(--color-surface-muted)"><div className={`h-full rounded-full ${percent >= 100 ? 'bg-(--color-danger)' : 'bg-(--color-action)'}`} style={{ width: `${percent}%` }} /></div>}
  </li>;
}

export default function BillingIndex({ tab, currentPlan, limits, usage, plans, canManage, requestEnabled, submissionKey, submissionScope, period }: BillingProps) {
  const [from, setFrom] = useState(period.from);
  const [to, setTo] = useState(period.to);
  const [loadingUsage, setLoadingUsage] = useState(false);
  const [failure, setFailure] = useState('');
  const [title, subtitle] = titles[tab];
  const transport = () => { setFailure('Не удалось связаться с сервером. Попробуйте ещё раз.'); return false; };
  return <><Head title={title} /><BillingPage active={tab} canManage={canManage} title={title} subtitle={subtitle}>
    {failure && <div role="alert" className="mb-5 rounded-xl bg-(--color-danger-soft) p-4 text-sm text-(--color-danger-ink)">{failure}</div>}
    {tab === 'overview' && <div className="space-y-5">
      <section className={`${panel} flex flex-wrap items-center gap-4`}><span className="grid size-14 shrink-0 place-items-center rounded-full bg-(--color-info-soft) text-(--color-link)"><Crown size={27} aria-hidden="true" /></span><div className="min-w-0 flex-1"><h2 className="text-xl font-bold">{currentPlan?.name ?? 'План не задан'}</h2><p className="mt-1 text-sm text-(--color-ink-muted)">Текущий план организации</p></div>{canManage && <Link href="/billing/plans" className={`cursor-pointer rounded-lg border border-(--color-border) px-4 py-3 text-sm font-semibold text-(--color-link) ${focus}`}>Сменить тариф</Link>}</section>
      <section className={panel}><div className="flex items-center gap-3"><BarChart3 aria-hidden="true" className="text-(--color-link)" /><div><h2 className="text-xl font-bold">Лимиты и использование</h2><p className="text-sm text-(--color-ink-muted)">Значения тарифа организации</p></div></div>{limits.length ? <ul className="mt-4 grid gap-x-8 md:grid-cols-2">{limits.map((limit) => <LimitRow key={limit.key} limit={limit} />)}</ul> : <p className="mt-5 text-sm text-(--color-ink-muted)">Лимиты не заданы.</p>}</section>
      <Notice><Info className="mr-2 inline-block align-middle" size={18} aria-hidden="true" /> Если значение не настроено, лимит указан как «не задано».</Notice>
    </div>}
    {tab === 'usage' && <section className={panel}><h2 className="text-xl font-bold">Использование</h2><p className="mt-1 text-sm text-(--color-ink-muted)">Данные по текущему бренду и доступным источникам</p><form className="mt-5 flex flex-wrap items-end gap-3" onSubmit={(event) => { event.preventDefault(); setFailure(''); router.get('/billing/usage', { from, to }, { preserveState: true, preserveScroll: true, onStart: () => setLoadingUsage(true), onFinish: () => setLoadingUsage(false), onError: (errors) => setFailure(Object.values(errors).join(' ') || 'Не удалось загрузить данные.'), onNetworkError: transport, onHttpException: transport }); }}><Input type="date" label="С" value={from} onChange={(event) => setFrom(event.target.value)} required /><Input type="date" label="По" value={to} onChange={(event) => setTo(event.target.value)} required /><Button className="cursor-pointer" type="submit" loading={loadingUsage}>Показать</Button></form><div aria-busy={loadingUsage}>{usage.length ? <dl className="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">{usage.map((item) => <div key={item.key} className="rounded-xl border border-(--color-border) p-4"><dt className="text-sm text-(--color-ink-muted)">{item.label}</dt><dd className="mt-2 text-2xl font-bold">{item.status === 'ready' && item.value !== null ? item.value.toLocaleString('ru-RU') : 'Недоступно'}</dd></div>)}</dl> : <EmptyState title="Нет данных об использовании" description="Данные появятся, когда будут доступны источники." />}</div></section>}
    {tab === 'plans' && <PlanRequests key={`${submissionScope ?? ''}:${requestEnabled}`} plans={plans} canManage={canManage} enabled={requestEnabled} scope={submissionScope} initialKey={submissionKey} />}
    {tab === 'invoices' && <><Notice>Раздел появится позже. Здесь будут счета и документы по оплате.</Notice><section className={panel}><h2 className="text-xl font-bold">Счета <span className="ml-2 rounded-full bg-(--color-surface-muted) px-3 py-1 text-sm">Скоро</span></h2><div className="py-12 text-center"><FileText aria-hidden="true" size={50} className="mx-auto text-(--color-ink-muted)" /><p className="mt-4 text-lg font-bold">Счета появятся здесь</p><p className="mt-2 text-sm text-(--color-ink-muted)">Функция скоро будет доступна</p></div></section></>}
    {tab === 'payer' && (canManage ? <PayerForm key={submissionScope ?? ''} scope={submissionScope} /> : <p role="alert" className="text-sm text-(--color-danger-ink)">Недостаточно прав для просмотра реквизитов.</p>)}
  </BillingPage></>;
}

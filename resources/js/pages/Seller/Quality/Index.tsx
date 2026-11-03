import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import CabinetLayout from '@/layouts/CabinetLayout';

type Day = { day: string; count: number; average: number; issues: number; withIssues: number; high: number; medium: number; topIssues: string[] };
const card = 'rounded-xl border border-(--color-border) bg-(--color-surface) p-5';
const labels: Record<string, string> = { invented_fact:'Выдуманный факт', unknown_fact:'Неизвестный факт', repeated_question:'Повторный вопрос', too_long:'Длинный ответ', forbidden_promise:'Недопустимое обещание', wrong_slot_value:'Неверное значение поля' };
const label = (key: string) => labels[key] ?? key.replaceAll('_', ' ');
const date = (value: string) => value.split('-').reverse().join('.');

export default function Index({ reportDays, days: assessmentDays }: { reportDays: Day[]; days: Day[] }) {
  const [period, setPeriod] = useState('7');
  const from = new Date(); from.setHours(0, 0, 0, 0); from.setDate(from.getDate() - Number(period) + 1);
  const start = `${from.getFullYear()}-${String(from.getMonth()+1).padStart(2,'0')}-${String(from.getDate()).padStart(2,'0')}`;
  const days = reportDays.filter(day => day.day >= start);
  const count = days.reduce((n, d) => n + d.count, 0);
  const average = count ? days.reduce((n, d) => n + d.average * d.count, 0) / count : null;
  const problemCount = days.reduce((n, d) => n + d.withIssues, 0);
  const high = days.reduce((n, d) => n + d.high, 0);
  const ordered = [...days].reverse();
  const maxIssues = Math.max(1, ...ordered.map(d => d.issues));
  const points = (fn: (d: Day) => number) => ordered.map((d, i) => `${ordered.length === 1 ? 50 : 5 + i * 90 / (ordered.length - 1)},${95 - fn(d) * 85}`).join(' ');
  return <CabinetLayout breadcrumbs={[{label:'Продавец',href:'/seller'},{label:'Качество'}]}><Head title="Качество диалогов"/><main className="mx-auto max-w-[1280px]" data-private-profile>
    <div className="flex flex-wrap items-end justify-between gap-4"><div><h1 className="text-3xl font-bold">Качество</h1><p className="mt-2 text-sm text-(--color-ink-muted)">Доклады о качестве диалогов текущего бренда</p></div><div className="flex flex-wrap gap-3"><label className="text-sm">Период <select value={period} onChange={e => setPeriod(e.target.value)} className="ml-2 min-h-11 cursor-pointer rounded-lg border border-(--color-border) bg-(--color-surface) px-3"><option value="7">Последние 7 дней</option><option value="30">Последние 30 дней</option><option value="90">Последние 90 дней</option></select></label><button disabled title="Сбор докладов пока не подключён" className="min-h-11 rounded-lg bg-(--color-surface-muted) px-4 text-sm text-(--color-ink-muted)">Собрать доклад сейчас · Скоро</button></div></div>
    <div className="mt-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">{[['Средняя оценка', average === null ? '—' : `${average.toFixed(1)} / 5`],['Доклады с проблемами', count ? `${Math.round(problemCount * 100 / count)} %` : '—'],['Проблем высокой важности', String(high)],['Стоимость за период', '—']].map(([title,value])=><section key={title} className={card}><h2 className="text-sm text-(--color-ink-muted)">{title}</h2><p className="mt-3 text-3xl font-bold">{value}</p></section>)}</div>
    <section className={`${card} mt-4`}><h2 className="text-xl font-bold">Оценка и проблемы по дням</h2><p className="mt-2 text-sm text-(--color-ink-muted)">Средняя оценка (0–5) · Число проблем</p>{ordered.length ? <><svg role="img" aria-label="Динамика оценки и числа проблем по дням" viewBox="0 0 100 100" preserveAspectRatio="none" className="mt-4 h-48 w-full overflow-visible"><line x1="0" y1="95" x2="100" y2="95" stroke="currentColor" opacity=".2"/><polyline points={points(d => d.average/5)} fill="none" stroke="#008b57" strokeWidth="1" vectorEffect="non-scaling-stroke"/><polyline points={points(d => d.issues/maxIssues)} fill="none" stroke="#eb7800" strokeWidth="1" vectorEffect="non-scaling-stroke"/>{ordered.map((d,i)=><g key={d.day}><circle cx={ordered.length === 1 ? 50 : 5 + i * 90 / (ordered.length-1)} cy={95 - d.average/5*85} r="1.2" fill="#008b57"/><circle cx={ordered.length === 1 ? 50 : 5 + i * 90 / (ordered.length-1)} cy={95 - d.issues/maxIssues*85} r="1.2" fill="#eb7800"/></g>)}</svg><div className="mt-2 flex justify-between text-sm text-(--color-ink-muted)"><span>{date(ordered[0].day)}</span><span>{date(ordered[ordered.length-1].day)}</span></div></> : <p className="mt-4 text-sm">За выбранный период докладов нет.</p>}</section>
    <section className={`${card} mt-4`}><h2 className="text-xl font-bold">Доклады</h2><p className="mt-1 text-sm text-(--color-ink-muted)">Последние 500 записей, без пагинации</p>{days.length===0 ? <p className="mt-4 text-sm">Докладов пока нет.</p> : <ul className="mt-4 space-y-3">{days.map(d=><li key={d.day} className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-(--color-border) p-4 text-sm"><div><strong>{date(d.day)}</strong><p className="mt-1">Докладов: {d.count} · Оценка: {d.average.toFixed(1)} / 5 · Высокие / средние: {d.high} / {d.medium}</p><div className="mt-2 flex flex-wrap gap-2">{d.topIssues.map(type=><span key={type} className="rounded-full bg-(--color-surface-muted) px-3 py-1">{label(type)}</span>)}</div></div><Link href={`/seller/quality/${d.day}`} className="cursor-pointer font-semibold text-(--color-link)">Открыть →</Link></li>)}</ul>}</section>
    <section className={`${card} mt-4`}><h2 className="text-xl font-bold">Оценки диалогов и симуляций</h2>{assessmentDays.length===0 ? <p className="mt-3 text-sm">Оценок пока нет.</p> : <ul className="mt-3 space-y-2">{assessmentDays.map(d=><li key={d.day}><Link href={`/seller/quality/${d.day}`} className="inline-flex min-h-11 cursor-pointer items-center text-sm font-semibold text-(--color-link)">{date(d.day)} · Оценок: {d.count} →</Link></li>)}</ul>}</section>
  </main></CabinetLayout>;
}

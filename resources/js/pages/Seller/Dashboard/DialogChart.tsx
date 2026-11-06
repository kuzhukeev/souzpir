import { useState } from 'react';

type Grouping = 'day' | 'week' | 'month';
type Point = { date: string; dialogs: number; contacts: number | null };

function grouped(daily: Record<string, number>, contacts: Record<string, number> | undefined, grouping: Grouping): Point[] {
  const groups = new Map<string, Point>();
  for (const [date, count] of Object.entries(daily).sort(([a], [b]) => a.localeCompare(b))) {
    const day = new Date(`${date}T00:00:00Z`);
    if (Number.isNaN(day.valueOf())) continue;
    if (grouping === 'week') day.setUTCDate(day.getUTCDate() - ((day.getUTCDay() + 6) % 7));
    if (grouping === 'month') day.setUTCDate(1);
    const key = day.toISOString().slice(0, 10);
    const point = groups.get(key) ?? { date: key, dialogs: 0, contacts: 0 };
    point.dialogs += count;
    point.contacts = point.contacts === null || contacts?.[date] === undefined ? null : point.contacts + contacts[date];
    groups.set(key, point);
  }
  return [...groups.values()];
}

export default function DialogChart({ daily, contacts, total }: { daily: Record<string, number>; contacts?: Record<string, number>; total: number }) {
  const [grouping, setGrouping] = useState<Grouping>('day');
  const points = grouped(daily, contacts, grouping);
  const hasContacts = points.length > 0 && points.every(point => point.contacts !== null);
  const max = Math.max(1, ...points.map(point => Math.max(point.dialogs, point.contacts ?? 0)));
  const xy = (value: number, i: number) => `${points.length === 1 ? 50 : 5 + 90 * i / (points.length - 1)},${90 - value / max * 75}`;
  const polyline = (key: 'dialogs' | 'contacts') => points.map((point, i) => xy(point[key] ?? 0, i)).join(' ');
  return <><div className="flex flex-wrap items-center justify-between gap-3"><h2 className="text-xl font-bold">Диалоги и контакты</h2><label className="text-sm">Группировка<select aria-label="Группировка графика" className="ml-2 min-h-11 cursor-pointer rounded-lg border border-(--color-border) bg-(--color-surface) px-3" value={grouping} onChange={event => setGrouping(event.target.value as Grouping)}><option value="day">По дням</option><option value="week">По неделям</option><option value="month">По месяцам</option></select></label></div>
    {total === 0 || points.length === 0 ? <p className="mt-5 text-sm">График появится после первого диалога.</p> : <><p className="mt-3 text-sm"><span className="text-(--color-link)">●</span> Диалоги · <span className="text-(--color-success-ink)">●</span> Получили контакт{!hasContacts && ' — нет данных'}</p><svg viewBox="0 0 100 100" preserveAspectRatio="none" role="img" aria-label={`График диалогов и контактов: ${{day:'по дням',week:'по неделям',month:'по месяцам'}[grouping]}`} className="mt-3 h-48 w-full overflow-visible"><line x1="5" y1="90" x2="95" y2="90" stroke="currentColor" opacity="0.25" vectorEffect="non-scaling-stroke"/><polyline fill="none" stroke="var(--color-link)" strokeWidth="2" vectorEffect="non-scaling-stroke" points={polyline('dialogs')}/>{hasContacts && <polyline fill="none" stroke="var(--color-success-ink)" strokeWidth="2" vectorEffect="non-scaling-stroke" points={polyline('contacts')}/ >}{points.map((point,i) => <circle data-series="dialogs" key={point.date} cx={xy(point.dialogs,i).split(',')[0]} cy={xy(point.dialogs,i).split(',')[1]} r="1" fill="var(--color-link)"><title>{`${point.date}: диалогов ${point.dialogs}, контактов ${point.contacts ?? 'нет данных'}`}</title></circle>)}{hasContacts && points.map((point,i) => <circle data-series="contacts" key={point.date} cx={xy(point.contacts ?? 0,i).split(',')[0]} cy={xy(point.contacts ?? 0,i).split(',')[1]} r="1" fill="var(--color-success-ink)"><title>{`${point.date}: контактов ${point.contacts}`}</title></circle>)}</svg><div className="mt-2 flex justify-between gap-2 text-xs text-(--color-ink-muted)"><span>{points[0]?.date}</span><span>{points.at(-1)?.date}</span></div><details className="mt-3 text-sm"><summary className="cursor-pointer text-(--color-link)">Показать данные графика</summary><ul className="mt-2 space-y-1">{points.map(point => <li key={point.date}>{point.date}: диалогов {point.dialogs}, контактов {point.contacts ?? 'нет данных'}</li>)}</ul></details></>}
    <p className="mt-2 text-sm text-(--color-ink-muted)">Всего {total}. Контакты показаны по дате создания диалога.</p></>;
}

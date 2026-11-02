import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import CabinetLayout from '@/layouts/CabinetLayout';

type Row = { id: string; date: string; visibility: number | null; share: number | null; providers: Record<string, { visibility: number | null; position: number | null }>; intents: Record<string, number | null>; entities: Record<string, number | null> };
type Tab = 'measurements' | 'providers' | 'intents' | 'share';
const tabs: [Tab, string][] = [['measurements', 'По замерам'], ['providers', 'По нейросетям'], ['intents', 'По намерениям'], ['share', 'Доля голоса']];
const intentNames: Record<string, string> = { choose: 'Выбор и сравнение', price: 'Цена', buy: 'Покупка', information: 'Информация' };
const value = (number: number | null | undefined) => number == null ? '—' : `${number.toLocaleString('ru-RU')} %`;
const date = (raw: string) => new Date(raw).toLocaleDateString('ru-RU');

export default function Trends({ rows, error }: { rows: Row[]; error: boolean }) {
    const [tab, setTab] = useState<Tab>('measurements');
    const [limit, setLimit] = useState(4);
    const [metric, setMetric] = useState<'visibility' | 'position'>('visibility');
    const selected = rows.slice(-limit);
    const keys = Array.from(new Set(selected.flatMap(row => Object.keys(tab === 'providers' ? row.providers : tab === 'intents' ? row.intents : row.entities))));
    return <CabinetLayout breadcrumbs={[{ label: 'Видение', href: '/vision' }, { label: 'Динамика' }]}>
        <Head title="Динамика видимости" />
        <main className="mx-auto max-w-[1200px]" data-private-profile>
            <h1 className="text-3xl font-bold">Динамика видимости</h1>
            <p className="mt-2 text-sm text-(--color-ink-muted)">Подтверждённые отчёты текущего бренда</p>
            <div className="mt-6 flex flex-wrap gap-2" aria-label="Разделы динамики">{tabs.map(([id, label]) => <button key={id} type="button" aria-pressed={tab === id} onClick={() => setTab(id)} className={`min-h-11 cursor-pointer rounded-lg px-4 text-sm ${tab === id ? 'bg-(--color-link) text-white' : 'border border-(--color-border) bg-(--color-surface)'}`}>{label}</button>)}</div>
            {error ? <div role="alert" className="mt-5 rounded-xl bg-(--color-surface) p-5 text-sm">Не удалось загрузить отчёты. <button type="button" onClick={() => window.location.reload()} className="cursor-pointer text-(--color-link)">Повторить</button></div> : rows.length === 0 ? <p className="mt-5 rounded-xl bg-(--color-surface) p-6 text-sm">Пока нет данных. Подтвердите замер и подготовьте отчёт.</p> : <>
                <div className="mt-5 flex flex-wrap items-end gap-4"><label className="text-sm">Период<select value={limit} onChange={e => setLimit(Number(e.target.value))} className="mt-1 block min-h-11 cursor-pointer rounded-lg border border-(--color-border) bg-(--color-surface) px-3"><option value={4}>Последние 4 замера</option><option value={10}>Последние 10 замеров</option><option value={20}>Последние 20 замеров</option></select></label>{tab === 'providers' && <label className="text-sm">Показатель<select value={metric} onChange={e => setMetric(e.target.value as 'visibility' | 'position')} className="mt-1 block min-h-11 cursor-pointer rounded-lg border border-(--color-border) bg-(--color-surface) px-3"><option value="visibility">Видимость</option><option value="position">Позиция</option></select></label>}</div>
                {tab === 'measurements' && selected.some(row => row.visibility != null) && <section aria-label="График видимости" className="mt-5 rounded-xl border border-(--color-border) bg-(--color-surface) p-5"><h2 className="text-xl font-semibold">Видимость по отчётам</h2><svg viewBox="0 0 600 180" role="img" aria-label="Видимость от 0 до 100 процентов" className="mt-4 h-48 w-full"><line x1="35" y1="10" x2="35" y2="155" stroke="currentColor" opacity=".3"/><line x1="35" y1="155" x2="590" y2="155" stroke="currentColor" opacity=".3"/>{selected.map((row, i) => { if (row.visibility == null) return null; const x = 35 + (selected.length === 1 ? 0 : i * 550 / (selected.length - 1)); const y = 155 - Math.max(0, Math.min(100, row.visibility)) * 1.4; const previous = selected[i - 1]; const px = 35 + (i - 1) * 550 / Math.max(1, selected.length - 1); const py = previous?.visibility == null ? null : 155 - Math.max(0, Math.min(100, previous.visibility)) * 1.4; return <g key={row.id}>{py != null && <line x1={px} y1={py} x2={x} y2={y} stroke="var(--color-link)" strokeWidth="3"/>}<circle cx={x} cy={y} r="4" fill="var(--color-link)"><title>{date(row.date)}: {value(row.visibility)}</title></circle></g>; })}</svg><p className="text-sm text-(--color-ink-muted)">Точные значения и даты приведены в таблице ниже.</p></section>}                <section className="mt-5 overflow-x-auto rounded-xl border border-(--color-border) bg-(--color-surface) p-5"><h2 className="mb-4 text-xl font-semibold">{tabs.find(([id]) => id === tab)?.[1]}</h2>
                    {tab === 'measurements' ? <table className="w-full min-w-[550px] text-left text-sm"><thead><tr><th className="p-3">Дата отчёта</th><th className="p-3">Видимость</th><th className="p-3">Доля голоса</th><th className="p-3">Отчёт</th></tr></thead><tbody>{selected.map(row => <tr key={row.id} className="border-t border-(--color-border)"><td className="p-3">{date(row.date)}</td><td className="p-3">{value(row.visibility)}</td><td className="p-3">{value(row.share)}</td><td className="p-3"><Link href={`/vision/reports?report=${row.id}`} className="cursor-pointer text-(--color-link)">Открыть</Link></td></tr>)}</tbody></table> : keys.length === 0 ? <p className="text-sm">Пока недостаточно данных.</p> : <table className="w-full min-w-[550px] text-left text-sm"><thead><tr><th className="p-3">{tab === 'providers' ? 'Нейросеть' : tab === 'intents' ? 'Намерение' : 'Компания'}</th>{selected.map(row => <th key={row.id} className="p-3">{date(row.date)}</th>)}</tr></thead><tbody>{keys.map(key => <tr key={key} className="border-t border-(--color-border)"><th className="p-3">{intentNames[key] ?? key}</th>{selected.map(row => { const n = tab === 'providers' ? row.providers[key]?.[metric] : tab === 'intents' ? row.intents[key] : row.entities[key]; return <td key={row.id} className="p-3">{tab === 'providers' && metric === 'position' ? n ?? '—' : value(n)}</td>; })}</tr>)}</tbody></table>}
                </section>
            </>}
        </main>
    </CabinetLayout>;
}


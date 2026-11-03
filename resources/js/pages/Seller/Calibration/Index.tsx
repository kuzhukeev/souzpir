import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import CabinetLayout from '@/layouts/CabinetLayout';

type Item = { id: string; run_id: string; name: string; judge: number; rating: number | null; comment: string | null; created_at: string };
export default function Index({ items, status }: { items: Item[]; status?: string }) {
  const [failure, setFailure] = useState('');
  const form = useForm({ items: items.map(item => ({ evaluation_id: item.id, rating: item.rating ?? 3, comment: item.comment ?? '' })) });
  function change(index: number, key: 'rating' | 'comment', value: string | number) {
    form.setData('items', form.data.items.map((row, i) => i === index ? { ...row, [key]: value } : row));
  }
  function save(e: React.FormEvent) { e.preventDefault(); setFailure(''); form.post('/seller/quality/calibration', { preserveScroll: true, onNetworkError: () => { setFailure('Не удалось сохранить оценки. Попробуйте ещё раз.'); return false; }, onHttpException: () => { setFailure('Не удалось сохранить оценки. Попробуйте ещё раз.'); return false; } }); }
  return <CabinetLayout breadcrumbs={[{ label: 'Продавец', href: '/seller' }, { label: 'Качество', href: '/seller/quality' }, { label: 'Калибровка' }]}><Head title="Калибровка оценщика" /><main className="mx-auto max-w-[1100px]" data-private-profile>
    <h1 className="text-3xl font-bold">Калибровка оценщика</h1><p className="mt-2 text-sm text-(--color-ink-muted)">Сравните оценку судьи с вашей оценкой и сохраните замечания.</p>
    <p className="mt-4 rounded-xl bg-(--color-info-soft) p-4 text-sm">Оцените примеры, чтобы увидеть расхождения с оценкой судьи. Показаны последние 100 прогонов.</p>
    {status && <p role="status" className="mt-3 text-sm">{status}</p>}
    {!items.length ? <p className="mt-5 rounded-xl border border-(--color-border) bg-(--color-surface) p-5 text-sm">Оценённых прогонов пока нет.</p> : <form onSubmit={save} className="mt-5 space-y-4">
      <section aria-label="Оценки прогонов" className="space-y-3">{items.map((item, i) => <article key={item.id} className="rounded-xl border border-(--color-border) bg-(--color-surface) p-4">
        <div className="flex flex-wrap justify-between gap-2"><div><strong>{item.name}</strong><p className="mt-1 text-sm text-(--color-ink-muted)">{new Date(item.created_at).toLocaleString('ru-RU')} · оценка судьи: {item.judge}</p></div><Link href="/seller/quality/scenarios" className="cursor-pointer text-sm text-(--color-link)">Открыть прогоны</Link></div>
        <div className="mt-3 grid gap-3 sm:grid-cols-[180px_1fr]"><label className="text-sm">Ваша оценка<select value={form.data.items[i].rating} onChange={e => change(i, 'rating', Number(e.target.value))} className="mt-1 block min-h-11 w-full cursor-pointer rounded-lg border border-(--color-border) bg-(--color-surface) px-3">{[1,2,3,4,5].map(n => <option key={n} value={n}>{n}</option>)}</select></label><label className="text-sm">Что не так<textarea maxLength={2000} value={form.data.items[i].comment} onChange={e => change(i, 'comment', e.target.value)} className="mt-1 block min-h-20 w-full rounded-lg border border-(--color-border) bg-(--color-surface) p-3" /></label></div>
      </article>)}</section>
      <section className="rounded-xl border border-(--color-border) bg-(--color-surface) p-5"><h2 className="text-xl font-bold">Расхождения оценок</h2><p className="mt-1 text-sm text-(--color-ink-muted)">Ваша оценка − оценка судьи</p><ul className="mt-4 space-y-3">{items.map((item, i) => { const delta = Number((form.data.items[i].rating - item.judge).toFixed(2)); return <li key={item.id} className="text-sm"><div className="flex justify-between gap-3"><span>{item.name}</span><span>{delta > 0 ? '+' : ''}{delta}</span></div><div className="mt-1 h-2 rounded bg-(--color-surface-muted)"><div className="h-2 rounded bg-(--color-link)" style={{ width: `${Math.min(Math.abs(delta) / 4 * 100, 100)}%` }} /></div></li>; })}</ul></section>
      {failure && <p role="alert" className="text-sm text-(--color-danger-ink)">{failure}</p>}
      {Object.values(form.errors).map((error, i) => <p role="alert" className="text-sm text-(--color-danger-ink)" key={i}>{error}</p>)}<button disabled={form.processing} className="min-h-11 cursor-pointer rounded-lg bg-(--color-link) px-4 text-sm font-semibold text-white disabled:opacity-50">{form.processing ? 'Сохраняем…' : 'Сохранить оценки'}</button>
    </form>}
  </main></CabinetLayout>;
}

import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import CabinetLayout from '@/layouts/CabinetLayout';

type Attention = { kind: string; title: string; count: number; href: string; severity: string };

export default function Attention({ seller }: { seller: { attention: Attention[] } | null }) {
  const [search, setSearch] = useState('');
  const [severity, setSeverity] = useState('all');
  const [module, setModule] = useState('all');
  const [pending, setPending] = useState<string | null>(null);
  const [error, setError] = useState('');
  const items = seller?.attention.filter((item) => (module === 'all' || module === 'seller') && (severity === 'all' || item.severity === severity) && item.title.toLocaleLowerCase('ru').includes(search.toLocaleLowerCase('ru'))) ?? [];
  return <CabinetLayout breadcrumbs={[{ label: 'Главная', href: '/dashboard' }, { label: 'Требует вашего действия' }]}>
    <Head title="Требует вашего действия" />
    <main className="mx-auto max-w-[1200px] space-y-5"><h1 className="text-3xl font-bold">Требует вашего действия</h1>
      <div className="flex flex-wrap items-end gap-3"><label className="block text-sm font-semibold">Поиск событий<input value={search} onChange={(event) => setSearch(event.target.value)} className="mt-2 block min-h-11 w-full max-w-md rounded-lg border border-(--color-border) bg-(--color-surface) px-3" /></label><label className="text-sm font-semibold">Модуль<select value={module} onChange={(event) => setModule(event.target.value)} className="mt-2 block min-h-11 cursor-pointer rounded-lg border border-(--color-border) bg-(--color-surface) px-3"><option value="all">Все модули</option><option value="seller">Продавец</option></select></label><label className="text-sm font-semibold">Важность<select value={severity} onChange={(event) => setSeverity(event.target.value)} className="mt-2 block min-h-11 cursor-pointer rounded-lg border border-(--color-border) bg-(--color-surface) px-3"><option value="all">Вся важность</option><option value="high">Высокая</option><option value="normal">Обычная</option></select></label><button type="button" onClick={() => { setSearch(''); setSeverity('all'); setModule('all'); }} className="min-h-11 cursor-pointer px-3 text-sm text-(--color-link)">Сбросить</button></div>
      {error && <p role="alert">{error}</p>}
      <div className="grid items-start gap-5 lg:grid-cols-[minmax(0,2fr)_minmax(240px,1fr)]"><div>{!seller ? <p role="status">Данные продавца недоступны.</p> : items.length ? <section className="overflow-x-auto rounded-xl border border-(--color-border) bg-(--color-surface) p-5"><table className="w-full min-w-[560px] text-left text-sm"><thead><tr><th className="p-3">Событие</th><th className="p-3">Модуль</th><th className="p-3">Важность</th><th className="p-3">Действие</th></tr></thead><tbody>{items.map((item) => <tr key={item.kind} className="border-t border-(--color-border)"><td className="p-3">{item.title} · {item.count}</td><td className="p-3">Продавец</td><td className="p-3">{item.severity === 'high' ? 'Высокая' : 'Обычная'}</td><td className="p-3"><Link className="cursor-pointer text-(--color-link)" href={item.href}>Открыть</Link><button type="button" disabled={pending !== null} onClick={() => router.post(`/attention/${encodeURIComponent(item.kind)}/snooze`, {}, { preserveScroll: true, onStart: () => { setError(''); setPending(item.kind); }, onFinish: () => setPending(null), onError: () => setError('Не удалось отложить событие.') })} className="ml-3 min-h-11 cursor-pointer text-(--color-link) disabled:opacity-60">{pending === item.kind ? 'Сохраняем…' : 'Отложить на 7 дней'}</button></td></tr>)}</tbody></table></section> : <p role="status" className="rounded-xl bg-(--color-surface) p-5">{search || severity !== 'all' ? 'События не найдены.' : 'Всё в порядке.'}</p>}</div><aside className="rounded-xl border border-(--color-border) bg-(--color-surface) p-5"><h2 className="text-lg font-bold">На главной</h2><p className="mt-3 text-sm">Требует вашего действия: {seller ? seller.attention.length : '—'}</p></aside></div>
      <Link href="/dashboard" className="inline-flex min-h-11 cursor-pointer items-center text-sm font-semibold text-(--color-link)">На главную</Link>
    </main>
  </CabinetLayout>;
}

import { Head, Link, useForm } from '@inertiajs/react';
import CabinetLayout from '@/layouts/CabinetLayout';

type Fact = { id: string; text: string; status: 'active' | 'inactive'; source: string; group_key: string };
type Change = { action: string; created_at: string; author: string | null };

export default function Show({ fact, version, history, status }: { fact: Fact; version: number; history: Change[]; status: string | null }) {
    const form = useForm({ version, text: fact.text, status: fact.status, source: fact.source });

    return <CabinetLayout breadcrumbs={[{ label: 'Продавец', href: '/seller' }, { label: 'База знаний', href: '/seller/knowledge' }, { label: 'Факты', href: '/seller/knowledge/facts' }, { label: 'Карточка факта' }]}>
        <Head title="Карточка факта" />
        <main className="mx-auto max-w-[900px]" data-private-profile>
            <Link href="/seller/knowledge/facts" className="cursor-pointer text-sm text-(--color-link)">← К списку фактов</Link>
            <h1 className="mt-3 text-3xl font-bold">Карточка факта</h1>
            <p className="mt-2 text-sm text-(--color-ink-muted)">Группа: {fact.group_key} · Источник: {fact.source}</p>
            {status && <p role="status" className="mt-4 rounded-lg bg-(--color-success-soft) p-3 text-sm">{status}</p>}
            <section className="mt-6 rounded-xl border border-(--color-border) bg-(--color-surface) p-5">
                <h2 className="text-xl font-bold">Содержание</h2>
                <form className="mt-4" onSubmit={event => { event.preventDefault(); form.post(`/seller/knowledge/facts/${fact.id}`, { preserveScroll: true }); }}>
                    <label className="block text-sm">Факт<textarea required maxLength={500} value={form.data.text} onChange={event => form.setData('text', event.target.value)} className="mt-1 block min-h-28 w-full rounded-lg border border-(--color-border) bg-(--color-surface) p-3 text-sm" /></label>
                    <label className="mt-3 flex items-center gap-2 text-sm"><input type="checkbox" checked={form.data.status === 'active'} onChange={event => form.setData('status', event.target.checked ? 'active' : 'inactive')} /> Использовать в ответах</label>
                    {Object.keys(form.errors).length > 0 && <p role="alert" className="mt-2 text-sm text-(--color-danger-ink)">{Object.values(form.errors).join(' ')}</p>}
                    <button disabled={form.processing} className="mt-4 min-h-11 cursor-pointer rounded-lg border border-(--color-border) px-4 text-sm font-semibold text-(--color-link) disabled:opacity-50">Сохранить факт</button>
                </form>
            </section>
            <section className="mt-6 rounded-xl border border-(--color-border) bg-(--color-surface) p-5"><h2 className="text-xl font-bold">Как бот это скажет</h2><p className="mt-3 rounded-lg bg-(--color-surface-muted) p-4 text-sm">«{form.data.text}»</p><p className="mt-2 text-sm text-(--color-ink-muted)">Точная фраза зависит от диалога и настроек продавца.</p></section>
            <section className="mt-6 rounded-xl border border-(--color-border) bg-(--color-surface) p-5"><h2 className="text-xl font-bold">История изменений</h2>{history.length === 0 ? <p className="mt-3 text-sm">Изменений пока нет.</p> : <ul className="mt-3 space-y-3">{history.map((change, index) => <li key={`${change.created_at}-${index}`} className="border-b border-(--color-border) pb-3 text-sm"><span className="font-semibold">{change.action}</span><span className="ml-2">{new Date(change.created_at).toLocaleString('ru-RU')}</span><span className="ml-2 text-(--color-ink-muted)">{change.author ?? 'Система'}</span></li>)}</ul>}</section>
        </main>
    </CabinetLayout>;
}

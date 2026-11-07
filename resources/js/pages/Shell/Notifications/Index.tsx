import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { Bell, Eye, MessageCircle } from 'lucide-react';
import CabinetLayout from '@/layouts/CabinetLayout';

type Item = { id: string; kind: string; group: string; groups?: string[]; title: string; read: boolean; created_at: string };
const tabs = [{ key: 'all', label: 'Все' }, { key: 'unread', label: 'Непрочитанные' }, { key: 'vision', label: 'Видение' }, { key: 'seller', label: 'Продавец' }, { key: 'shell', label: 'Кабинет' }];

export default function Index({ items, unread }: { items: Item[]; unread: number }) {
  const [tab, setTab] = useState('all');
  const [pending, setPending] = useState<string | null>(null);
  const [error, setError] = useState('');
  const visible = items.filter((item) => tab === 'all' || (tab === 'unread' ? !item.read : (item.groups ?? [item.group]).includes(tab)));
  const dayLabel = (value: string) => {
    const date = new Date(value);
    const today = new Date();
    const yesterday = new Date(today);
    yesterday.setDate(today.getDate() - 1);
    return date.toDateString() === today.toDateString() ? 'Сегодня' : date.toDateString() === yesterday.toDateString() ? 'Вчера' : date.toLocaleDateString('ru-RU');
  };
  const grouped = visible.reduce<Record<string, Item[]>>((groups, item) => {
    (groups[dayLabel(item.created_at)] ??= []).push(item);
    return groups;
  }, {});
  return <CabinetLayout breadcrumbs={[{ label: 'Главная', href: '/dashboard' }, { label: 'Уведомления' }]}>
    <Head title="Уведомления" /><main className="mx-auto max-w-[1200px] space-y-5"><div className="flex flex-wrap items-center justify-between gap-3"><h1 className="text-3xl font-bold">Уведомления</h1><div className="flex flex-wrap gap-4"><Link href="/profile/notifications" className="inline-flex min-h-11 cursor-pointer items-center text-sm font-semibold text-(--color-link)">Настроить уведомления →</Link><button type="button" disabled={unread === 0 || pending !== null} onClick={() => router.post('/notification-center/read-all', {}, { preserveScroll: true, onStart: () => { setError(''); setPending('all'); }, onFinish: () => setPending(null), onError: () => setError('Не удалось обновить уведомления.') })} className="min-h-11 cursor-pointer text-sm text-(--color-link) disabled:cursor-not-allowed disabled:opacity-60">{pending === 'all' ? 'Сохраняем…' : 'Прочитать все'}</button></div></div>
      <nav aria-label="Фильтр уведомлений" className="flex flex-wrap gap-2">{tabs.map((item) => <button key={item.key} type="button" aria-pressed={tab === item.key} onClick={() => setTab(item.key)} className={`min-h-11 cursor-pointer rounded-lg px-4 text-sm ${tab === item.key ? 'bg-(--color-action) text-(--color-on-action)' : 'bg-(--color-surface)'}`}>{item.label}{item.key === 'unread' && unread > 0 ? ` (${unread})` : ''}</button>)}</nav>
      {error && <p role="alert">{error}</p>}
      {visible.length ? Object.entries(grouped).map(([day, entries]) => <section key={day} className="rounded-xl border border-(--color-border) bg-(--color-surface) px-5"><h2 className="pt-4 font-semibold">{day}</h2><ul className="divide-y divide-(--color-border)">{entries?.map((item) => <li key={`${item.kind}-${item.id}`} className="flex flex-wrap items-center justify-between gap-3 py-4 text-sm"><div className="flex items-start gap-3"><span aria-hidden="true" className="grid size-10 shrink-0 place-items-center rounded-full bg-(--color-info-soft) text-(--color-link)">{item.group === 'vision' ? <Eye size={20} /> : item.group === 'seller' ? <MessageCircle size={20} /> : <Bell size={20} />}</span><div><p className={item.read ? '' : 'font-bold'}>{item.title}</p><p className="mt-1 text-(--color-ink-muted)">{item.group === 'vision' ? 'Видение' : item.group === 'seller' ? 'Продавец' : 'Кабинет'} · <time>{new Date(item.created_at).toLocaleString('ru-RU')}</time></p></div></div>{!item.read && <button type="button" disabled={pending === item.id} className="min-h-11 cursor-pointer text-(--color-link)" onClick={() => router.post(`/dashboard/notifications/${item.kind}/${item.id}/read`, {}, { preserveScroll: true, onStart: () => { setError(''); setPending(item.id); }, onFinish: () => setPending(null), onError: () => setError('Не удалось обновить уведомление.') })}>{pending === item.id ? 'Сохраняем…' : 'Отметить прочитанным'}</button>}</li>)}</ul></section>) : <div className="rounded-xl bg-(--color-surface) p-5"><p role="status">{tab === 'unread' ? 'Всё прочитано.' : 'Уведомлений пока нет.'}</p>{tab !== 'all' && <button type="button" onClick={() => setTab('all')} className="mt-3 min-h-11 cursor-pointer text-sm text-(--color-link)">Показать все уведомления</button>}</div>}
    </main>
  </CabinetLayout>;
}

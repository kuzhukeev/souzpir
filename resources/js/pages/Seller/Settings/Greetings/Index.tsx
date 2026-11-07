import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import CabinetLayout from '@/layouts/CabinetLayout';

const channels = { widget: 'Сайт', telegram: 'Telegram', api: 'API', email: 'Email', whatsapp: 'WhatsApp' } as const;
const pages = { home: 'Главная', catalog: 'Каталог', contacts: 'Контакты' } as const;
type Channel = keyof typeof channels;
type Page = keyof typeof pages;
type GreetingKey = Channel | 'widget_catalog' | 'widget_contacts' | 'after_hours';
type Greetings = Partial<Record<GreetingKey, string>>;
const field = 'min-h-11 w-full rounded-lg border border-(--color-border) bg-(--color-surface) px-3 py-2 text-sm';

export default function Index({ greetings, canWrite, status }: { greetings: Greetings; canWrite: boolean; status?: string }) {
  const form = useForm<{ greetings: Greetings }>({ greetings });
  const [channel, setChannel] = useState<Channel>('widget');
  const [page, setPage] = useState<Page>('home');
  const [afterHours, setAfterHours] = useState(false);
  const key: GreetingKey = afterHours ? 'after_hours' : channel === 'widget' && page !== 'home' ? `widget_${page}` : channel;
  const text = form.data.greetings[key] ?? '';
  const preview = text.trim() ? text.replaceAll('{имя}', 'Анна').replaceAll('{страница}', pages[page]) : 'Автоматическое приветствие';
  function update(value: string) { form.setData('greetings', { ...form.data.greetings, [key]: value }); }
  function submit(event: React.FormEvent) { event.preventDefault(); form.post('/seller/settings/greetings', { preserveScroll: true }); }

  return <CabinetLayout breadcrumbs={[{ label: 'Продавец', href: '/seller' }, { label: 'Настройки', href: '/seller/settings' }, { label: 'Приветствия' }]}>
    <Head title="Приветствия" />
    <main className="mx-auto max-w-[1000px] space-y-5" data-private-profile>
      <header><h1 className="text-3xl font-bold">Приветствия</h1><p className="mt-2 text-sm text-(--color-ink-muted)">Настройте сообщение для первого ответа по каналу и разделу сайта. Пустое поле использует обычный ответ продавца.</p></header>
      {status && <p role="status" className="rounded-lg bg-(--color-brand-primary-soft) p-3 text-sm">{status}</p>}
      <form onSubmit={submit} className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_minmax(260px,0.8fr)]">
        <section className="space-y-4 rounded-xl border border-(--color-border) bg-(--color-surface) p-5">
          <label className="block text-sm font-semibold">Канал<select aria-label="Канал" className={`${field} mt-2 cursor-pointer`} value={channel} onChange={event => { setChannel(event.target.value as Channel); setAfterHours(false); }}>{(Object.entries(channels) as [Channel, string][]).map(([id, label]) => <option key={id} value={id} disabled={id === 'whatsapp'}>{label}{id === 'whatsapp' ? ' · Скоро' : ''}</option>)}</select></label>
          {channel === 'widget' && <label className="block text-sm font-semibold">Раздел сайта<select aria-label="Раздел сайта" className={`${field} mt-2 cursor-pointer`} value={page} onChange={event => setPage(event.target.value as Page)}>{(Object.entries(pages) as [Page, string][]).map(([id, label]) => <option key={id} value={id}>{label}</option>)}</select></label>}
          <label className="flex cursor-pointer items-center gap-3 text-sm"><input type="checkbox" checked={afterHours} onChange={event => setAfterHours(event.target.checked)} />В нерабочее время</label>
          <label className="block text-sm font-semibold">Текст приветствия<textarea aria-label="Текст приветствия" disabled={!canWrite} maxLength={1000} rows={5} value={text} onChange={event => update(event.target.value)} placeholder="Автоматическое приветствие" className={`${field} mt-2 resize-y font-normal`} /></label>
          <div className="flex flex-wrap items-center gap-2 text-sm"><span>Переменные:</span>{['{имя}', '{страница}'].map(variable => <button key={variable} type="button" disabled={!canWrite} className="min-h-9 cursor-pointer rounded-full border border-(--color-border) px-3 disabled:opacity-50" onClick={() => update(text + variable)}>{variable}</button>)}</div>
          {Object.keys(form.errors).length > 0 && <p role="alert" className="text-sm text-(--color-danger-ink)">Не удалось сохранить. Проверьте текст приветствия.</p>}
          <div className="flex flex-wrap items-center gap-3">{canWrite && <button disabled={form.processing} className="min-h-11 cursor-pointer rounded-lg bg-(--color-link) px-4 text-sm font-semibold text-white disabled:opacity-50">{form.processing ? 'Сохраняем…' : 'Сохранить'}</button>}<Link href="/seller/settings" className="cursor-pointer text-sm text-(--color-link)">К настройкам</Link></div>
        </section>
        <section className="rounded-xl border border-(--color-border) bg-(--color-surface) p-5"><h2 className="text-lg font-bold">Предпросмотр виджета</h2><div className="mt-4 rounded-2xl border border-(--color-border) bg-(--color-surface-muted) p-4"><p className="text-sm font-semibold">Продавец</p><p className="mt-3 rounded-xl border border-(--color-border) bg-(--color-surface) p-3 text-sm whitespace-pre-wrap">{preview}</p></div><p className="mt-3 text-sm text-(--color-ink-muted)">Пример: имя «Анна», раздел «{pages[page]}».</p></section>
      </form>
    </main>
  </CabinetLayout>;
}

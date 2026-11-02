import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import CabinetLayout from '@/layouts/CabinetLayout';

type Account = { id: string; name: string; status: string; mode: string; tokenConfigured: boolean; managersChat: string | null; webhookUrl: string; webhookSecret: string | null };
export default function Settings({ account, canManage, status }: { account: Account | null; canManage: boolean; status?: string | null }) {
  const [showSecret, setShowSecret] = useState(false);
  const form = useForm({ name: account?.name ?? 'Telegram', token: '', managers_chat: account?.managersChat ?? '', mode: account?.mode ?? 'bot' });
  const save = (event: React.FormEvent) => { event.preventDefault(); form.put('/seller/channels/telegram'); };
  return <CabinetLayout breadcrumbs={[{ label: 'Главная', href: '/dashboard' }, { label: 'Каналы', href: '/seller/channels' }, { label: 'Telegram' }]}>
    <Head title="Telegram" />
    <main className="mx-auto max-w-3xl pb-12"><Link href="/seller/channels" className="inline-flex min-h-11 cursor-pointer items-center text-sm font-semibold text-(--color-link)">← К каналам</Link>
      <h1 className="mt-3 text-3xl font-bold">Telegram</h1><p className="mt-2 text-sm text-(--color-ink-muted)">Бот для переписки с клиентами выбранного бренда.</p>
      {status && <p role="status" className="mt-4 rounded-lg bg-(--color-success-soft) p-3 text-sm">{status}</p>}
      <form onSubmit={save} className="mt-6 space-y-5 rounded-2xl border border-(--color-border) bg-(--color-surface) p-5 shadow-(--shadow-card)">
        <label className="block text-sm font-semibold">Название канала<input disabled={!canManage} value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} className="mt-2 min-h-11 w-full rounded-lg border border-(--color-border) bg-(--color-surface) px-3 text-sm" /></label>{form.errors.name && <p role="alert" className="text-sm text-(--color-danger-ink)">{form.errors.name}</p>}
        <label className="block text-sm font-semibold">Токен бота<input disabled={!canManage} type="password" autoComplete="off" value={form.data.token} onChange={(e) => form.setData('token', e.target.value)} placeholder={account?.tokenConfigured ? 'Оставьте пустым, чтобы сохранить текущий' : 'Введите токен'} className="mt-2 min-h-11 w-full rounded-lg border border-(--color-border) bg-(--color-surface) px-3 text-sm" /></label>{form.errors.token && <p role="alert" className="text-sm text-(--color-danger-ink)">{form.errors.token}</p>}
        <label className="block text-sm font-semibold">Чат менеджеров<input disabled={!canManage} value={form.data.managers_chat} onChange={(e) => form.setData('managers_chat', e.target.value)} placeholder="Например, -1001234567890" className="mt-2 min-h-11 w-full rounded-lg border border-(--color-border) bg-(--color-surface) px-3 text-sm" /></label>{form.errors.managers_chat && <p role="alert" className="text-sm text-(--color-danger-ink)">{form.errors.managers_chat}</p>}
        <label className="block text-sm font-semibold">Режим<select disabled={!canManage} value={form.data.mode} onChange={(e) => form.setData('mode', e.target.value)} className="mt-2 min-h-11 w-full cursor-pointer rounded-lg border border-(--color-border) bg-(--color-surface) px-3 text-sm"><option value="bot">Автономно</option><option value="suggest">Подсказки</option><option value="manager">Выключен</option></select></label>
        {canManage && <button disabled={form.processing} className="min-h-11 cursor-pointer rounded-lg bg-(--color-link) px-5 text-sm font-semibold text-white">{form.processing ? 'Сохраняем…' : 'Сохранить'}</button>}
      </form>
      {account && <section className="mt-5 rounded-2xl border border-(--color-border) bg-(--color-surface) p-5 text-sm"><h2 className="text-xl font-bold">Вебхук</h2><p className="mt-2">Укажите эти данные при настройке бота. Telegram требует публичный HTTPS-адрес. Входящие сообщения сохраняются в диалогах; ответы из кабинета пока не доставляются.</p><p className="mt-3 break-all">Адрес: <code>{account.webhookUrl}</code></p>{account.webhookSecret && <p className="mt-2">Секрет: <code>{showSecret ? account.webhookSecret : '••••••••'}</code> <button type="button" onClick={() => setShowSecret(!showSecret)} className="ml-2 min-h-11 cursor-pointer text-sm font-semibold text-(--color-link)">{showSecret ? 'Скрыть' : 'Показать'}</button></p>}</section>}
    </main>
  </CabinetLayout>;
}

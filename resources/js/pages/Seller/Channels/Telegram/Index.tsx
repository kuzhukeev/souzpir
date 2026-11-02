import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import CabinetLayout from '@/layouts/CabinetLayout';

type Channel = { id: string; name: string; type: string; status: string; mode: string; dialogs7d: number };
const modes: Record<string, string> = { bot: 'Автономно', suggest: 'Подсказки', manager: 'Выключен' };
const statuses: Record<string, string> = { active: 'Токен сохранён', unconfigured: 'Не настроен', disabled: 'Отключён', error: 'Ошибка', planned: 'Скоро' };

export default function Index({ channels, canManage, status }: { channels: Channel[]; canManage: boolean; status?: string | null }) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [confirmStop, setConfirmStop] = useState(false);
  const saveMode = (channel: Channel, mode: string) => {
    setError(''); setBusy(true);
    router.patch(`/seller/channels/${channel.id}/mode`, { mode }, {
      onFinish: () => setBusy(false),
      onError: (errors) => setError(Object.values(errors)[0] ?? 'Не удалось изменить режим.'),
      onNetworkError: () => { setError('Нет связи. Проверьте режим перед повтором.'); return false; },
    });
  };
  const stop = () => {
    setError(''); setBusy(true);
    router.post('/seller/channels/stop', { confirm: true }, {
      onFinish: () => { setBusy(false); setConfirmStop(false); },
      onError: (errors) => setError(Object.values(errors)[0] ?? 'Не удалось остановить ботов.'),
      onNetworkError: () => { setError('Нет связи. Проверьте каналы перед повтором.'); return false; },
    });
  };
  return <CabinetLayout breadcrumbs={[{ label: 'Главная', href: '/dashboard' }, { label: 'Каналы' }]}>
    <Head title="Каналы" />
    <main className="mx-auto max-w-5xl pb-12">
      <h1 className="text-3xl font-bold">Каналы</h1>
      <p className="mt-2 text-sm text-(--color-ink-muted)">Управляйте общением с клиентами выбранного бренда.</p>
      {status && <p role="status" className="mt-4 rounded-lg bg-(--color-success-soft) p-3 text-sm">{status}</p>}
      {error && <p role="alert" className="mt-4 rounded-lg bg-(--color-danger-soft) p-3 text-sm">{error}</p>}
      {canManage && <button type="button" onClick={() => setConfirmStop(true)} className="mt-6 min-h-11 cursor-pointer rounded-lg border border-(--color-border) px-4 text-sm font-semibold">Остановить всех ботов</button>}
      {confirmStop && <div role="dialog" aria-modal="true" aria-labelledby="stop-title" className="mt-4 rounded-xl border border-(--color-border) bg-(--color-surface) p-5 shadow-(--shadow-card)">
        <h2 id="stop-title" className="text-lg font-bold">Остановить всех ботов?</h2>
        <p className="mt-2 text-sm">Все каналы в автономном режиме перейдут в режим «Выключен».</p>
        <div className="mt-4 flex gap-3"><button type="button" disabled={busy} onClick={stop} className="min-h-11 cursor-pointer rounded-lg bg-(--color-danger-soft) px-4 text-sm font-semibold">Остановить</button><button type="button" onClick={() => setConfirmStop(false)} className="min-h-11 cursor-pointer rounded-lg border border-(--color-border) px-4 text-sm">Отмена</button></div>
      </div>}
      {channels.length === 0 ? <section className="mt-6 rounded-2xl border border-(--color-border) bg-(--color-surface) p-6"><h2 className="text-xl font-bold">Каналы ещё не подключены</h2><p className="mt-2 text-sm">Начните с Telegram.</p><Link href="/seller/channels/telegram" className="mt-4 inline-flex min-h-11 cursor-pointer items-center text-sm font-semibold text-(--color-link)">Настроить Telegram →</Link></section>
        : <div className="mt-6 grid gap-4 md:grid-cols-2">{channels.map((channel) => <section key={channel.id} className="rounded-2xl border border-(--color-border) bg-(--color-surface) p-5 shadow-(--shadow-card)">
          <h2 className="text-xl font-bold">{channel.name}</h2><p className="mt-2 text-sm">{statuses[channel.status] ?? channel.status}</p><p className="mt-1 text-sm">Диалогов за 7 дней: {channel.dialogs7d}</p>
          <label className="mt-4 block text-sm font-semibold" htmlFor={`mode-${channel.id}`}>Режим</label>
          <select id={`mode-${channel.id}`} disabled={!canManage || busy} value={channel.mode} onChange={(event) => saveMode(channel, event.target.value)} className="mt-2 min-h-11 w-full cursor-pointer rounded-lg border border-(--color-border) bg-(--color-surface) px-3 text-sm">{Object.entries(modes).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select>
          {channel.type === 'telegram' && <Link href="/seller/channels/telegram" className="mt-4 inline-flex min-h-11 cursor-pointer items-center text-sm font-semibold text-(--color-link)">Настройки Telegram →</Link>}
        </section>)}</div>}
    </main>
  </CabinetLayout>;
}

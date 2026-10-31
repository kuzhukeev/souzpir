import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { Button, Input } from '@/components/ui';
import CabinetLayout from '@/layouts/CabinetLayout';

type Item = { id: string; titles: string[]; created_at: string };

export default function DigestInbox({ items, digestTime, timezone, canSetTime, canReadSettings }: { items: Item[]; digestTime: string; timezone: string | null; canSetTime: boolean; canReadSettings: boolean }) {
  const [time, setTime] = useState(digestTime);
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState('');
  const save = () => {
    setNotice('');
    router.put('/seller/settings/notifications/digests/time', { digest_time: time }, {
      preserveScroll: true,
      onStart: () => setBusy(true),
      onFinish: () => setBusy(false),
      onSuccess: () => setNotice('Время сводки сохранено.'),
      onError: () => setNotice('Укажите время в формате ЧЧ:ММ.'),
      onNetworkError: () => { setNotice('Не удалось связаться с сервером.'); return false; },
      onHttpException: () => { setNotice('Не удалось сохранить время.'); return false; },
    });
  };
  return (
    <CabinetLayout breadcrumbs={[{ label: 'Главная' }, { label: 'Продавец' }, { label: 'Сводки' }]}>
      <Head title="Сводки уведомлений" />
      <h1 className="text-3xl font-bold">Сводки уведомлений</h1>
      {canReadSettings && <Link href="/seller/settings/notifications" className="mt-4 inline-block text-(--color-link)">Настройки уведомлений</Link>}
      <section className="mt-6 rounded-2xl bg-(--color-surface) p-5 shadow-(--shadow-card)" aria-label="Настройка времени сводки">
        {canSetTime ? <div className="flex flex-wrap items-end gap-3"><Input label="Моё время сводки" type="time" required value={time} disabled={busy} onChange={(event) => setTime(event.target.value)} /><Button disabled={busy || !time} loading={busy} onClick={save}>Сохранить время</Button></div> : <p className="text-sm text-(--color-ink-muted)">Вашей роли недоступны события для сводки.</p>}
        <p className="mt-3 text-sm text-(--color-ink-muted)">Раз в день по времени бренда{timezone ? ` (${timezone})` : ''}. Ожидающая сводка сохранит ранее назначенное время.</p>
        <p role="status" className="mt-2 text-sm">{notice}</p>
      </section>
      {items.length === 0 ? <p className="mt-6 text-(--color-ink-muted)">Пока нет доставленных сводок.</p> : (
        <ul className="mt-6 space-y-4">{items.map((item) => <li key={item.id} className="rounded-2xl bg-(--color-surface) p-5 shadow-(--shadow-card)"><time dateTime={item.created_at} className="text-sm text-(--color-ink-muted)">{new Date(item.created_at).toLocaleString('ru-RU', { dateStyle: 'medium', timeStyle: 'short', ...(timezone ? { timeZone: timezone } : {}) })}</time><ul className="mt-3 list-disc space-y-2 pl-5">{item.titles.map((title, index) => <li key={index}>{title}</li>)}</ul></li>)}</ul>
      )}
    </CabinetLayout>
  );
}

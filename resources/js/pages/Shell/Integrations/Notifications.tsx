import { useState, type FormEvent } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, Mail, Send } from 'lucide-react';
import { Button, Input } from '@/components/ui';
import { Page, panel, focus } from '@/features/Shell/Organizations/Shared';

type Matrix = Record<string, Record<string, boolean>>;
type Props = { telegram_configured: boolean; telegram_mask: string | null; report_emails: string[]; matrix: Matrix };
const events = [
  { key: 'handoff', label: 'Диалог ждёт менеджера' },
  { key: 'unknown_question', label: 'Неизвестный вопрос' },
  { key: 'report_published', label: 'Отчёт опубликован' },
  { key: 'limit_80', label: 'Лимит тарифа 80%' },
];
const channels = [
  { key: 'cabinet', label: 'В кабинете' },
  { key: 'email', label: 'Почта' },
  { key: 'telegram', label: 'Telegram' },
];

export default function Notifications({ telegram_configured, telegram_mask, report_emails, matrix: initialMatrix = {} }: Props) {
  const [chat, setChat] = useState('');
  const [emails, setEmails] = useState(report_emails.join('\n'));
  const [matrix, setMatrix] = useState(initialMatrix);
  const [saving, setSaving] = useState(false);
  const [testing, setTesting] = useState<'telegram' | 'email' | null>(null);
  const [notice, setNotice] = useState('');
  const [error, setError] = useState('');

  const save = (event: FormEvent) => {
    event.preventDefault();
    setError('');
    setNotice('');
    router.put('/integrations/notifications', {
      telegram_chat: chat,
      report_emails: emails.split(/[\n,;]+/).map((value) => value.trim()).filter(Boolean),
      matrix,
    }, {
      onStart: () => setSaving(true),
      onFinish: () => setSaving(false),
      onSuccess: () => { setChat(''); setNotice('Настройки сохранены. Внешняя доставка выключена.'); },
      onError: (errors) => setError(Object.values(errors)[0] ?? 'Не удалось сохранить настройки.'),
      onNetworkError: () => { setError('Не удалось связаться с сервером. Попробуйте ещё раз.'); return false; },
      onHttpException: () => { setError('Не удалось сохранить настройки. Попробуйте ещё раз.'); return false; },
    });
  };

  const test = (channel: 'telegram' | 'email') => {
    setError('');
    setNotice('');
    router.post('/integrations/notifications/test', { channel }, {
      onStart: () => setTesting(channel),
      onFinish: () => setTesting(null),
      onSuccess: () => setNotice('Проверка пройдена в fake режиме. Сообщения не отправлялись.'),
      onError: () => setError('Сначала сохраните настройки канала.'),
      onNetworkError: () => { setError('Не удалось связаться с сервером. Попробуйте ещё раз.'); return false; },
      onHttpException: () => { setError('Не удалось проверить канал. Попробуйте ещё раз.'); return false; },
    });
  };

  return (
    <>
      <Head title="Уведомления компании" />
      <Page title="Уведомления компании" subtitle="Каналы уведомлений для всей команды">
        <Link href="/integrations" className={'mb-5 inline-flex min-h-10 cursor-pointer items-center gap-2 text-sm font-semibold text-(--color-link) ' + focus}>
          <ArrowLeft size={17} aria-hidden="true" /> Подключения
        </Link>
        <p className="mb-5 rounded-xl bg-(--color-warning-soft) p-4 text-sm text-(--color-warning-ink)">
          Внешняя доставка пока выключена. Проверка канала работает без отправки сообщений.
        </p>
        {notice && <p role="status" className="mb-4 text-sm text-(--color-success-ink)">{notice}</p>}
        {error && <p role="alert" className="mb-4 text-sm text-(--color-danger-ink)">{error}</p>}
        <form onSubmit={save}>
          <div className="grid gap-4 xl:grid-cols-2">
            <section className={panel}>
              <h2 className="flex items-center gap-2 text-xl font-bold"><Send size={22} aria-hidden="true" /> Telegram</h2>
              <p className="mt-2 text-sm text-(--color-ink-muted)">{telegram_configured ? `Чат настроен${telegram_mask ? `: ${telegram_mask}` : ''}` : 'Чат не настроен'}</p>
              <div className="mt-5"><Input label="ID чата Telegram" value={chat} onChange={(event) => setChat(event.target.value)} hint="Оставьте пустым, чтобы сохранить текущий чат." /></div>
              <p className="mt-2 text-sm text-(--color-ink-muted)">Подключение чата по коду станет доступно после приёма подтверждения от Telegram-бота. Сейчас можно сохранить известный ID чата; отправка выключена.</p>
              <Button className="mt-4" type="button" variant="secondary" disabled={!telegram_configured || saving} loading={testing === 'telegram'} onClick={() => test('telegram')}>Проверить без отправки</Button>
            </section>
            <section className={panel}>
              <h2 className="flex items-center gap-2 text-xl font-bold"><Mail size={22} aria-hidden="true" /> Почта для отчётов</h2>
              <p className="mt-2 text-sm text-(--color-ink-muted)">Адреса для отчётов и системных уведомлений</p>
              <label htmlFor="report-emails" className="mt-5 block text-sm font-semibold">Адреса электронной почты</label>
              <textarea id="report-emails" value={emails} onChange={(event) => setEmails(event.target.value)} rows={4} placeholder="Один адрес в строке" className={'mt-1 w-full rounded-xl border border-(--color-control-border) bg-(--color-surface) p-3 text-sm text-(--color-ink) ' + focus} />
              <p className="mt-1 text-sm text-(--color-ink-muted)">До пяти адресов, по одному в строке. Пустой список отключает почту.</p>
              <Button className="mt-4" type="button" variant="secondary" disabled={report_emails.length === 0 || saving} loading={testing === 'email'} onClick={() => test('email')}>Проверить без отправки</Button>
            </section>
          </div>
          <section className={`${panel} mt-5`} aria-labelledby="common-notifications-title">
            <div className="flex flex-wrap items-center justify-between gap-3"><h2 id="common-notifications-title" className="text-xl font-bold">Общие уведомления</h2><Link href="/profile/notifications" className={'min-h-10 cursor-pointer text-sm font-semibold text-(--color-link) ' + focus}>Личные настройки уведомлений</Link></div>
            <p className="mt-2 text-sm text-(--color-ink-muted)">Настройки сохраняются для текущего бренда. Внешняя доставка пока выключена.</p>
            <div className="mt-4 overflow-x-auto"><table className="w-full min-w-[620px] text-left text-sm"><thead><tr className="border-b border-(--color-border)"><th className="p-3">Событие</th>{channels.map((channel) => <th key={channel.key} className="p-3 text-center">{channel.label}</th>)}</tr></thead><tbody>{events.map((event) => <tr key={event.key} className="border-b border-(--color-border)"><th scope="row" className="p-3 font-medium">{event.label}</th>{channels.map((channel) => <td key={channel.key} className="p-3 text-center"><input type="checkbox" className="cursor-pointer" aria-label={`${event.label}: ${channel.label}`} checked={matrix[event.key]?.[channel.key] ?? false} onChange={(change) => setMatrix((current) => ({ ...current, [event.key]: { ...current[event.key], [channel.key]: change.target.checked } }))} /></td>)}</tr>)}</tbody></table></div>
          </section>
          <div className="mt-5 flex justify-end"><Button type="submit" loading={saving}>Сохранить</Button></div>
        </form>
      </Page>
    </>
  );
}

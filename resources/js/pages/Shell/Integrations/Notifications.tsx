import { useState, type FormEvent } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, Mail, Send } from 'lucide-react';
import { Button, Modal } from '@/components/ui';
import { Page, panel, focus } from '@/features/Shell/Organizations/Shared';

type Matrix = Record<string, Record<string, boolean>>;
type Props = { telegram_configured: boolean; telegram_mask: string | null; report_emails: string[]; matrix: Matrix; telegram_link?: { code: string | null; pending: boolean; bot: string | null }; company_deliveries?: { id: string; event_key: string; channel: string; status: string }[] };
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

export default function Notifications({ telegram_configured, telegram_mask, report_emails, matrix: initialMatrix = {}, telegram_link, company_deliveries = [] }: Props) {
  const [linkOpen, setLinkOpen] = useState(false);
  const [linkBusy, setLinkBusy] = useState(false);
  const [emails, setEmails] = useState(report_emails);
  const [emailInput, setEmailInput] = useState('');
  const [matrix, setMatrix] = useState(initialMatrix);
  const [saving, setSaving] = useState(false);
  const [testing, setTesting] = useState<'telegram' | 'email' | null>(null);
  const [notice, setNotice] = useState('');
  const [error, setError] = useState('');

  const save = (event: FormEvent) => {
    event.preventDefault();
    if (emailInput.trim()) { setError('Добавьте введённый адрес перед сохранением.'); return; }
    setError('');
    setNotice('');
    router.put('/integrations/notifications', {
      telegram_chat: null,
      report_emails: emails,
      matrix,
    }, {
      onStart: () => setSaving(true),
      onFinish: () => setSaving(false),
      onSuccess: () => setNotice('Настройки сохранены. Внешняя доставка выключена.'),
      onError: (errors) => setError(Object.values(errors)[0] ?? 'Не удалось сохранить настройки.'),
      onNetworkError: () => { setError('Не удалось связаться с сервером. Попробуйте ещё раз.'); return false; },
      onHttpException: () => { setError('Не удалось сохранить настройки. Попробуйте ещё раз.'); return false; },
    });
  };

  const addEmail = () => {
    const value = emailInput.trim();
    if (!value) return;
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) { setError('Введите корректный адрес электронной почты.'); return; }
    if (emails.some((email) => email.toLowerCase() === value.toLowerCase())) { setError('Этот адрес уже добавлен.'); return; }
    if (emails.length >= 5) { setError('Можно добавить не более пяти адресов.'); return; }
    setEmails((current) => [...current, value]);
    setEmailInput('');
    setError('');
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
              <p className="mt-3 text-sm text-(--color-ink-muted)">Подключите Telegram с помощью одноразового кода.</p>
              <Button className="mt-4" type="button" variant="secondary" onClick={() => setLinkOpen(true)}>Подключить Telegram</Button>{' '}
              <Button className="mt-4" type="button" variant="secondary" disabled={!telegram_configured || saving} loading={testing === 'telegram'} onClick={() => test('telegram')}>Проверить без отправки</Button>
            </section>
            <section className={panel}>
              <h2 className="flex items-center gap-2 text-xl font-bold"><Mail size={22} aria-hidden="true" /> Почта для отчётов</h2>
              <p className="mt-2 text-sm text-(--color-ink-muted)">Адреса для отчётов и системных уведомлений</p>
              <label htmlFor="report-emails" className="mt-5 block text-sm font-semibold">Адреса электронной почты</label>
              <div className="mt-2 flex flex-wrap gap-2">{emails.map((email) => <span key={email} className="inline-flex items-center gap-2 rounded-full bg-(--color-surface-muted) px-3 py-1 text-sm">{email}<button type="button" aria-label={`Удалить ${email}`} className="cursor-pointer" onClick={() => setEmails((current) => current.filter((item) => item !== email))}>×</button></span>)}</div>
              <div className="mt-2 flex gap-2"><input id="report-emails" type="email" value={emailInput} onChange={(event) => setEmailInput(event.target.value)} onKeyDown={(event) => { if (event.key === 'Enter') { event.preventDefault(); addEmail(); } }} placeholder="name@example.ru" className={'min-h-11 min-w-0 flex-1 rounded-xl border border-(--color-control-border) bg-(--color-surface) p-3 text-sm text-(--color-ink) ' + focus} /><Button type="button" variant="secondary" onClick={addEmail}>Добавить</Button></div>
              <p className="mt-1 text-sm text-(--color-ink-muted)">До пяти адресов. Пустой список отключает почту.</p>
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
        <section className={`${panel} mt-5`} aria-labelledby="company-deliveries-title"><h2 id="company-deliveries-title" className="text-xl font-bold">Последние проверки доставки</h2>{company_deliveries.length === 0 ? <p className="mt-3 text-sm text-(--color-ink-muted)">Доставок пока нет.</p> : <ul className="mt-3 divide-y divide-(--color-border)">{company_deliveries.map((delivery) => <li key={delivery.id} className="flex flex-wrap justify-between gap-2 py-3 text-sm"><span>{({ 'seller.conversation.handed_off': 'Диалог ждёт менеджера', 'seller.unknown_question.logged': 'Неизвестный вопрос', 'vision.report.published': 'Отчёт опубликован', 'seller.quota.threshold_reached': 'Лимит тарифа 80%' } as Record<string, string>)[delivery.event_key] ?? 'Уведомление'} · {delivery.channel === 'email' ? 'Почта' : 'Telegram'}</span><span>{({ pending: 'В очереди', simulated: 'Проверено без отправки', cancelled: 'Отменено', failed: 'Ошибка' } as Record<string, string>)[delivery.status] ?? 'Неизвестно'}</span></li>)}</ul>}</section>
        <Modal open={linkOpen} title="Подключение Telegram" onClose={() => setLinkOpen(false)}>
          <p>1. Получите одноразовый код. 2. Отправьте боту команду с кодом. 3. Проверьте и подтвердите подключение.</p>
          {error && <p role="alert" className="mt-3 text-(--color-danger-ink)">{error}</p>}
          {telegram_link?.code ? <>
            <p className="mt-4">Откройте бота @{telegram_link.bot} и отправьте ему команду:</p>
            <p className="mt-2 text-lg font-semibold">/start {telegram_link.code}</p>
            <p className="mt-2">Код действует 10 минут. {telegram_link.pending ? 'Ответ бота получен.' : 'Ожидаем ответ бота.'}</p>
            <div className="mt-4 flex flex-wrap gap-2">
              <Button type="button" variant="secondary" onClick={() => router.reload({ only: ['telegram_link'] })}>Проверить подключение</Button>
              <Button type="button" disabled={!telegram_link.pending || linkBusy} loading={linkBusy} onClick={() => router.post('/integrations/notifications/telegram/confirm', {}, { onStart: () => setLinkBusy(true), onFinish: () => setLinkBusy(false), onSuccess: () => { setLinkOpen(false); setNotice('Telegram подключён.'); }, onError: (errors) => setError(Object.values(errors)[0] ?? 'Не удалось подтвердить подключение.') })}>Подтвердить</Button>
            </div>
          </> : <Button className="mt-4" type="button" loading={linkBusy} onClick={() => router.post('/integrations/notifications/telegram/start', {}, { onStart: () => setLinkBusy(true), onFinish: () => setLinkBusy(false), onError: (errors) => setError(Object.values(errors)[0] ?? 'Не удалось получить код.') })}>Получить код</Button>}
        </Modal>
      </Page>
    </>
  );
}

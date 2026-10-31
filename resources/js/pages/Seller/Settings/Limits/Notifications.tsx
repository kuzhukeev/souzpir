import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { Bell, MessageCircle, Clock3, BookOpen, FileText, TriangleAlert, Link2, ChartNoAxesCombined } from 'lucide-react';
import { Button, Select } from '@/components/ui';
import CabinetLayout from '@/layouts/CabinetLayout';

type Channel = 'cabinet' | 'email' | 'telegram';
type Recipient = 'all' | 'owner' | 'admin' | 'manager' | 'editor';
type Row = { event: string; label: string; recipient: Recipient; roles: Recipient[]; channels: Record<Channel, boolean>; timing: 'immediate' | 'digest' };
type Test = { id: string; event_key: string; channel: Channel; status: string; created_at: string };
const roles: Record<Recipient, string> = { all: 'Все с доступом', owner: 'Владелец', admin: 'Администраторы', manager: 'Менеджеры', editor: 'Редакторы' };
const channels: { key: Channel; label: string }[] = [{ key: 'cabinet', label: 'Кабинет' }, { key: 'email', label: 'Почта' }, { key: 'telegram', label: 'Telegram' }];
const icons = [MessageCircle, Clock3, BookOpen, FileText, TriangleAlert, Link2, ChartNoAxesCombined];

export default function Notifications({ rows, canEdit, digestAvailable, testKey: initialTestKey, tests }: { rows: Row[]; canEdit: boolean; digestAvailable: boolean; testKey: string; tests: Test[] }) {
  const [original, setOriginal] = useState(rows);
  const [current, setCurrent] = useState(rows);
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState('');
  const [testEvent, setTestEvent] = useState(rows[0]?.event ?? '');
  const [testChannel, setTestChannel] = useState<Channel>('cabinet');
  const [testKey, setTestKey] = useState(initialTestKey);
  const changes = current.filter((row, i) => JSON.stringify(row) !== JSON.stringify(original[i]));
  const edit = (event: string, update: Partial<Row>) => setCurrent((items) => items.map((row) => row.event === event ? { ...row, ...update } : row));
  const save = () => {
    setNotice('');
    router.put('/seller/settings/notifications', { changes }, {
      preserveScroll: true,
      onStart: () => setBusy(true),
      onFinish: () => setBusy(false),
      onSuccess: (page) => { const updated = (page.props.rows as Row[] | undefined) ?? current; setOriginal(updated); setCurrent(updated); setNotice('Настройки сохранены.'); },
      onError: () => setNotice('Не удалось сохранить настройки. Проверьте выбранные значения.'),
      onNetworkError: () => { setNotice('Не удалось связаться с сервером.'); return false; },
      onHttpException: () => { setNotice('Не удалось сохранить настройки. Попробуйте ещё раз.'); return false; },
    });
  };
  const sendTest = () => {
    setNotice('');
    router.post('/seller/settings/notifications/test', { event: testEvent, channel: testChannel, key: testKey }, {
      preserveScroll: true,
      onStart: () => setBusy(true),
      onFinish: () => setBusy(false),
      onSuccess: (page) => { setTestKey(page.props.testKey as string); setNotice('Тестовое уведомление поставлено в очередь.'); },
      onError: () => setNotice('Не удалось поставить тест в очередь.'),
      onNetworkError: () => { setNotice('Связь потеряна. Повтор сохранит тот же номер запроса.'); return false; },
      onHttpException: () => { setNotice('Не удалось поставить тест в очередь.'); return false; },
    });
  };
  return (
    <CabinetLayout breadcrumbs={[{ label: 'Главная' }, { label: 'Продавец' }, { label: 'Настройки' }, { label: 'Уведомления' }]}>
      <Head title="Уведомления модуля" />
      <header className="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div><h1 className="text-3xl font-bold tracking-tight">Уведомления модуля</h1><p className="mt-1 text-base text-(--color-ink-muted)">Выберите получателей, каналы и время отправки</p></div>
      </header>
      <nav aria-label="Настройки продавца" className="mb-6 flex flex-wrap gap-2">
        <Link href="/seller/settings/limits" className="rounded-lg bg-(--color-surface) px-4 py-3 text-sm text-(--color-link)">Лимиты</Link>
        <span aria-current="page" className="rounded-lg bg-(--color-success-soft) px-4 py-3 text-sm font-semibold text-(--color-action)">Уведомления</span>
      </nav>
      <p className="mb-4 text-sm text-(--color-ink-muted)">Получатели выбираются среди участников с доступом к бренду и событию. Их личные настройки каналов также учитываются. Сводка отправляется раз в день в личное время получателя по часовому поясу бренда.</p>
      <Link href="/seller/settings/notifications/digests" className="mb-4 inline-block text-sm text-(--color-link)">Мои сводки</Link>
      {!digestAvailable && <p role="status" className="mb-4 text-sm text-(--color-ink-muted)">Доставка сводок пока недоступна.</p>}
      <section aria-label="Тестовое уведомление" className="mb-4 flex flex-wrap items-end gap-3 rounded-2xl bg-(--color-surface) p-4">
        <Select label="Событие для теста" value={testEvent} disabled={!canEdit || busy} options={rows.map((row) => ({ value: row.event, label: row.label }))} onValueChange={setTestEvent} />
        <Select label="Канал теста" value={testChannel} disabled={!canEdit || busy} options={channels.map((channel) => ({ value: channel.key, label: channel.label }))} onValueChange={(value) => setTestChannel(value as Channel)} />
        <Button disabled={!canEdit || busy} onClick={sendTest}>Отправить тест себе</Button>
        <p className="text-sm text-(--color-ink-muted)">Доставка зависит от ваших прав и личных настроек канала.</p>
      </section>
      {tests.length > 0 && <div className="mb-4 text-sm text-(--color-ink-muted)"><p className="font-semibold">Последние тесты</p><ul>{tests.map((test) => <li key={test.id}>{channels.find((channel) => channel.key === test.channel)?.label}: {({ pending: 'в очереди', sending: 'отправляется', sent: 'доставлен', failed: 'не отправлен', uncertain: 'результат неизвестен', cancelled: 'доступ или канал отключён' } as Record<string, string>)[test.status] ?? 'статус неизвестен'}</li>)}</ul></div>}
      <section aria-label="Уведомления по событиям" className="rounded-2xl bg-(--color-surface) p-5 shadow-(--shadow-card)">
        <ul className="divide-y divide-(--color-border)">
          {current.map((row, index) => { const Icon = icons[index] || Bell; return (
            <li key={row.event} className="grid gap-3 py-4 xl:grid-cols-[minmax(190px,1fr)_minmax(175px,0.8fr)_minmax(250px,1.2fr)_minmax(150px,0.7fr)] xl:items-end">
              <div className="flex min-h-10 items-center gap-3"><span className="grid size-11 shrink-0 place-items-center rounded-full bg-(--color-info-soft) text-(--color-link)"><Icon size={20} aria-hidden="true" /></span><p className="font-semibold">{row.label}</p></div>
              <Select label="Кому" value={row.recipient} disabled={!canEdit || busy} options={row.roles.map((role) => ({ value: role, label: roles[role] }))} onValueChange={(value) => edit(row.event, { recipient: value as Recipient })} />
              <fieldset disabled={!canEdit || busy} aria-label={'Каналы: ' + row.label} className="flex min-h-10 flex-wrap items-center gap-x-4 gap-y-2">{channels.map(({ key, label }) => <label key={key} className="inline-flex items-center gap-2 text-sm"><input type="checkbox" checked={row.channels[key]} onChange={(e) => edit(row.event, { channels: { ...row.channels, [key]: e.target.checked } })} className="size-4 cursor-pointer accent-(--color-action)" />{label}</label>)}</fieldset>
              <Select label="Когда" value={row.timing} disabled={!canEdit || busy} options={[{ value: 'immediate', label: 'Сразу' }, { value: 'digest', label: 'Сводкой', disabled: !digestAvailable }]} onValueChange={(value) => edit(row.event, { timing: value as Row['timing'] })} />
            </li>
          ); })}
        </ul>
        <div className="mt-5 flex flex-wrap items-center justify-end gap-3"><span role="status" className="text-sm">{notice}</span><Button disabled={!canEdit || changes.length === 0} loading={busy} onClick={save}>Сохранить изменения</Button></div>
      </section>
    </CabinetLayout>
  );
}

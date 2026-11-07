import { Head, Link, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import CabinetLayout from '@/layouts/CabinetLayout';

type Hook = {
  id: string;
  events: string[];
  mappings?: Record<string, string>;
  deliveries: {
    id: string;
    event: string;
    status: string;
    status_code: number | null;
    created_at: string;
  }[];
};
const labels: Record<string, string> = {
  'seller.contact.captured': 'Контакт получен',
  'seller.conversation.handed_off': 'Диалог передан менеджеру',
  'webhook.test': 'Проверка',
};
const statuses: Record<string, string> = {
  pending: 'В очереди',
  sending: 'Отправляется',
  sent: 'Отправлено',
  failed: 'Ошибка',
  simulated: 'Тестовая проверка',
  unknown: 'Неизвестно',
};

function MappingForm({ hook }: { hook: Hook }) {
  const [fields, setFields] = useState({ name: hook.mappings?.name ?? 'name', phone: hook.mappings?.phone ?? 'phone', email: hook.mappings?.email ?? 'email' });
  const [pending, setPending] = useState(false);
  const [message, setMessage] = useState('');
  const save = (event: FormEvent) => {
    event.preventDefault();
    setMessage('');
    router.post(`/seller/integrations/crm/${hook.id}/mappings`, { fields }, {
      preserveScroll: true, onStart: () => setPending(true), onFinish: () => setPending(false),
      onSuccess: () => setMessage('Сопоставление сохранено.'),
      onError: (errors) => setMessage(Object.values(errors)[0] ?? 'Не удалось сохранить сопоставление.'),
      onNetworkError: () => { setMessage('Связь прервана. Повторите попытку.'); return false; },
    });
  };
  return <form onSubmit={save} className="mt-4 space-y-3"><h4 className="font-semibold">Сопоставление полей</h4>
    {([['name', 'Имя'], ['phone', 'Телефон'], ['email', 'Почта']] as const).map(([key, label]) => <label key={key} className="flex flex-wrap items-center gap-2">Поле EYON: {label} → Поле CRM<input required maxLength={64} value={fields[key]} onChange={(event) => setFields({ ...fields, [key]: event.target.value })} className="min-h-10 rounded-lg border border-(--color-border) bg-(--color-surface) px-3" /></label>)}
    <button disabled={pending} className="min-h-11 cursor-pointer rounded-lg border border-(--color-border) px-4 font-semibold disabled:opacity-50">{pending ? 'Сохранение…' : 'Сохранить сопоставление'}</button>{message && <p role="status">{message}</p>}
  </form>;
}

function WebhookForm() {
  const [url, setUrl] = useState('');
  const [secret, setSecret] = useState('');
  const [events, setEvents] = useState<string[]>([]);
  const [pending, setPending] = useState(false);
  const [message, setMessage] = useState('');
  const submit = (event: FormEvent) => {
    event.preventDefault(); setMessage('');
    router.post('/integrations/api/webhook-settings', { url, secret, events }, {
      onStart: () => setPending(true), onFinish: () => setPending(false),
      onError: (errors) => setMessage(Object.values(errors)[0] ?? 'Не удалось сохранить вебхук.'),
      onNetworkError: () => { setMessage('Связь прервана. Проверьте результат перед повтором.'); return false; },
    });
  };
  return <form onSubmit={submit} className="mt-5 space-y-4" autoComplete="off">
    <label className="block text-sm font-semibold">URL вебхука<input type="url" required value={url} onChange={(event) => setUrl(event.target.value)} placeholder="https://crm.example.ru/webhook" className="mt-1 block min-h-11 w-full rounded-lg border border-(--color-border) bg-(--color-surface) px-3" /></label>
    <label className="block text-sm font-semibold">Секрет (64 шестнадцатеричных символа)<input type="password" required pattern="[a-f0-9]{64}" autoComplete="new-password" value={secret} onChange={(event) => setSecret(event.target.value)} className="mt-1 block min-h-11 w-full rounded-lg border border-(--color-border) bg-(--color-surface) px-3" /></label>
    <fieldset><legend className="font-semibold">События</legend>{(['seller.contact.captured', 'seller.conversation.handed_off'] as const).map((item) => <label key={item} className="mt-2 flex cursor-pointer items-center gap-2"><input type="checkbox" checked={events.includes(item)} onChange={(event) => setEvents(event.target.checked ? [...events, item] : events.filter((value) => value !== item))} />{labels[item]}</label>)}</fieldset>
    <button disabled={pending || events.length === 0 || !window.isSecureContext} className="min-h-11 cursor-pointer rounded-lg bg-(--color-link) px-4 text-sm font-semibold text-white disabled:opacity-50">{pending ? 'Сохранение…' : 'Сохранить вебхук'}</button>
    {!window.isSecureContext && <p role="status">Для настройки вебхука откройте страницу через HTTPS или localhost.</p>}{message && <p role="alert">{message}</p>}
  </form>;
}

export default function Index({ canManage, webhooks, status }: { canManage: boolean; webhooks: Hook[]; status?: string | null }) {
  const [sending, setSending] = useState('');
  const [error, setError] = useState('');
  const sendTest = (id: string, fail: boolean) => {
    setError('');
    setSending(id);
    router.post(`/seller/integrations/crm/${id}/test`, { simulate_failure: fail }, {
      onFinish: () => setSending(''),
      onError: (errors) => setError(Object.values(errors)[0] ?? 'Не удалось отправить тестовое событие.'),
      onNetworkError: () => { setError('Связь прервалась. Проверьте журнал перед повтором.'); return false; },
    });
  };
  return (
    <CabinetLayout
      breadcrumbs={[
        { label: 'Главная', href: '/dashboard' },
        { label: 'Интеграции', href: '/seller/integrations/planned' },
        { label: 'CRM-вебхук' },
      ]}
    >
      <Head title="CRM-вебхук" />
      <div className="mx-auto max-w-5xl">
        <Link
          href="/seller/integrations/planned"
          className="inline-flex min-h-11 cursor-pointer items-center text-sm font-semibold text-(--color-link)"
        >
          ← К интеграциям
        </Link>
        <h1 className="mt-3 text-3xl font-bold">CRM-вебхук</h1>
        <p className="mt-3 text-sm text-(--color-ink-muted)">
          Передавайте сведения о контакте и передаче диалога в свою CRM.
        </p>
        {status && <p role="status" className="mt-4 rounded-lg bg-(--color-success-soft) p-3 text-sm">{status}</p>}
        {error && <p role="alert" className="mt-4 rounded-lg bg-(--color-danger-soft) p-3 text-sm text-(--color-danger-ink)">{error}</p>}
        <section
          className="mt-6 rounded-2xl border border-(--color-border) bg-(--color-surface) p-6 shadow-(--shadow-card)"
          aria-labelledby="crm-status"
        >
          <h2 id="crm-status" className="text-xl font-bold">
            Настройка вебхука
          </h2>
          <p className="mt-3 text-sm">
            Укажите адрес и секрет, затем выберите события CRM. Сейчас доступна только тестовая доставка без сети.
          </p>
          {canManage && <WebhookForm />}
          <h3 className="mt-6 font-semibold">События</h3>
          <ul className="mt-2 list-inside list-disc space-y-1 text-sm">
            <li>Контакт получен</li>
            <li>Диалог передан менеджеру</li>
          </ul>
          {canManage && (
            <Link
              href="/integrations/api"
              className="mt-5 inline-flex min-h-11 cursor-pointer items-center rounded-lg border border-(--color-border) px-4 text-sm font-semibold text-(--color-link)"
            >
              Открыть настройки вебхуков
            </Link>
          )}
          {canManage && (
            <div className="mt-6">
              <h3 className="font-semibold">Вебхуки CRM и журнал</h3>
              {webhooks.length === 0 ? (
                <p className="mt-2 text-sm">Вебхуки с событиями CRM ещё не настроены.</p>
              ) : (
                webhooks.map((hook, index) => (
                  <div
                    key={hook.id}
                    className="mt-3 rounded-lg border border-(--color-border) p-4 text-sm"
                  >
                    <p className="font-semibold">Вебхук {index + 1}</p>
                    <p className="mt-2">
                      {hook.events
                        .filter((event) => labels[event])
                        .map((event) => labels[event])
                        .join(', ')}
                    </p>
                    <div className="mt-3 flex flex-wrap gap-2">
                      <button type="button" disabled={!!sending} onClick={() => sendTest(hook.id, false)} className="min-h-11 cursor-pointer rounded-lg border border-(--color-border) px-4 text-sm font-semibold text-(--color-link) disabled:cursor-not-allowed disabled:opacity-50">Отправить тестовое событие</button>
                    </div>
                    <MappingForm hook={hook} />
                    {hook.deliveries.length === 0 ? (
                      <p className="mt-2">Записей в журнале пока нет.</p>
                    ) : (
                      <ul className="mt-2 space-y-1">
                        {hook.deliveries.map((delivery) => (
                          <li key={delivery.id}>
                            {labels[delivery.event] ?? 'Другое событие'}:{' '}
                            {statuses[delivery.status] ?? 'Неизвестно'}
                            {delivery.status_code ? ` (${delivery.status_code})` : ''}
                          </li>
                        ))}
                      </ul>
                    )}
                  </div>
                ))
              )}
            </div>
          )}
          <p className="mt-5 text-sm text-(--color-ink-muted)">
            amoCRM — Скоро · Битрикс24 — Скоро
          </p>
        </section>
      </div>
    </CabinetLayout>
  );
}

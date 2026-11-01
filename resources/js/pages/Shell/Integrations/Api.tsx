import { useState, type FormEvent } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft, KeyRound, Plus, Webhook } from 'lucide-react';
import { Button, Checkbox, Input, Modal } from '@/components/ui';
import { Page, panel, focus, Errors } from '@/features/Shell/Organizations/Shared';

type ApiKey = {
  id: string;
  name: string;
  mask: string;
  abilities: string[];
  created_at: string;
  last_used_at: string | null;
};
type Delivery = {
  id: string;
  event: string;
  status: string;
  status_code: number | null;
  created_at: string;
};
type Hook = { id: string; events: string[]; secret: string; deliveries: Delivery[] };
type Props = {
  keys: ApiKey[];
  webhooks: Hook[];
  webhookEvents: { value: string; label: string }[];
  permissions: { manage: boolean };
  apiBrandReadConfigured: boolean;
  apiKeyRuntimeAvailable: boolean;
  webhookDeliveryAvailable: boolean;
  secureSecretTransport: boolean;
  secretStorageReady: boolean;
  integration_api_key_available?: boolean;
  integration_webhook_secret_available?: boolean;
};
const abilities = [
  { value: 'read', label: 'Чтение' },
  { value: 'turn', label: 'Ход диалога' },
  { value: 'leads', label: 'Лиды' },
];
const date = (value: string) => new Date(value).toLocaleString('ru-RU');
const deliveryLabels: Record<string, string> = {
  pending: 'В очереди',
  sending: 'Отправляется',
  sent: 'Доставлено',
  failed: 'Ошибка',
};
export default function Api({
  keys,
  webhooks,
  webhookEvents,
  permissions,
  apiBrandReadConfigured,
  apiKeyRuntimeAvailable,
  webhookDeliveryAvailable,
  secureSecretTransport,
  secretStorageReady,
  integration_api_key_available,
  integration_webhook_secret_available,
}: Props) {
  const [showCreate, setShowCreate] = useState(false);
  const [token, setToken] = useState<string | null>(null);
  const [hookSecret, setHookSecret] = useState<string | null>(null);
  const [consumedKey, setConsumedKey] = useState(false);
  const [consumedHook, setConsumedHook] = useState(false);
  const [revealing, setRevealing] = useState(false);
  const [error, setError] = useState('');
  const [pending, setPending] = useState<string | null>(null);
  const [notice, setNotice] = useState('');
  const [copyMessage, setCopyMessage] = useState('');
  const copySecret = async (value: string) => {
    try {
      await navigator.clipboard.writeText(value);
      setCopyMessage('Скопировано');
    } catch {
      setCopyMessage('Не удалось скопировать. Выделите и скопируйте значение вручную.');
    }
  };
  const form = useForm({ name: '', abilities: [] as string[] });
  const reveal = async (type: 'api_key' | 'webhook_secret') => {
    setError('');
    setRevealing(true);
    try {
      const cookie = document.cookie.split('; ').find((part) => part.startsWith('XSRF-TOKEN='));
      if (!cookie) throw new Error('missing csrf');
      const response = await fetch('/integrations/api/secrets', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-XSRF-TOKEN': decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)),
        },
        body: JSON.stringify({ type }),
      });
      if (!response.ok) {
        if (response.status === 404 && type === 'api_key') setConsumedKey(true);
        throw new Error('secret unavailable');
      }
      const result = (await response.json()) as { value?: unknown };
      if (typeof result.value !== 'string' || !result.value) throw new Error('secret unavailable');
      if (type === 'api_key') {
        setConsumedKey(true);
        setToken(result.value);
      } else {
        setConsumedHook(true);
        setHookSecret(result.value);
      }
    } catch {
      setError(type === 'api_key'
        ? 'Не удалось получить ключ. Возможно, он отозван или время показа истекло; создайте новый.'
        : 'Не удалось получить секрет. Возможно, время показа истекло; создайте новый.');
    } finally {
      setRevealing(false);
    }
  };
  const fail = () => {
    setError('Не удалось связаться с сервером. Попробуйте ещё раз.');
    return false;
  };
  const create = (event: FormEvent) => {
    event.preventDefault();
    setError('');
    form.post('/integrations/api/keys', {
      preserveScroll: true,
      onSuccess: () => {
        setConsumedKey(false);
        setShowCreate(false);
        form.reset();
      },
      onNetworkError: fail,
      onHttpException: fail,
    });
  };
  const revoke = (id: string) => {
    setError('');
    router.delete('/integrations/api/keys/' + encodeURIComponent(id), {
      preserveScroll: true,
      onStart: () => setPending(id),
      onFinish: () => setPending(null),
      onError: (errors) => setError(Object.values(errors).join(' ')),
      onNetworkError: fail,
      onHttpException: fail,
    });
  };
  const testHook = (id: string) => {
    setError('');
    setNotice('');
    router.post(
      '/integrations/api/webhooks/' + encodeURIComponent(id) + '/test',
      {},
      {
        preserveScroll: true,
        onStart: () => setPending(id),
        onFinish: () => setPending(null),
        onSuccess: () =>
          setNotice('Тестовая доставка поставлена в очередь. Проверьте её статус в истории.'),
        onError: (errors) => setError(Object.values(errors).join(' ')),
        onNetworkError: fail,
        onHttpException: fail,
      },
    );
  };
  return (
    <>
      <Head title="API-ключи и вебхуки" />
      <Page title="API-ключи и вебхуки" subtitle="Управляйте доступом и событиями интеграций">
        <Link
          href="/integrations"
          className={
            'mb-5 inline-flex min-h-10 cursor-pointer items-center gap-2 text-sm font-semibold text-(--color-link) ' +
            focus
          }
        >
          <ArrowLeft size={17} aria-hidden="true" /> Подключения
        </Link>
        <Errors errors={{}} transport={error} />
        {notice && (
          <p role="status" className="mb-4 text-sm">
            {notice}
          </p>
        )}
        {!secureSecretTransport && (
          <p
            role="status"
            className="mb-5 rounded-xl bg-(--color-warning-soft) p-4 text-sm text-(--color-warning-ink)"
          >
            Для создания и просмотра секретов откройте кабинет по HTTPS или через localhost на этом
            компьютере.
          </p>
        )}
        {!secretStorageReady && (
          <p
            role="status"
            className="mb-5 rounded-xl bg-(--color-warning-soft) p-4 text-sm text-(--color-warning-ink)"
          >
            Показ секретов временно недоступен: требуется безопасное хранилище сессий и блокировок.
          </p>
        )}
        {apiBrandReadConfigured ? (
          <p role="status" className="mb-5 rounded-xl bg-(--color-surface-muted) p-4 text-sm">
            Маршрут чтения текущего бренда настроен: <code>GET /api/v1/brand</code>. Нужен
            действующий ключ с правом «Чтение» и защищённое соединение. Доступность сервера и
            права ключа проверяются при каждом запросе.
          </p>
        ) : (
          <p role="status" className="mb-5 rounded-xl bg-(--color-warning-soft) p-4 text-sm text-(--color-warning-ink)">
            Чтение бренда через API сейчас не подтверждено или недоступно.
          </p>
        )}
        {!apiKeyRuntimeAvailable && (
          <p
            role="status"
            className="mb-5 rounded-xl bg-(--color-warning-soft) p-4 text-sm text-(--color-warning-ink)"
          >
            Запросы API для работы с диалогами и лидами пока недоступны.
          </p>
        )}
        {integration_api_key_available && !consumedKey && (
          <p className="mb-4 text-sm">
            Создан новый ключ. Покажите его или отзовите перед созданием следующего.{' '}
            <Button
              variant="secondary"
              disabled={revealing || !secureSecretTransport || !secretStorageReady}
              onClick={() => void reveal('api_key')}
            >
              Показать ключ один раз
            </Button>
          </p>
        )}
        {integration_webhook_secret_available && !consumedHook && (
          <p className="mb-4 text-sm">
            Создан новый вебхук.{' '}
            <Button
              variant="secondary"
              disabled={revealing || !secureSecretTransport || !secretStorageReady}
              onClick={() => void reveal('webhook_secret')}
            >
              Показать секрет один раз
            </Button>
          </p>
        )}
        <section className={panel + ' mb-5'} aria-labelledby="keys-heading">
          <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
            <h2 id="keys-heading" className="flex items-center gap-2 text-xl font-bold">
              <KeyRound size={22} aria-hidden="true" /> API-ключи
            </h2>
            <Button
              disabled={!permissions.manage || !secureSecretTransport || !secretStorageReady || (integration_api_key_available && !consumedKey)}
              onClick={() => setShowCreate(true)}
            >
              <Plus size={17} aria-hidden="true" /> Создать ключ
            </Button>
          </div>
          {keys.length === 0 ? (
            <p className="text-sm text-(--color-ink-muted)">Ключей пока нет</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full min-w-[660px] text-left text-sm">
                <thead className="bg-(--color-surface-muted) text-(--color-ink-muted)">
                  <tr>
                    <th className="p-3">Название</th>
                    <th className="p-3">Ключ</th>
                    <th className="p-3">Права</th>
                    <th className="p-3">Создан</th>
                    <th className="p-3">Последний вызов</th>
                    <th className="p-3">Действие</th>
                  </tr>
                </thead>
                <tbody>
                  {keys.map((key) => (
                    <tr key={key.id} className="border-t border-(--color-border)">
                      <td className="p-3">{key.name}</td>
                      <td className="p-3">
                        <code>{key.mask}</code>
                      </td>
                      <td className="p-3">
                        {key.abilities
                          .map((item) => abilities.find((a) => a.value === item)?.label ?? item)
                          .join(' · ')}
                      </td>
                      <td className="p-3">{date(key.created_at)}</td>
                      <td className="p-3">{key.last_used_at ? date(key.last_used_at) : '—'}</td>
                      <td className="p-3">
                        <Button
                          variant="ghost"
                          disabled={!permissions.manage || pending !== null}
                          onClick={() => revoke(key.id)}
                        >
                          Отозвать
                        </Button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </section>
        <section className={panel} aria-labelledby="hooks-heading">
          <h2 id="hooks-heading" className="flex items-center gap-2 text-xl font-bold">
            <Webhook size={22} aria-hidden="true" /> Вебхуки
          </h2>
          <p className="mt-1 text-sm text-(--color-ink-muted)">Получайте события в вашей системе</p>
          {!webhookDeliveryAvailable && (
            <p className="mt-4 text-sm text-(--color-warning-ink)">
              Настройка и отправка вебхуков временно недоступны. Адреса ранее созданных вебхуков скрыты.
            </p>
          )}{' '}
          {webhooks.length === 0 && (
            <p className="mt-5 text-sm text-(--color-ink-muted)">Вебхуков пока нет</p>
          )}
          {webhooks.map((hook) => (
            <div key={hook.id} className="mt-5 border-b border-(--color-border) pb-5">
              <div className="flex flex-wrap items-end justify-between gap-3">
                <div className="min-w-0">
                  <p className="text-sm font-semibold">Адрес эндпоинта</p>
                  <p className="mt-1 text-sm">Адрес скрыт</p>
                  <p className="mt-3 text-sm font-semibold">Секретный токен</p>
                  <input
                    aria-label="Секретный токен вебхука"
                    type="password"
                    readOnly
                    value={hook.secret}
                    className="mt-1 w-full max-w-md rounded-lg border border-(--color-control-border) bg-(--color-surface) p-2 text-sm"
                  />
                </div>
                <Button
                  variant="secondary"
                  disabled={!permissions.manage || !webhookDeliveryAvailable || pending !== null}
                  onClick={() => testHook(hook.id)}
                >
                  Отправить тест
                </Button>
              </div>
              <p className="mt-4 text-sm">
                События:{' '}
                {hook.events
                  .map((event) => webhookEvents.find((e) => e.value === event)?.label ?? 'Недоступное событие')
                  .join(', ') || '—'}
              </p>
              <h3 className="mt-5 font-semibold">Последние 10 доставок</h3>
              {hook.deliveries.length === 0 ? (
                <p className="mt-2 text-sm text-(--color-ink-muted)">Доставок пока нет</p>
              ) : (
                <ul className="mt-2 divide-y divide-(--color-border)">
                  {hook.deliveries.map((delivery) => (
                    <li
                      key={delivery.id}
                      className="flex flex-wrap justify-between gap-2 py-2 text-sm"
                    >
                      <span>
                        {date(delivery.created_at)} ·{' '}
                        {delivery.event === 'webhook.test'
                          ? 'Тестовая отправка'
                          : (webhookEvents.find((e) => e.value === delivery.event)?.label ??
                            'Недоступное событие')}
                      </span>
                      <span>
                        {deliveryLabels[delivery.status] ?? 'Недоступен'}
                        {delivery.status_code ? ` (${delivery.status_code})` : ''}
                      </span>
                    </li>
                  ))}
                </ul>
              )}
            </div>
          ))}
        </section>
      </Page>
      <Modal open={showCreate} title="Создать API-ключ" onClose={() => setShowCreate(false)}>
        <form onSubmit={create} className="space-y-4">
          <Errors errors={form.errors} transport={error} />
          <Input
            label="Название ключа"
            required
            maxLength={160}
            value={form.data.name}
            onChange={(e) => form.setData('name', e.target.value)}
          />
          <fieldset>
            <legend className="mb-2 font-semibold">Права</legend>
            <div className="flex flex-col gap-3">
              {abilities.map((ability) => (
                <Checkbox
                  key={ability.value}
                  label={ability.label}
                  checked={form.data.abilities.includes(ability.value)}
                  onChange={(e) =>
                    form.setData(
                      'abilities',
                      e.target.checked
                        ? [...form.data.abilities, ability.value]
                        : form.data.abilities.filter((value) => value !== ability.value),
                    )
                  }
                />
              ))}
            </div>
          </fieldset>
          <div className="flex flex-wrap justify-end gap-2">
            <Button variant="secondary" onClick={() => setShowCreate(false)}>
              Отмена
            </Button>
            <Button type="submit" loading={form.processing}>
              Создать
            </Button>
          </div>
        </form>
      </Modal>
      <Modal
        open={hookSecret !== null}
        title="Сохраните секрет вебхука"
        onClose={() => setHookSecret(null)}
      >
        <p>Секрет показывается только один раз. Сохраните его в безопасном месте.</p>
        <p
          className="mt-4 break-all rounded-lg bg-(--color-surface-muted) p-3 font-mono"
          aria-label="Секрет вебхука"
        >
          {hookSecret}
        </p>
        <p role="status" className="mt-2 text-sm">
          {copyMessage}
        </p>
        <div className="mt-4 flex justify-end gap-2">
          <Button variant="secondary" onClick={() => hookSecret && void copySecret(hookSecret)}>
            Копировать
          </Button>
          <Button
            onClick={() => {
              setHookSecret(null);
              setCopyMessage('');
            }}
          >
            Готово
          </Button>
        </div>
      </Modal>
      <Modal open={token !== null} title="Сохраните ключ" onClose={() => setToken(null)}>
        <p>Ключ показывается только один раз. Сохраните его в безопасном месте.</p>
        <p
          className="mt-4 break-all rounded-lg bg-(--color-surface-muted) p-3 font-mono"
          aria-label="Созданный API-ключ"
        >
          {token}
        </p>
        <p role="status" className="mt-2 text-sm">
          {copyMessage}
        </p>
        <div className="mt-4 flex justify-end gap-2">
          <Button variant="secondary" onClick={() => token && void copySecret(token)}>
            Копировать
          </Button>
          <Button
            onClick={() => {
              setToken(null);
              setCopyMessage('');
            }}
          >
            Готово
          </Button>
        </div>
      </Modal>
    </>
  );
}

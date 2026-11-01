import { Head, Link } from '@inertiajs/react';
import CabinetLayout from '@/layouts/CabinetLayout';

type Hook = {
  id: string;
  events: string[];
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

export default function Index({ canManage, webhooks }: { canManage: boolean; webhooks: Hook[] }) {
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
        <section
          className="mt-6 rounded-2xl border border-(--color-border) bg-(--color-surface) p-6 shadow-(--shadow-card)"
          aria-labelledby="crm-status"
        >
          <h2 id="crm-status" className="text-xl font-bold">
            Настройка вебхука
          </h2>
          <p className="mt-3 text-sm">
            Создайте вебхук в общих настройках и выберите события CRM. Автоматическая доставка пока
            недоступна.
          </p>
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
            Подключения amoCRM и Битрикс24 появятся позднее.
          </p>
        </section>
      </div>
    </CabinetLayout>
  );
}

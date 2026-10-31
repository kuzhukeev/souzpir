import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { Badge, Button, DataTable, Skeleton } from '@/components/ui';
import CabinetLayout from '@/layouts/CabinetLayout';
import { formatDate, type Filters, type Page } from '@/features/Shell/Journal/model';
import { useJournalNavigation } from '@/features/Shell/Journal/navigation';
import { FiltersForm, JournalHeader, Pages } from '@/features/Shell/Journal/shared';

type Row = {
  id: string;
  created_at: string;
  channel: 'cabinet' | 'email' | 'telegram';
  status: 'pending' | 'sending' | 'sent' | 'failed' | 'uncertain';
  recipient: string | null;
  title: string;
};
type Props = { rows: Page<Row>; filters: Filters };
const channels = { cabinet: 'В кабинете', email: 'Email', telegram: 'Telegram' };
const statuses = {
  pending: 'В очереди',
  sending: 'Отправляется',
  sent: 'Доставлено',
  failed: 'Ошибка',
  uncertain: 'Требует проверки',
};
export default function Notifications({ rows, filters }: Props) {
  const { loading, failed, retry: retryLoad } = useJournalNavigation();
  const [retrying, setRetrying] = useState<string | null>(null);
  const [notice, setNotice] = useState('');
  const retry = (id: string) => {
    setNotice('');
    router.post(
      '/journal/notifications/' + encodeURIComponent(id) + '/retry',
      {},
      {
        preserveScroll: true,
        onStart: () => setRetrying(id),
        onFinish: () => setRetrying(null),
        onSuccess: () => setNotice('Уведомление поставлено в очередь повторной доставки.'),
        onError: () =>
          setNotice('Не удалось повторить доставку. Обновите страницу и попробуйте ещё раз.'),
        onHttpException: () => {
          setNotice('Не удалось повторить доставку. Обновите страницу и попробуйте ещё раз.');
          return false;
        },
        onNetworkError: () => {
          setNotice('Не удалось связаться с сервером. Повторите попытку.');
          return false;
        },
      },
    );
  };
  return (
    <CabinetLayout
      breadcrumbs={[
        { label: 'Главная' },
        { label: 'Журнал', href: '/journal' },
        { label: 'Уведомления' },
      ]}
    >
      <Head title="История уведомлений" />
      <JournalHeader
        title="История уведомлений"
        subtitle="Отправка уведомлений и их статус"
        tab="notifications"
      />
      <FiltersForm
        key={JSON.stringify(filters)}
        path="/journal/notifications"
        initial={filters}
        options={[
          {
            key: 'channel',
            label: 'Канал',
            items: Object.entries(channels).map(([value, label]) => ({ value, label })),
          },
          {
            key: 'status',
            label: 'Статус',
            items: Object.entries(statuses).map(([value, label]) => ({ value, label })),
          },
        ]}
      />
      <section
        aria-label="Список уведомлений"
        aria-busy={loading}
        className="rounded-2xl bg-(--color-surface) p-5 shadow-(--shadow-card)"
      >
        {notice && (
          <p role="status" className="mb-4">
            {notice}
          </p>
        )}
        {failed && (
          <div role="alert" className="mb-4 rounded-lg bg-(--color-surface-muted) p-4">
            Не удалось загрузить данные.{' '}
            <Button variant="ghost" size="sm" onClick={retryLoad}>
              Попробовать снова
            </Button>
          </div>
        )}
        {loading ? (
          <div className="space-y-3">
            <Skeleton label="Загружается история уведомлений" />
            <Skeleton label="Загрузка" />
            <Skeleton label="Загрузка" />
          </div>
        ) : (
          <>
            <DataTable<Row>
              label="История уведомлений"
              rows={rows.data}
              empty="Уведомлений пока нет"
              columns={[
                {
                  key: 'created_at',
                  label: 'Дата и время',
                  render: (row) => formatDate(row.created_at),
                },
                { key: 'title', label: 'Событие' },
                { key: 'channel', label: 'Канал', render: (row) => channels[row.channel] },
                { key: 'recipient', label: 'Получатель', render: (row) => row.recipient || '—' },
                {
                  key: 'status',
                  label: 'Статус',
                  render: (row) => (
                    <Badge
                      tone={
                        row.status === 'sent'
                          ? 'success'
                          : row.status === 'failed'
                            ? 'danger'
                            : row.status === 'uncertain'
                              ? 'warning'
                              : 'info'
                      }
                    >
                      {statuses[row.status]}
                    </Badge>
                  ),
                },
                {
                  key: 'id',
                  label: 'Действие',
                  render: (row) =>
                    row.status === 'failed' && row.channel !== 'cabinet' ? (
                      <Button
                        variant="ghost"
                        size="sm"
                        disabled={retrying !== null}
                        onClick={() => retry(row.id)}
                      >
                        Повторить
                      </Button>
                    ) : row.status === 'uncertain' ? (
                      'Нужна проверка'
                    ) : (
                      '—'
                    ),
                },
              ]}
            />
            <Pages rows={rows} filters={filters} path="/journal/notifications" />
          </>
        )}
      </section>
    </CabinetLayout>
  );
}

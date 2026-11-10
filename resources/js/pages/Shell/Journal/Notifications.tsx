import { Head, router } from '@inertiajs/react';
import { AlertCircle } from 'lucide-react';
import { useState } from 'react';
import { Badge, Button, DataTable, Drawer, Skeleton } from '@/components/ui';
import CabinetLayout from '@/layouts/CabinetLayout';
import { formatDate, type Filters, type Page } from '@/features/Shell/Journal/model';
import { useJournalNavigation } from '@/features/Shell/Journal/navigation';
import { FiltersForm, JournalHeader, Pages } from '@/features/Shell/Journal/shared';
import JournalRowMenu from '@/features/Shell/Journal/JournalRowMenu';

type Row = {
  id: string;
  created_at: string;
  channel: 'cabinet' | 'email' | 'telegram';
  status: 'pending' | 'deferred' | 'sending' | 'sent' | 'failed' | 'uncertain';
  recipient: string | null;
  title: string;
  attempts: number;
};
type Props = { rows: Page<Row>; filters: Filters };
const channels = { cabinet: 'В кабинете', email: 'Email', telegram: 'Telegram' };
const statuses = {
  pending: 'В очереди',
  deferred: 'Отложено',
  sending: 'Отправляется',
  sent: 'Доставлено',
  failed: 'Ошибка',
  uncertain: 'Требует проверки',
};
export default function Notifications({ rows, filters }: Props) {
  const { loading, failed, retry: retryLoad } = useJournalNavigation();
  const [retrying, setRetrying] = useState<string | null>(null);
  const [notice, setNotice] = useState('');
  const [selected, setSelected] = useState<Row | null>(null);
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
        aria-busy={loading && retrying === null}
        className="rounded-2xl bg-(--color-surface) p-5 shadow-(--shadow-card)"
      >
        {notice && (
          <p role="status" className="mb-4">
            {notice}
          </p>
        )}
        {failed && (
          <div className="mb-4">
            <h2 className="text-xl font-bold">Ошибка</h2>
            <div
              role="alert"
              className="mt-4 flex gap-3 rounded-xl border border-(--color-danger) bg-(--color-danger-soft) p-4"
            >
              <AlertCircle aria-hidden className="size-6 shrink-0 text-(--color-danger-ink)" />
              <div>
                <p className="font-semibold text-(--color-danger-ink)">
                  Не удалось загрузить историю уведомлений
                </p>
                <p className="mt-1 text-sm text-(--color-ink-muted)">Код: ERR_LOAD</p>
              </div>
            </div>
            <Button className="mt-4" onClick={retryLoad}>
              Повторить
            </Button>
          </div>
        )}
        {loading && retrying === null ? (
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
                            : row.status === 'uncertain' || row.status === 'deferred'
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
                  render: (row) => (
                    <span className="flex items-center justify-end gap-2">
                      {row.status === 'failed' && row.channel !== 'cabinet' ? (
                        <Button
                          variant="ghost"
                          size="sm"
                          disabled={retrying !== null}
                          loading={retrying === row.id}
                          onClick={() => retry(row.id)}
                        >
                          {retrying === row.id ? 'Повторяем…' : 'Повторить'}
                        </Button>
                      ) : row.status === 'uncertain' ? (
                        'Нужна проверка'
                      ) : (
                        '—'
                      )}
                      <JournalRowMenu
                        url={null}
                        detailsLabel="Детали уведомления"
                        onDetails={() => setSelected(row)}
                      />
                    </span>
                  ),
                },
              ]}
            />
            <Pages rows={rows} filters={filters} path="/journal/notifications" />
          </>
        )}
      </section>
      <Drawer open={selected !== null} title="Детали уведомления" onClose={() => setSelected(null)}>
        {selected && (
          <dl className="space-y-4">
            <div>
              <dt className="text-(--color-ink-muted)">Время</dt>
              <dd>{formatDate(selected.created_at)}</dd>
            </div>
            <div>
              <dt className="text-(--color-ink-muted)">Событие</dt>
              <dd>{selected.title}</dd>
            </div>
            <div>
              <dt className="text-(--color-ink-muted)">Получатель</dt>
              <dd>{selected.recipient || '—'}</dd>
            </div>
            <div>
              <dt className="text-(--color-ink-muted)">Канал</dt>
              <dd>{channels[selected.channel]}</dd>
            </div>
            <div>
              <dt className="text-(--color-ink-muted)">Статус</dt>
              <dd>{statuses[selected.status]}</dd>
            </div>
            <div>
              <dt className="text-(--color-ink-muted)">Попыток доставки</dt>
              <dd>{selected.attempts}</dd>
            </div>
          </dl>
        )}
      </Drawer>
    </CabinetLayout>
  );
}

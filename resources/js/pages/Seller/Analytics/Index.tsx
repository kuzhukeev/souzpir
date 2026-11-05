import WorkloadChart from './WorkloadChart';
import { useState } from 'react';
import Details from './Details';
import Channels from './Channels';
import { Head, useForm } from '@inertiajs/react';
import CabinetLayout from '@/layouts/CabinetLayout';
type Analytics = {
  funnel?: { label: string; count: number }[];
  manager?: string;
  managerOptions?: { id: string; name: string }[];
  outcomes?: Record<string, number>;
  previousTotal?: number;
  previousMetrics?: { contact: number; handed: number; closed: number };
  transitionRates?: Record<string, number | null>;
  from: string;
  to: string;
  channel?: string;
  source?: string;
  channelOptions?: string[];
  channelMetrics?: {
    channel: string;
    dialogs: number;
    contacts: number;
    conversion: number | null;
    average_rating: number | null;
    planned: boolean;
  }[];
  sitePages?: {
    page: string;
    opens: number;
    dialogs: number;
    contacts: number;
    utm: string | null;
  }[];
  sources?: Record<string, number>;
  transitions?: Record<string, number>;
  total: number;
  contact: number;
  handed: number;
  closed: number;
  types: Record<string, number>;
  stages: Record<string, number>;
  channels: Record<string, number>;
  daily: Record<string, number>;
  topics: Record<string, number>;
  managers: {
    name: string;
    assigned: number;
    taken: number;
    closed: number;
    contact_closed?: number;
    reaction_minutes?: number | null;
    reviews?: number;
    samples?: number | null;
    score?: number | null;
    workload?: Record<string, number>;
  }[];
  canManage: boolean;
};
const labels: Record<string, string> = {
  unknown: 'Не определён',
  newbie: 'Начинающий',
  experienced: 'Опытный',
  for_self: 'Для себя',
  prices_only: 'Только цены',
  open: 'Открытие',
  qualify: 'Квалификация',
  need: 'Потребность',
  budget: 'Бюджет',
  close_contact: 'Завершение контакта',
  followup: 'Напоминание',
  handoff: 'Передача',
  spam: 'Спам',
  lost: 'Отказ',
  done: 'Завершено',
  telegram: 'Telegram',
  widget: 'Сайт',
  api: 'API',
  email: 'Почта',
  whatsapp: 'WhatsApp',
  delivery: 'Доставка',
  price: 'Цена',
  pricing: 'Цена',
  payment: 'Оплата',
  availability: 'Наличие',
  discount: 'Скидки',
  product: 'Товар',
  other: 'Другая тема',
  full_contact: 'Полный контакт',
  partial_contact: 'Частичный контакт',
  paused: 'Пауза',
  declined: 'Отказ',
  stale: 'Нет ответа',
};
const card = 'rounded-xl border border-(--color-border) bg-(--color-surface) p-5';
function Rows({
  values,
  empty = 'Данных пока нет.',
}: {
  values: Record<string, number>;
  empty?: string;
}) {
  const entries = Object.entries(values);
  return entries.length ? (
    <ul className="mt-3 space-y-2">
      {entries.map(([key, count]) => (
        <li
          key={key}
          className="flex justify-between gap-3 rounded-lg bg-(--color-surface-muted) p-3 text-sm"
        >
          <span>{labels[key] ?? key}</span>
          <strong>{count}</strong>
        </li>
      ))}
    </ul>
  ) : (
    <p className="mt-3 text-sm">{empty}</p>
  );
}
export default function Index({ analytics: a }: { analytics: Analytics }) {
  const [tab, setTab] = useState('funnel');
  const [compare, setCompare] = useState(true);
  const form = useForm({
    from: a.from,
    to: a.to,
    channel: a.channel ?? '',
    source: a.source ?? '',
    manager: a.manager ?? '',
  });
  const query = new URLSearchParams({
    from: a.from,
    to: a.to,
    channel: a.channel ?? '',
    source: a.source ?? '',
    manager: a.manager ?? '',
  }).toString();
  return (
    <CabinetLayout breadcrumbs={[{ label: 'Продавец', href: '/seller' }, { label: 'Аналитика' }]}>
      <Head title="Аналитика продавца" />
      <main className="mx-auto max-w-[1500px]" data-private-profile>
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h1 className="text-3xl font-bold">Аналитика продавца</h1>
            <p className="mt-2 text-sm text-(--color-ink-muted)">
              Диалоги текущего бренда за выбранный период
            </p>
          </div>
          <a
            href={`/seller/analytics/export.csv?${query}`}
            className="inline-flex min-h-11 cursor-pointer items-center rounded-lg border border-(--color-border) px-4 text-sm font-semibold text-(--color-link)"
          >
            Скачать CSV
          </a>
        </div>
        <nav aria-label="Разделы аналитики" className="mt-5 flex flex-wrap gap-2 text-sm">
          {[
            ['funnel', 'Воронка'],
            ['dialogs', 'Диалоги'],
            ['topics', 'Темы и вопросы'],
            ['channels', 'Источники и каналы'],
            ...(a.canManage ? [['managers', 'Менеджеры']] : []),
            ['report', 'Отчёты'],
          ].map(([key, label]) => (
            <button
              type="button"
              key={key}
              aria-pressed={tab === key}
              onClick={() => setTab(key)}
              className={`min-h-11 cursor-pointer rounded-lg border border-(--color-border) px-3 py-2 ${tab === key ? 'bg-(--color-action) text-white' : 'text-(--color-link)'}`}
            >
              {label}
            </button>
          ))}
        </nav>
        <form
          className="mt-5 flex flex-wrap items-end gap-3"
          onSubmit={(e) => {
            e.preventDefault();
            form.get('/seller/analytics', { preserveState: true });
          }}
        >
          <label className="text-sm">
            С
            <input
              required
              type="date"
              value={form.data.from}
              onChange={(e) => form.setData('from', e.target.value)}
              className="mt-1 block min-h-11 rounded-lg border border-(--color-border) bg-(--color-surface) px-3"
            />
          </label>
          <label className="text-sm">
            По
            <input
              required
              type="date"
              value={form.data.to}
              onChange={(e) => form.setData('to', e.target.value)}
              className="mt-1 block min-h-11 rounded-lg border border-(--color-border) bg-(--color-surface) px-3"
            />
          </label>
          <label className="text-sm">
            Канал
            <select
              value={form.data.channel}
              onChange={(e) => form.setData('channel', e.target.value)}
              className="mt-1 block min-h-11 cursor-pointer rounded-lg border border-(--color-border) bg-(--color-surface) px-3"
            >
              <option value="">Все каналы</option>
              {(a.channelOptions ?? []).map((channel) => (
                <option key={channel} value={channel}>
                  {labels[channel] ?? channel}
                </option>
              ))}
            </select>
          </label>
          <label className="text-sm">
            Источник
            <select
              value={form.data.source}
              onChange={(e) => form.setData('source', e.target.value)}
              className="mt-1 block min-h-11 cursor-pointer rounded-lg border border-(--color-border) bg-(--color-surface) px-3"
            >
              <option value="">Все источники</option>
              <option value="unknown">Неизвестен</option>
            </select>
          </label>
          {a.canManage && (
            <label className="text-sm">
              Менеджер
              <select
                value={form.data.manager}
                onChange={(e) => form.setData('manager', e.target.value)}
                className="mt-1 block min-h-11 cursor-pointer rounded-lg border border-(--color-border) bg-(--color-surface) px-3"
              >
                <option value="">Все менеджеры</option>
                {(a.managerOptions ?? []).map((manager) => (
                  <option key={manager.id} value={manager.id}>
                    {manager.name}
                  </option>
                ))}
              </select>
            </label>
          )}
          <button
            disabled={form.processing}
            className="min-h-11 cursor-pointer rounded-lg bg-(--color-link) px-4 text-sm font-semibold text-white disabled:opacity-50"
          >
            {form.processing ? 'Загружаем…' : 'Показать'}
          </button>
          {Object.values(form.errors).length > 0 && (
            <span role="alert" className="text-sm text-(--color-danger-ink)">
              {Object.values(form.errors)[0]}
            </span>
          )}
        </form>
        {a.total === 0 && (
          <p className="mt-5 rounded-lg bg-(--color-surface-muted) p-4 text-sm">
            За выбранный период диалогов нет.
          </p>
        )}
        <div hidden={tab !== 'funnel'}>
          <section className={`${card} mt-5`} aria-label="Воронка">
            <h2 className="text-xl font-bold">Воронка диалогов</h2>
            <p className="mt-2 text-sm text-(--color-ink-muted)">
              Каждый следующий шаг учитывает диалоги, прошедшие предыдущие. Потребность определяется
              сохранённым переходом или текущим этапом.
            </p>
            <ol className="mt-3 grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
              {(a.funnel ?? []).map((step, index) => (
                <li key={step.label} className="rounded-lg bg-(--color-surface-muted) p-3 text-sm">
                  <span>{step.label}</span>
                  <strong className="mt-1 block text-xl">{step.count}</strong>
                  {index > 0 && (
                    <span>
                      {a.funnel?.[index - 1]?.count
                        ? `${Math.round((step.count / a.funnel[index - 1].count) * 100)}% от предыдущего шага`
                        : 'Нет данных для расчёта'}
                    </span>
                  )}
                </li>
              ))}
            </ol>
          </section>
          <section id="funnel" className="mt-6 grid gap-3 md:grid-cols-4">
            {[
              ['Диалоги', a.total],
              ['С контактом', a.contact],
              ['Переданы менеджеру', a.handed],
              ['Завершены', a.closed],
            ].map(([name, count]) => (
              <div className={card} key={name}>
                <h2 className="text-sm font-semibold">{name}</h2>
                <p className="mt-2 text-2xl font-bold">{count}</p>
              </div>
            ))}
          </section>
          <section className={`${card} mt-4`}>
            <h2 className="text-xl font-bold">Переходы по стадиям</h2>
            <p className="mt-2 text-sm text-(--color-ink-muted)">
              Учитываются только сохранённые переходы за период для выбранных диалогов. Доля — от
              числа диалогов, а не от предыдущей стадии; история до начала записи отсутствует.
            </p>
            {Object.keys(a.transitions ?? {}).length ? (
              <ul className="mt-3 space-y-2">
                {Object.entries(a.transitions ?? {}).map(([stage, count]) => (
                  <li
                    key={stage}
                    className="flex justify-between gap-3 rounded-lg bg-(--color-surface-muted) p-3 text-sm"
                  >
                    <span>{labels[stage] ?? stage}</span>
                    <strong>
                      {count} · {a.total ? Math.round((count / a.total) * 100) : 0}%
                      {a.transitionRates?.[stage] != null && (
                        <span> · {a.transitionRates[stage]}% от предыдущего этапа</span>
                      )}
                    </strong>
                  </li>
                ))}
              </ul>
            ) : (
              <p className="mt-3 text-sm">Сохранённых переходов за период нет.</p>
            )}
          </section>
          <section className={`${card} mt-4`}>
            <h2 className="text-xl font-bold">Итоги диалогов</h2>
            <Rows values={a.outcomes ?? {}} />
          </section>
          <div className="mt-4 grid gap-4 lg:grid-cols-2">
            <section className={card}>
              <h2 className="text-xl font-bold">Типы клиентов</h2>
              <Rows values={a.types} />
            </section>
            <section className={card}>
              <h2 className="text-xl font-bold">Текущие стадии диалогов</h2>
              <p className="mt-2 text-sm text-(--color-ink-muted)">
                Текущее состояние, не история прохождения воронки.
              </p>
              <Rows values={a.stages} />
            </section>
            <section className={card}>
              <h2 className="text-xl font-bold">Диалоги по дням</h2>
              <Rows values={a.daily} />
            </section>
            <section className={card}>
              <h2 className="text-xl font-bold">Источники</h2>
              <Rows values={a.sources ?? {}} />
              <p className="mt-2 text-sm text-(--color-ink-muted)">
                Источник и UTM пока не сохраняются; данные не подставляются автоматически.
              </p>
            </section>
            <section className={card}>
              <h2 className="text-xl font-bold">Темы сообщений</h2>
              <p className="mt-2 text-sm text-(--color-ink-muted)">
                Подсчитаны только сообщения с сохранённой темой.
              </p>
              <Rows values={a.topics} />
            </section>
          </div>
          <p className="mt-4 text-sm text-(--color-ink-muted)">
            За предыдущий период той же длительности: {a.previousTotal ?? 0} диалогов; изменение:{' '}
            {a.total - (a.previousTotal ?? 0) > 0 ? '+' : ''}
            {a.total - (a.previousTotal ?? 0)}.
          </p>
          <label className="mt-4 flex cursor-pointer items-center gap-2 text-sm">
            <input
              type="checkbox"
              checked={compare}
              onChange={(event) => setCompare(event.target.checked)}
            />
            Сравнить с предыдущим периодом
          </label>
          <section hidden={!compare} className={`${card} mt-4`} aria-label="Сравнение периодов">
            <h2 className="text-xl font-bold">Сравнение периодов</h2>
            <p className="mt-2 text-sm text-(--color-ink-muted)">
              Сравниваются текущие состояния диалогов, созданных в каждом из периодов одинаковой
              длительности.
            </p>
            <div className="mt-3 overflow-x-auto">
              <table className="w-full text-left text-sm">
                <thead>
                  <tr>
                    <th className="p-2">Показатель</th>
                    <th className="p-2">Предыдущий период</th>
                    <th className="p-2">Выбранный период</th>
                    <th className="p-2">Изменение</th>
                  </tr>
                </thead>
                <tbody>
                  {(
                    [
                      ['Контакт', 'contact'],
                      ['Переданы менеджеру', 'handed'],
                      ['Завершены', 'closed'],
                    ] as const
                  ).map(([label, key]) => {
                    const previous = a.previousMetrics?.[key];
                    const delta = previous == null ? null : a[key] - previous;
                    return (
                      <tr key={key} className="border-t border-(--color-border)">
                        <th className="p-2">{label}</th>
                        <td className="p-2">{previous ?? 'Нет данных'}</td>
                        <td className="p-2">{a[key]}</td>
                        <td className="p-2">
                          {delta == null ? 'Нет данных' : `${delta > 0 ? '+' : ''}${delta}`}
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          </section>
        </div>
        <div hidden={tab !== 'channels'}>
          <Channels metrics={a.channelMetrics ?? []} pages={a.sitePages ?? []} />
        </div>
        <div hidden={tab !== 'managers'}>
          {a.canManage && (
            <section id="managers" className={card}>
              <h2 className="text-xl font-bold">Работа менеджеров</h2>
              <p className="mt-2 text-sm text-(--color-ink-muted)">
                Показаны текущие назначения; время реакции — от передачи до принятия, оценки
                относятся к назначенным диалогам.
              </p>
              {a.managers.length === 0 ? (
                <p className="mt-3 text-sm">Назначений пока нет.</p>
              ) : (
                <div className="mt-3 overflow-x-auto">
                  <table className="w-full min-w-[750px] text-left text-sm">
                    <thead>
                      <tr>
                        <th>Участник</th>
                        <th>Взял</th>
                        <th>Время реакции</th>
                        <th>Закрыл с контактом</th>
                        <th>Пометки</th>
                        <th>Оценка</th>
                        <th>Образцов речи добавил</th>
                      </tr>
                    </thead>
                    <tbody>
                      {a.managers.map((m, i) => (
                        <tr key={i} className="border-t border-(--color-border)">
                          <th className="py-3">{m.name}</th>
                          <td>{m.taken}</td>
                          <td>{m.reaction_minutes == null ? '—' : `${m.reaction_minutes} мин`}</td>
                          <td>{m.contact_closed ?? 0}</td>
                          <td>{m.reviews ?? 0}</td>
                          <td>{m.score ?? '—'}</td>
                          <td>{m.samples ?? '—'}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
              <h3 className="mt-4 font-semibold">Загрузка по дням</h3>
              <WorkloadChart managers={a.managers} />
              <p className="mt-2 text-sm text-(--color-ink-muted)">Учитываются образцы с подтверждённым автором и датой. Старые импорты и предложения без указанного типа не учитываются; при фильтре канала или источника показатель недоступен.</p>
            </section>
          )}
        </div>
        <Details data={a} activeTab={tab} />
      </main>
    </CabinetLayout>
  );
}

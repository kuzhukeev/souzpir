import { Head, Link, usePage } from '@inertiajs/react';
import {
  ChartNoAxesCombined,
  CreditCard,
  MessageCircle,
  MessagesSquare,
  Share2,
  Users,
} from 'lucide-react';
import CabinetLayout from '@/layouts/CabinetLayout';
import type { SharedPropsV1 } from '@/types/generated/data';

type Row = {
  key: string;
  label: string;
  value: string | number | null;
  status: 'ready' | 'unavailable';
  limit: string | null;
  configured: boolean;
};
type Props = {
  rows: Row[];
  budget: { used: string | null; limit: string | null } | null;
  period: { from: string; to: string; timezone: string };
};
const icons = [MessageCircle, MessagesSquare, Share2, Users];
function display(value: string | number | null) {
  if (value === null) return '—';
  const [whole, fraction] = String(value).split('.');
  return whole.replace(/\B(?=(\d{3})+(?!\d))/g, ' ') + (fraction ? ',' + fraction : '');
}
function date(value: string) {
  return new Intl.DateTimeFormat('ru-RU', {
    day: 'numeric',
    month: 'long',
    year: 'numeric',
    timeZone: 'UTC',
  }).format(new Date(value + 'T00:00:00Z'));
}
export default function Index({ rows, budget, period }: Props) {
  const { props } = usePage<SharedPropsV1 & Record<string, unknown>>();
  const notificationsAvailable = Object.values(props.ziggy?.routes ?? {}).some(route => route.uri === 'seller/settings/notifications' && route.methods.includes('GET'));
  return (
    <CabinetLayout
      breadcrumbs={[
        { label: 'Главная' },
        { label: 'Продавец' },
        { label: 'Настройки' },
        { label: 'Лимиты' },
      ]}
    >
      <Head title="Лимиты модуля" />
      <header className="mb-6">
        <h1 className="text-3xl font-bold tracking-tight">Лимиты модуля</h1>
        <p className="mt-1 text-base text-(--color-ink-muted)">
          Использование ИИ-продавца в текущем месяце
        </p>
      </header>
      <nav aria-label="Настройки продавца" className="mb-6 flex flex-wrap gap-2">
        <Link href="/seller/settings" className="cursor-pointer rounded-lg bg-(--color-surface) px-4 py-3 text-sm text-(--color-link)">Режим работы</Link>
        <Link href="/seller/settings" className="cursor-pointer rounded-lg bg-(--color-surface) px-4 py-3 text-sm text-(--color-link)">Распределение</Link>
        <span
          aria-current="page"
          className="rounded-lg bg-(--color-success-soft) px-4 py-3 text-sm font-semibold text-(--color-action)"
        >
          Лимиты
        </span>
        {notificationsAvailable ? <Link href="/seller/settings/notifications" className="cursor-pointer rounded-lg bg-(--color-surface) px-4 py-3 text-sm text-(--color-link)">Уведомления</Link> : <span aria-disabled="true" className="rounded-lg bg-(--color-surface) px-4 py-3 text-sm text-(--color-ink-muted)">Уведомления · Скоро</span>}
      </nav>
      <section
        aria-labelledby="usage-heading"
        className="rounded-2xl bg-(--color-surface) p-5 shadow-(--shadow-card) sm:p-6"
      >
        <div className="mb-4 flex flex-wrap items-baseline justify-between gap-2">
          <h2 id="usage-heading" className="text-xl font-bold">
            Использование за период
          </h2>
          <p className="text-sm text-(--color-ink-muted)">
            С {date(period.from)} до {date(period.to)} · UTC
          </p>
        </div>
        <ul className="divide-y divide-(--color-border)">
          {rows.map((row, i) => {
            const Icon = icons[i] || ChartNoAxesCombined;
            const value = row.status === 'ready' && row.value !== null ? Number(row.value) : null;
            const max = row.configured && row.limit !== null ? Number(row.limit) : null;
            const percent =
              value !== null &&
              max !== null &&
              Number.isFinite(value) &&
              Number.isFinite(max) &&
              max > 0
                ? Math.round((value / max) * 100)
                : null;
            return (
              <li
                key={row.key}
                className="grid gap-3 py-4 sm:grid-cols-[minmax(210px,1.2fr)_minmax(110px,.5fr)_minmax(120px,2fr)_4rem] sm:items-center"
              >
                <div className="flex items-center gap-3">
                  <span className="grid size-11 shrink-0 place-items-center rounded-full bg-(--color-success-soft) text-(--color-action)">
                    <Icon size={21} aria-hidden="true" />
                  </span>
                  <span className="font-semibold">{row.label}</span>
                </div>
                <span className="text-sm">
                  {row.status === 'ready' ? display(row.value) : 'Нет данных'}{' '}
                  <span className="text-(--color-ink-muted)">
                    / {row.configured ? display(row.limit) : 'не задано'}
                  </span>
                </span>
                {percent !== null ? (
                  <progress
                    aria-label={row.label}
                    value={Math.min(percent, 100)}
                    max={100}
                    className={
                      'h-3 w-full ' +
                      (percent >= 80 ? 'accent-amber-500' : 'accent-(--color-action)')
                    }
                  />
                ) : (
                  <span className="text-sm text-(--color-ink-muted)">
                    {row.configured ? 'Нет данных для расчёта' : 'Лимит не установлен'}
                  </span>
                )}
                {percent !== null && <span className="text-sm font-semibold">{percent} %</span>}
              </li>
            );
          })}
        </ul>
      </section>
      <section
        aria-labelledby="units-heading"
        className="mt-5 rounded-2xl bg-(--color-surface) p-5 shadow-(--shadow-card)"
      >
        <div className="flex items-start gap-3">
          <CreditCard size={22} className="mt-0.5 text-(--color-link)" aria-hidden="true" />
          <div>
            <h2 id="units-heading" className="font-bold">
              Тарифные единицы ИИ
            </h2>
            <p className="mt-1 text-sm text-(--color-ink-muted)">
              {budget?.used != null ? display(budget.used) : 'Нет данных'} /{' '}
              {budget?.limit != null ? display(budget.limit) : 'лимит не задан'}
            </p>
            <p className="mt-1 text-sm text-(--color-ink-muted)">
              Стоимость в денежных единицах не отображается до настройки тарифа.
            </p>
          </div>
        </div>
      </section>
    </CabinetLayout>
  );
}

import { Head, Link } from '@inertiajs/react';
import { CircleCheck, Star } from 'lucide-react';
import CabinetLayout from '@/layouts/CabinetLayout';

type Point = { id: string; date: string; visibility: number };
type Item = {
  id: string;
  title: string;
  description: string | null;
  completed_at: string;
  completed_by_name: string | null;
  before: Point | null;
  after: Point | null;
  before_number: number | null;
  after_number: number | null;
  delta: number | null;
  status: 'growth' | 'no_change' | 'too_early';
};

const card = 'rounded-2xl border border-(--color-border) bg-(--color-surface) p-5 shadow-(--shadow-card)';
const statusLabel: Record<Item['status'], string> = {
  growth: 'Есть рост',
  no_change: 'Без изменений',
  too_early: 'Рано судить',
};
const statusClass: Record<Item['status'], string> = {
  growth: 'bg-(--color-success-soft) text-(--color-success-ink)',
  no_change: 'bg-(--color-warning-soft) text-(--color-warning-ink)',
  too_early: 'bg-(--color-info-soft) text-(--color-info-ink)',
};

function formatDate(value?: string | null) {
  if (!value) return '—';
  const parsed = new Date(value);
  return Number.isNaN(parsed.getTime()) ? value : parsed.toLocaleDateString('ru-RU');
}
function number(value: number | null) {
  return value === null ? '—' : `№${value}`;
}
function visibilityChange(item: Item) {
  return item.before && item.after ? `${item.before.visibility}% → ${item.after.visibility}%` : '—';
}
function StatusBadge({ status }: { status: Item['status'] }) {
  return (
    <span className={`inline-flex items-center gap-2 whitespace-nowrap rounded-full px-3 py-1 text-sm font-semibold ${statusClass[status]}`}>
      <span aria-hidden="true" className="size-2 rounded-full bg-current" />
      {statusLabel[status]}
    </span>
  );
}

export default function Effect({ items }: { items: Item[] }) {
  return (
    <CabinetLayout breadcrumbs={[{ label: 'Видение', href: '/vision' }, { label: 'Действия', href: '/vision/actions' }, { label: 'История и эффект' }]}>
      <Head title="История и эффект" />
      <main className="mx-auto max-w-[1100px] pb-12" data-private-profile>
        <h1 className="text-3xl font-bold">История и эффект</h1>
        <p className="mt-2 text-(--color-ink-muted)">Как изменились ответы после выполненных действий</p>

        <section className={`${card} mt-5`}>
          <div className="flex items-center gap-4">
            <span aria-hidden="true" className="flex size-14 shrink-0 items-center justify-center rounded-full bg-(--color-success-soft) text-(--color-success-ink)">
              <Star className="size-6" />
            </span>
            <div>
              <h2 className="font-bold">Достижений пока нет</h2>
              <p className="mt-1 text-sm text-(--color-ink-muted)">Они появятся по мере продвижения.</p>
            </div>
          </div>
        </section>

        <section className={`${card} mt-5`}>
          <h2 className="text-xl font-bold">История действий и их эффект</h2>
          <p className="mt-2 text-sm text-(--color-ink-muted)">
            Эффект показывает, как изменились ответы на вопросы, которые затронуты этим действием.
          </p>
          {items.length ? (
            <div className="mt-5 overflow-x-auto">
              <table className="w-full min-w-[720px] text-left text-sm">
                <thead>
                  <tr className="border-b border-(--color-border)">
                    <th className="p-3">Действие</th>
                    <th className="p-3">Выполнено</th>
                    <th className="p-3">Замер до → после</th>
                    <th className="p-3">Видимость: было → стало</th>
                    <th className="p-3">Эффект</th>
                  </tr>
                </thead>
                <tbody>
                  {items.map((item) => (
                    <tr key={item.id} className="border-b border-(--color-border) last:border-0">
                      <td className="p-3">
                        <p className="font-semibold">{item.title}</p>
                        {item.description && <p className="mt-1 text-(--color-ink-muted)">{item.description}</p>}
                      </td>
                      <td className="p-3">
                        {formatDate(item.completed_at)}
                        {item.completed_by_name ? ` · ${item.completed_by_name}` : ''}
                      </td>
                      <td className="p-3">
                        {number(item.before_number)} → {number(item.after_number)}
                      </td>
                      <td className="p-3">{visibilityChange(item)}</td>
                      <td className="p-3">
                        <StatusBadge status={item.status} />
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          ) : (
            <div className="mt-5 flex flex-col items-center gap-4 py-10 text-center">
              <span aria-hidden="true" className="flex size-16 items-center justify-center rounded-full bg-(--color-success-soft) text-(--color-success-ink)">
                <CircleCheck className="size-8" />
              </span>
              <div>
                <h3 className="text-lg font-bold">Выполненных действий пока нет</h3>
                <p className="mt-1 text-sm text-(--color-ink-muted)">
                  Отметьте действие выполненным, чтобы отслеживать эффект после следующего замера.
                </p>
              </div>
              <Link
                href="/vision/actions"
                className="inline-flex min-h-11 cursor-pointer items-center rounded-lg border border-(--color-border) px-4 text-sm font-semibold text-(--color-link) hover:bg-(--color-surface-muted)"
              >
                К действиям
              </Link>
            </div>
          )}
        </section>
      </main>
    </CabinetLayout>
  );
}

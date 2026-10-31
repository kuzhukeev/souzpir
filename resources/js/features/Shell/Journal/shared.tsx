import { Link } from '@inertiajs/react';
import { Button, Input, Pagination, Select } from '@/components/ui';
import { useState, type FormEvent, type ReactNode } from 'react';
import { go, type Filters, type Option, type Page } from './model';

export function JournalHeader({
  title,
  subtitle,
  tab,
  canNotifications = true,
  right,
}: {
  title: string;
  subtitle: string;
  tab: 'actions' | 'notifications';
  canNotifications?: boolean;
  right?: ReactNode;
}) {
  const link = (active: boolean) =>
    'inline-flex min-h-11 items-center border-b-2 px-4 text-sm font-semibold ' +
    (active
      ? 'border-(--color-action) text-(--color-action)'
      : 'border-transparent text-(--color-link) hover:bg-(--color-surface-muted)');
  return (
    <>
      <div className="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
          <h1 className="text-3xl font-bold tracking-tight">{title}</h1>
          <p className="mt-1 text-base text-(--color-ink-muted)">{subtitle}</p>
        </div>
        {right}
      </div>
      <nav
        aria-label="Разделы журнала"
        className="mb-6 flex flex-wrap border-b border-(--color-border)"
      >
        <Link
          href="/journal"
          aria-current={tab === 'actions' ? 'page' : undefined}
          className={link(tab === 'actions')}
        >
          Действия в кабинете
        </Link>
        {canNotifications && (
          <Link
            href="/journal/notifications"
            aria-current={tab === 'notifications' ? 'page' : undefined}
            className={link(tab === 'notifications')}
          >
            История уведомлений
          </Link>
        )}
      </nav>
    </>
  );
}
export function FiltersForm({
  initial,
  path,
  options,
}: {
  initial: Filters;
  path: string;
  options: { key: keyof Filters; label: string; items: Option[] }[];
}) {
  const [filters, setFilters] = useState<Filters>(initial);
  const set = (key: keyof Filters, value: string) =>
    setFilters((current) => ({ ...current, [key]: value === 'all' ? '' : value }));
  const submit = (event: FormEvent) => {
    event.preventDefault();
    go(path, filters);
  };
  return (
    <form
      onSubmit={submit}
      className="mb-5 flex flex-wrap items-end gap-3 rounded-2xl bg-(--color-surface) p-5 shadow-(--shadow-card)"
    >
      <p className="w-full text-sm text-(--color-ink-muted)">Время и даты указаны по Москве</p>
      <Input
        label="С даты"
        type="date"
        value={filters.from ?? ''}
        onChange={(e) => set('from', e.target.value)}
        className="w-40 max-w-full"
      />
      <Input
        label="По дату"
        type="date"
        value={filters.to ?? ''}
        min={filters.from || undefined}
        onChange={(e) => set('to', e.target.value)}
        className="w-40 max-w-full"
      />
      {options.map((item) => (
        <Select
          key={item.key}
          label={item.label}
          value={filters[item.key] || 'all'}
          onValueChange={(value) => set(item.key, value)}
          className="min-w-40 flex-1"
          options={[{ value: 'all', label: 'Все' }, ...item.items]}
        />
      ))}
      <Button type="submit">Применить</Button>
      <Button
        variant="ghost"
        onClick={() => {
          setFilters({});
          go(path, {});
        }}
      >
        Сбросить
      </Button>
    </form>
  );
}
export function Pages<T>({
  rows,
  filters,
  path,
}: {
  rows: Page<T>;
  filters: Filters;
  path: string;
}) {
  return (
    <div className="flex flex-wrap items-center justify-between gap-3 pt-4 text-sm text-(--color-ink-muted)">
      <span>Всего: {rows.total}</span>
      {rows.last_page > 1 && (
        <Pagination
          page={rows.current_page}
          pages={rows.last_page}
          onChange={(page) => go(path, filters, page)}
        />
      )}
    </div>
  );
}

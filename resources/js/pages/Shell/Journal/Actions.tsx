import { Head, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Avatar, Button, DataTable, Drawer, Skeleton } from '@/components/ui';
import CabinetLayout from '@/layouts/CabinetLayout';
import {
  actionLabel,
  compact,
  formatDate,
  type Filters,
  type Page,
} from '@/features/Shell/Journal/model';
import { useJournalNavigation } from '@/features/Shell/Journal/navigation';
import { JournalHeader, Pages } from '@/features/Shell/Journal/shared';
import ActionFilters from '@/features/Shell/Journal/ActionFilters';
import { subjectPath } from '@/features/Shell/Journal/subjectPath';

type Row = {
  id: string;
  created_at: string;
  action: string;
  subject_type: string;
  subject_id: string;
  brand_id: string | null;
  actor: string | null;
  before: string | null;
  after: string | null;
};
type Props = {
  rows: Page<Row>;
  filters: Filters;
  actors: { id: string; name: string }[];
  actions: string[];
  canNotifications: boolean;
};
function moduleName(action: string) {
  return action.startsWith('vision.')
    ? 'Видение'
    : action.startsWith('seller.') || action.startsWith('knowledge.')
      ? 'ИИ-продавец'
      : 'Кабинет';
}
function changes(before: string | null, after: string | null): {key:string;before:string;after:string}[] {
  const parse=(value:string|null):Record<string,unknown>=>{if(!value)return {};try { const parsed:unknown=JSON.parse(value);return parsed && typeof parsed==='object' && !Array.isArray(parsed)?parsed as Record<string,unknown>:{'значение':parsed}; } catch { return {'значение':value}; }};
  const left=parse(before),right=parse(after);const display=(value:unknown)=>value===undefined||value===null?'—':typeof value==='string'?value:JSON.stringify(value);
  return [...new Set([...Object.keys(left),...Object.keys(right)])].filter(key=>JSON.stringify(left[key])!==JSON.stringify(right[key])).map(key=>({key,before:display(left[key]),after:display(right[key])}));
}
export default function Actions({ rows, filters, actors, actions, canNotifications }: Props) {
  const { brand } = usePage<{ brand: { id: string; name: string } | null }>().props;
  const [selected, setSelected] = useState<Row | null>(null);
  const { loading, failed, retry: retryLoad } = useJournalNavigation();
  const query = new URLSearchParams(compact(filters)).toString();
  const actorOptions = actors.map((actor) => ({ value: actor.id, label: actor.name }));
  if (filters.actor && !actorOptions.some((actor) => actor.value === filters.actor)) {
    actorOptions.push({ value: filters.actor, label: 'Выбранный участник' });
  }
  return (
    <CabinetLayout breadcrumbs={[{ label: 'Главная' }, { label: 'Журнал' }]}>
      <Head title="Действия в кабинете" />
      <JournalHeader
        title="Действия в кабинете"
        subtitle="История изменений и действий команды"
        tab="actions"
        canNotifications={canNotifications}
        right={
          <a
            className="inline-flex min-h-10 cursor-pointer items-center rounded-lg bg-(--color-action) px-4 text-sm font-semibold text-white hover:bg-(--color-action-hover)"
            href={'/journal/export' + (query ? '?' + query : '')}
          >
            Экспорт CSV
          </a>
        }
      />
      <ActionFilters
        key={JSON.stringify(filters)}
        initial={filters}
        options={[
          {
            key: 'actor',
            label: 'Участник',
            items: actorOptions,
          },
          {
            key: 'module',
            label: 'Модуль',
            items: [
              { value: 'seller', label: 'ИИ-продавец' },
              { value: 'vision', label: 'Видение' },
              { value: 'cabinet', label: 'Кабинет' },
            ],
          },
          {
            key: 'action',
            label: 'Тип действия',
            items: actions.map((action) => ({ value: action, label: actionLabel(action) })),
          },
        ]}
      />
      <section
        aria-label="Список действий"
        aria-busy={loading}
        className="rounded-2xl bg-(--color-surface) p-5 shadow-(--shadow-card)"
      >
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
            <Skeleton label="Загружается журнал действий" />
            <Skeleton label="Загрузка" />
            <Skeleton label="Загрузка" />
          </div>
        ) : (
          <>
            <DataTable<Row>
              label="Действия в кабинете"
              rows={rows.data}
              empty={Object.values(filters).some(Boolean) ? 'По выбранным фильтрам действий нет' : 'Действий пока нет'}
              columns={[
                { key: 'created_at', label: 'Время', render: (row) => formatDate(row.created_at) },
                {
                  key: 'actor',
                  label: 'Кто',
                  render: (row) => (
                    <span className="flex items-center gap-2">
                      <Avatar name={row.actor || 'Система'} />
                      {row.actor || 'Система'}
                    </span>
                  ),
                },
                { key: 'action', label: 'Действие', render: (row) => actionLabel(row.action) },
                {
                  key: 'subject_id',
                  label: 'Объект',
                  render: (row) => { const path = subjectPath(row.subject_type, row.subject_id); return path ? <a className="cursor-pointer break-all text-(--color-link) underline" href={path}>{row.subject_id}</a> : <span className="break-all">{row.subject_id}</span>; },
                },
                {
                  key: 'brand_id',
                  label: 'Бренд',
                  render: (row) => (row.brand_id ? (brand?.name ?? 'Текущий бренд') : 'Вся компания'),
                },
                { key: 'subject_type', label: 'Модуль', render: (row) => moduleName(row.action) },
                {
                  key: 'id',
                  label: 'Детали',
                  render: (row) => (
                    <Button variant="ghost" size="sm" onClick={() => setSelected(row)}>
                      Открыть
                    </Button>
                  ),
                },
              ]}
            />
            <Pages rows={rows} filters={filters} path="/journal" />
          </>
        )}
      </section>
      <Drawer open={selected !== null} title="Детали действия" onClose={() => setSelected(null)}>
        {selected && (
          <dl className="space-y-4">
            <div>
              <dt className="text-(--color-ink-muted)">Время</dt>
              <dd>{formatDate(selected.created_at)}</dd>
            </div>
            <div>
              <dt className="text-(--color-ink-muted)">Участник</dt>
              <dd>{selected.actor || 'Система'}</dd>
            </div>
            <div>
              <dt className="text-(--color-ink-muted)">Действие</dt>
              <dd>{actionLabel(selected.action)}</dd>
            </div>
            <div>
              <dt className="text-(--color-ink-muted)">Бренд</dt>
              <dd>{selected.brand_id ? (brand?.name ?? 'Текущий бренд') : 'Вся компания'}</dd>
            </div>
            <div>
              <dt className="text-(--color-ink-muted)">Модуль</dt>
              <dd>{moduleName(selected.action)}</dd>
            </div>
            <div><dt className="text-(--color-ink-muted)">Изменения</dt><dd>{changes(selected.before,selected.after).length ? <dl className="mt-2 space-y-2">{changes(selected.before,selected.after).map(item=><div key={item.key} className="rounded-lg bg-(--color-bg) p-3"><dt className="font-semibold">{item.key.replaceAll('_',' ')}</dt><dd className="mt-1 break-words">{item.before} → {item.after}</dd></div>)}</dl> : 'Изменений нет'}</dd></div>
            <div><dt className="text-(--color-ink-muted)">Объект</dt><dd className="break-all">{selected.subject_id}</dd></div>
            {subjectPath(selected.subject_type, selected.subject_id) && <div><a href={subjectPath(selected.subject_type, selected.subject_id) ?? '#'} className="inline-flex min-h-11 cursor-pointer items-center font-semibold text-(--color-link)">Открыть полностью</a></div>}
          </dl>
        )}
      </Drawer>
    </CabinetLayout>
  );
}

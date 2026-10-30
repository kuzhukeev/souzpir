import PreviewSelect from './PreviewSelect';
import { useEffect, useRef, useState } from 'react';
import {
  AlertCircle,
  BarChart3,
  BookOpen,
  ChevronRight,
  Clock3,
  FileText,
  Flag,
  LayoutGrid,
  MessageCircle,
  MoreVertical,
  PieChart,
  Search,
  UserRound,
  Users,
  type LucideIcon,
} from 'lucide-react';
import PreviewShell from './PreviewShell';
import { Button, DemoDialog, EmptyCheck } from './ui';
import type { PreviewTheme } from './preview-state';

export type AttentionState = 'full' | 'empty' | 'loading' | 'error';
type Module = 'seller' | 'vision' | 'workspace';
type Priority = 'high' | 'medium' | 'normal';
type DemoEvent = {
  id: string;
  title: string;
  description: string;
  module: Module;
  priority: Priority;
  icon: LucideIcon;
  details: string;
};
const modules: Record<Module, string> = {
  seller: 'ИИ-продавец',
  vision: 'Видение',
  workspace: 'Кабинет',
};
const priorities: Record<Priority, string> = {
  high: 'Высокая',
  medium: 'Средняя',
  normal: 'Обычная',
};
const priorityStyles: Record<Priority, string> = {
  high: 'bg-(--color-danger-soft) text-(--color-danger-ink)',
  medium: 'bg-(--color-warning-soft) text-(--color-warning-ink)',
  normal: 'bg-(--color-info-soft) text-(--color-info-ink)',
};
const events: DemoEvent[] = [
  {
    id: 'dialogues',
    title: 'Диалоги ждут менеджера',
    description: '3 диалога ожидают ответа',
    module: 'seller',
    priority: 'high',
    icon: MessageCircle,
    details:
      'Пример передачи диалога менеджеру. В следующем этапе здесь откроется реальный список переписок.',
  },
  {
    id: 'questions',
    title: 'Неизвестные вопросы',
    description: '4 вопроса без ответа',
    module: 'seller',
    priority: 'medium',
    icon: BookOpen,
    details:
      'Пример вопросов, для которых нужно дополнить знания продавца. Тексты и знания в этой демонстрации не изменяются.',
  },
  {
    id: 'terms',
    title: 'Условия не утверждены',
    description: '2 условия ожидают утверждения',
    module: 'seller',
    priority: 'medium',
    icon: FileText,
    details:
      'Пример проверки коммерческих условий. Никакое условие из макета не становится правилом продавца.',
  },
  {
    id: 'measurement',
    title: 'Замер готов к уточнениям',
    description: '6 ответов требуют проверки',
    module: 'vision',
    priority: 'normal',
    icon: BarChart3,
    details: 'Пример готового замера видимости. Реальный запрос к ИИ и измерение не выполняются.',
  },
  {
    id: 'limit',
    title: 'Лимит тарифа',
    description: 'Использовано 80% диалогов',
    module: 'workspace',
    priority: 'medium',
    icon: PieChart,
    details:
      '80% — иллюстративное значение из макета, а не действующий лимит или тариф. Коммерческие условия пока не заданы.',
  },
  {
    id: 'invitation',
    title: 'Участник не принял приглашение',
    description: 'Приглашение отправлено 5 дней назад',
    module: 'workspace',
    priority: 'normal',
    icon: UserRound,
    details: 'Пример приглашения в команду. Повторная отправка письма в предпросмотре недоступна.',
  },
];

function EventIcon({ item, small = false }: { item: DemoEvent; small?: boolean }) {
  const Icon = item.icon;
  return (
    <span
      className={`grid shrink-0 place-items-center rounded-full ${small ? 'h-10 w-10' : 'h-12 w-12'} ${priorityStyles[item.priority]}`}
    >
      <Icon size={small ? 22 : 25} strokeWidth={1.75} />
    </span>
  );
}

function PriorityBadge({ priority }: { priority: Priority }) {
  return (
    <span
      className={`inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-1 text-sm ${priorityStyles[priority]}`}
    >
      <span className="h-2 w-2 rounded-full bg-current" />
      {priorities[priority]}
    </span>
  );
}

function LoadingRows() {
  return (
    <div
      role="status"
      aria-label="Загрузка демонстрационных событий"
      aria-busy="true"
      className="space-y-5 px-5 py-8"
    >
      <span className="sr-only">Загрузка событий…</span>
      {Array.from({ length: 6 }, (_, i) => (
        <div key={i} className="flex items-center gap-4 motion-safe:animate-pulse">
          <span className="h-12 w-12 shrink-0 rounded-full bg-(--color-surface-muted)" />
          <div className="flex-1 space-y-2">
            <div className="h-4 w-3/4 rounded bg-(--color-surface-muted)" />
            <div className="h-3 w-1/2 rounded bg-(--color-surface-muted)" />
          </div>
          <div className="hidden h-6 w-20 rounded-full bg-(--color-surface-muted) sm:block" />
        </div>
      ))}
    </div>
  );
}

function HomeWidget({
  items,
  state,
  onOpen,
}: {
  items: DemoEvent[];
  state: AttentionState;
  onOpen: (item: DemoEvent) => void;
}) {
  const empty = items.length === 0 && state !== 'loading' && state !== 'error';
  const preview = items.slice(0, 2);
  return (
    <aside
      aria-label="Пример виджета на главной"
      className={`h-fit rounded-(--radius-card) bg-(--color-surface) p-5 shadow-(--shadow-card) min-[1440px]:p-5 ${empty ? 'min-[1440px]:mt-[106px]' : ''}`}
    >
      {!empty && (
        <>
          <h2 className="text-xl font-bold">На главной</h2>
          <p className="mt-1 text-sm leading-6 text-(--color-ink-muted)">
            Так выглядит виджет
            <br className="hidden min-[1440px]:block" /> на Главной странице
          </p>
        </>
      )}
      <div className={empty ? '' : 'mt-4 rounded-xl border border-(--color-border) p-3'}>
        <div className="flex items-center justify-between gap-3">
          <h3 className="text-[14px] font-bold leading-5">Требует вашего действия</h3>
          <span
            className={`grid h-6 w-6 shrink-0 place-items-center rounded-full text-sm font-bold ${empty ? 'bg-(--color-warning-soft) text-(--color-warning-ink)' : 'bg-(--color-danger) text-white'}`}
          >
            {empty ? 0 : preview.length}
          </span>
        </div>
        {state === 'loading' ? (
          <div className="mt-5 space-y-3 motion-safe:animate-pulse">
            <div className="h-14 rounded-lg bg-(--color-surface-muted)" />
            <div className="h-14 rounded-lg bg-(--color-surface-muted)" />
          </div>
        ) : state === 'error' ? (
          <p className="py-5 text-sm text-(--color-ink-muted)">Данные виджета недоступны.</p>
        ) : empty ? (
          <div className="pt-7 pb-4 text-center">
            <EmptyCheck compact />
            <h4 className="mt-3 text-xl font-bold">Всё в порядке</h4>
            <p className="mt-2 text-sm leading-6 text-(--color-ink-muted)">
              Нет событий, требующих внимания.
            </p>
          </div>
        ) : (
          <div className="mt-3 space-y-2">
            {preview.map((item, index) => (
              <button
                key={item.id}
                type="button"
                onClick={() => onOpen(item)}
                className="flex w-full items-center gap-2 rounded-lg bg-(--color-bg) px-2 py-3 text-left hover:bg-(--color-surface-muted)"
              >
                <span className="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-(--color-warning-soft) text-(--color-warning-ink)">
                  {index === 0 ? <Users size={21} /> : <BookOpen size={21} />}
                </span>
                <span className="min-w-0 flex-1">
                  <span className="block text-sm font-semibold leading-5">{item.title}</span>
                  <span className="mt-1 block text-sm leading-5 text-(--color-ink-muted)">
                    {item.description}
                  </span>
                </span>
                <ChevronRight size={15} className="shrink-0 text-(--color-icon)" />
              </button>
            ))}
          </div>
        )}
      </div>
    </aside>
  );
}

export default function AttentionPreview({
  state,
  onStateChange,
  theme,
  onNotice,
}: {
  state: AttentionState;
  onStateChange: (state: AttentionState) => void;
  theme: PreviewTheme;
  onNotice: (message: string) => void;
}) {
  const [module, setModule] = useState<Module | 'all'>('all');
  const [priority, setPriority] = useState<Priority | 'all'>('all');
  const [query, setQuery] = useState('');
  const [snoozed, setSnoozed] = useState<string[]>([]);
  const [menu, setMenu] = useState('');
  const menuTrigger = useRef<HTMLButtonElement | null>(null);
  const [selected, setSelected] = useState<DemoEvent | null>(null);
  const [drafts, setDrafts] = useState<Record<string, string>>({});
  useEffect(() => {
    const close = (event: KeyboardEvent) => {
      if (event.key === 'Escape' && menu) {
        setMenu('');
        menuTrigger.current?.focus();
      }
    };
    const outside = (event: MouseEvent) => {
      if (event.target instanceof Element && !event.target.closest('[data-event-menu]'))
        setMenu('');
    };
    document.addEventListener('keydown', close);
    document.addEventListener('click', outside);
    return () => {
      document.removeEventListener('keydown', close);
      document.removeEventListener('click', outside);
    };
  }, [menu]);
  const available = state === 'empty' ? [] : events.filter((item) => !snoozed.includes(item.id));
  const visible = available.filter(
    (item) =>
      (module === 'all' || item.module === module) &&
      (priority === 'all' || item.priority === priority) &&
      `${item.title} ${item.description} ${modules[item.module]}`
        .toLocaleLowerCase('ru')
        .includes(query.trim().toLocaleLowerCase('ru')),
  );
  const filtered = module !== 'all' || priority !== 'all' || query.trim() !== '';
  const resetFilters = () => {
    setModule('all');
    setPriority('all');
    setQuery('');
  };
  const home = () => {
    resetFilters();
    setSnoozed([]);
    onStateChange('full');
    onNotice('Показан заполненный демонстрационный экран кабинета.');
  };
  const snooze = (item: DemoEvent) => {
    const index = visible.findIndex((event) => event.id === item.id);
    const nextId = (visible[index + 1] ?? visible[index - 1])?.id;
    setSnoozed((values) => [...values, item.id]);
    setMenu('');
    requestAnimationFrame(() => {
      const nextTrigger = Array.from(
        document.querySelectorAll<HTMLButtonElement>('[data-event-trigger]'),
      ).find(
        (button) => button.dataset.eventTrigger === nextId && button.getClientRects().length > 0,
      );
      (nextTrigger ?? document.getElementById('attention-heading'))?.focus();
    });
    onNotice(
      'Событие отложено только в текущем макете. Реальное напоминание не создано; «Сбросить демо» вернёт событие.',
    );
  };
  const actions = (item: DemoEvent) => (
    <div className="flex items-center justify-end gap-2 sm:gap-3">
      <button
        type="button"
        onClick={() => setSelected(item)}
        aria-label={`Открыть: ${item.title}`}
        className="rounded py-2 text-sm font-medium text-(--color-action) hover:underline"
      >
        Открыть
      </button>
      <div data-event-menu className="relative">
        <button
          type="button"
          aria-label={`Действия: ${item.title}`}
          data-event-trigger={item.id}
          aria-expanded={menu === item.id}
          onClick={(event) => {
            menuTrigger.current = event.currentTarget;
            setMenu(menu === item.id ? '' : item.id);
          }}
          className="rounded-lg p-2 text-(--color-icon) hover:bg-(--color-surface-muted)"
        >
          <MoreVertical size={20} />
        </button>
        {menu === item.id && (
          <div className="absolute right-0 top-full z-30 w-64 rounded-xl border border-(--color-border) bg-(--color-surface) p-2 shadow-(--shadow-card)">
            <button
              type="button"
              onClick={() => snooze(item)}
              className="flex w-full items-center gap-2 rounded-lg px-3 py-3 text-left text-sm text-(--color-link) hover:bg-(--color-surface-muted)"
            >
              <Clock3 size={19} />
              Отложить на 7 дней
            </button>
          </div>
        )}
      </div>
    </div>
  );
  return (
    <PreviewShell
      theme={theme}
      searchValue={query}
      onSearch={setQuery}
      onHome={home}
      onNotice={onNotice}
    >
      <main id="preview-main" className="min-w-0 px-4 py-5 lg:px-6 lg:py-6">
        <nav
          aria-label="Хлебные крошки"
          className="mb-5 flex flex-wrap items-center gap-3 text-sm text-(--color-ink-muted)"
        >
          <button type="button" onClick={home} className="rounded text-(--color-link)">
            Главная
          </button>
          <span aria-hidden="true">/</span>
          <span aria-current="page">Требует вашего действия</span>
        </nav>
        <div className="grid min-w-0 gap-5 min-[1440px]:grid-cols-[minmax(0,1fr)_360px]">
          <section className="min-w-0" aria-labelledby="attention-heading">
            <div className="flex items-center gap-3">
              <h1
                id="attention-heading"
                tabIndex={-1}
                className="text-[27px] leading-tight font-extrabold tracking-[-.7px] sm:text-[32px] min-[90rem]:text-[36px]"
              >
                Требует вашего действия
              </h1>
              {visible.length > 0 && state === 'full' && (
                <span
                  aria-label={`Событий: ${visible.length}`}
                  className="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-(--color-danger) text-base font-bold text-white"
                >
                  {visible.length}
                </span>
              )}
            </div>
            <p className="mt-1.5 text-base text-(--color-ink-muted) sm:text-lg">
              События, которым нужно ваше внимание
            </p>
            <div className="mt-6 rounded-(--radius-card) bg-(--color-surface) shadow-(--shadow-card)">
              <div
                className="grid grid-cols-2 items-center gap-3 p-4 sm:flex sm:flex-wrap lg:p-5"
                aria-label="Фильтры событий"
              >
                <PreviewSelect
                  label="Модуль"
                  icon={<LayoutGrid size={19} className="shrink-0 text-(--color-icon)" />}
                  value={module}
                  onChange={(value) => setModule(value as Module | 'all')}
                  disabled={state === 'loading'}
                  options={[
                    { value: 'all', label: 'Все модули' },
                    ...Object.entries(modules).map(([value, label]) => ({ value, label })),
                  ]}
                  className="w-full min-w-0 sm:w-44"
                />
                <PreviewSelect
                  label="Важность"
                  icon={<Flag size={19} className="shrink-0 text-(--color-icon)" />}
                  value={priority}
                  onChange={(value) => setPriority(value as Priority | 'all')}
                  disabled={state === 'loading'}
                  options={[
                    { value: 'all', label: 'Вся важность' },
                    ...Object.entries(priorities).map(([value, label]) => ({ value, label })),
                  ]}
                  className="w-full min-w-0 sm:w-48"
                />
                <label className="col-span-2 flex h-11 min-w-0 flex-1 items-center gap-2 rounded-(--radius-control) bg-(--color-bg) px-3 sm:min-w-[160px]">
                  <Search size={20} className="shrink-0 text-(--color-icon)" />
                  <span className="sr-only">Поиск по событиям</span>
                  <input
                    aria-label="Поиск по событиям"
                    type="search"
                    value={query}
                    onChange={(event) => setQuery(event.target.value)}
                    disabled={state === 'loading'}
                    placeholder="Поиск по событиям..."
                    className="w-full min-w-0 border-0 bg-transparent text-sm outline-none placeholder:text-(--color-ink-muted)"
                  />
                </label>
                <button
                  type="button"
                  onClick={resetFilters}
                  disabled={state === 'loading'}
                  className="rounded px-2 py-2 text-sm text-(--color-action) hover:underline disabled:opacity-50"
                >
                  Сбросить
                </button>
              </div>
              {state === 'loading' ? (
                <LoadingRows />
              ) : state === 'error' ? (
                <div
                  role="alert"
                  className="grid min-h-[420px] place-content-center px-6 py-16 text-center"
                >
                  <AlertCircle size={44} className="mx-auto text-(--color-danger)" />
                  <h2 className="mt-5 text-2xl font-bold">Не удалось загрузить события</h2>
                  <p className="mx-auto mt-3 max-w-md text-sm leading-6 text-(--color-ink-muted)">
                    Это пример ошибки в макете. Повторная попытка покажет демонстрационные данные.
                  </p>
                  <Button className="mx-auto mt-6" onClick={() => onStateChange('full')}>
                    Повторить
                  </Button>
                </div>
              ) : visible.length === 0 ? (
                <div className="grid min-h-[510px] place-content-center px-5 pb-16 pt-10 text-center">
                  <EmptyCheck />
                  <h2 className="mt-3 text-[30px] font-extrabold">
                    {filtered ? 'Ничего не найдено' : 'Всё в порядке'}
                  </h2>
                  <p className="mx-auto mt-3 max-w-md text-base leading-7 text-(--color-ink-muted)">
                    {filtered
                      ? 'Попробуйте изменить фильтры или поисковый запрос.'
                      : 'Сейчас нет событий, требующих вашего внимания.'}
                  </p>
                  <Button
                    variant="secondary"
                    className="mx-auto mt-7 min-w-40"
                    onClick={filtered ? resetFilters : home}
                  >
                    {filtered ? 'Сбросить фильтры' : 'На главную'}
                  </Button>
                </div>
              ) : (
                <>
                  <div className="hidden px-5 pb-3 md:block">
                    <table
                      aria-label="Демонстрационные события"
                      className="w-full table-fixed border-collapse text-left"
                    >
                      <thead>
                        <tr className="border-b border-(--color-border) text-sm font-normal text-(--color-ink-muted)">
                          <th scope="col" className="w-[47%] pb-3 font-normal">
                            Событие
                          </th>
                          <th scope="col" className="w-[17%] pb-3 font-normal">
                            Модуль
                          </th>
                          <th scope="col" className="w-[18%] pb-3 font-normal">
                            Важность
                          </th>
                          <th scope="col" className="w-[18%] pb-3 text-center font-normal">
                            Действие
                          </th>
                        </tr>
                      </thead>
                      <tbody>
                        {visible.map((item) => (
                          <tr
                            key={item.id}
                            data-testid="attention-row"
                            className="border-b border-(--color-border) last:border-0"
                          >
                            <td className="py-4 pr-3">
                              <div className="flex items-center gap-3">
                                <EventIcon item={item} />
                                <span className="min-w-0">
                                  <span className="block text-[14px] leading-5 font-bold">
                                    {item.title}
                                  </span>
                                  <span className="mt-1 block text-sm leading-5 text-(--color-ink-muted)">
                                    {item.description}
                                  </span>
                                </span>
                              </div>
                            </td>
                            <td className="pr-2 text-sm leading-5">{modules[item.module]}</td>
                            <td className="pr-1">
                              <PriorityBadge priority={item.priority} />
                            </td>
                            <td>{actions(item)}</td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                  <div className="divide-y divide-(--color-border) px-4 pb-2 md:hidden">
                    {visible.map((item) => (
                      <article key={item.id} data-testid="attention-card" className="py-4">
                        <div className="flex items-start gap-3">
                          <EventIcon item={item} small />
                          <div className="min-w-0 flex-1">
                            <h2 className="text-sm font-bold leading-5">{item.title}</h2>
                            <p className="mt-1 text-sm leading-5 text-(--color-ink-muted)">
                              {item.description}
                            </p>
                          </div>
                        </div>
                        <div className="mt-3 flex flex-wrap items-center gap-2">
                          <span className="text-sm text-(--color-ink-muted)">
                            {modules[item.module]}
                          </span>
                          <PriorityBadge priority={item.priority} />
                          <span className="ml-auto">{actions(item)}</span>
                        </div>
                      </article>
                    ))}
                  </div>
                </>
              )}
            </div>
            <p className="mt-3 text-sm leading-5 text-(--color-ink-muted)">
              Все события и цифры — примеры из макета. Тарифные условия и реальные действия здесь не
              применяются.
            </p>
          </section>
          <HomeWidget items={visible} state={state} onOpen={setSelected} />
        </div>
      </main>
      <DemoDialog
        open={selected !== null}
        title={selected?.title ?? 'Событие'}
        onClose={() => setSelected(null)}
        drawer
      >
        {selected && (
          <>
            <span className="inline-flex rounded-full bg-(--color-info-soft) px-3 py-1 text-sm text-(--color-info-ink)">
              Демонстрация события
            </span>
            <p className="mt-5 text-sm leading-7 text-(--color-ink-muted)">{selected.details}</p>
            <form
              className="mt-6"
              onSubmit={(event) => {
                event.preventDefault();
                setSelected(null);
                onNotice(
                  'Черновик заметки сохранён только в памяти этого предпросмотра. После обновления страницы он исчезнет.',
                );
              }}
            >
              <label htmlFor="event-draft" className="block text-sm font-semibold">
                Локальный черновик заметки
              </label>
              <textarea
                id="event-draft"
                value={drafts[selected.id] ?? ''}
                onChange={(event) => setDrafts({ ...drafts, [selected.id]: event.target.value })}
                rows={5}
                maxLength={2000}
                aria-describedby="draft-help"
                placeholder="Добавьте заметку для проверки формы..."
                className="mt-2 w-full resize-y rounded-(--radius-control) border border-(--color-control-border) p-3 text-sm"
              />
              <p id="draft-help" className="mt-2 text-sm leading-5 text-(--color-ink-muted)">
                Только текущая вкладка. Данные не отправляются на сервер и не сохраняются в
                браузере.
              </p>
              <div className="mt-5 flex flex-wrap gap-2">
                <Button type="submit">Сохранить черновик</Button>
                <Button variant="secondary" onClick={() => setSelected(null)}>
                  Закрыть
                </Button>
              </div>
            </form>
          </>
        )}
      </DemoDialog>
    </PreviewShell>
  );
}

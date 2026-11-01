import { useCallback, useEffect, useRef, useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import CabinetLayout from '@/layouts/CabinetLayout';
import { Button } from '@/components/ui/forms';
import { Modal } from '@/components/ui/overlay';
import { intents, dateUtc, limit } from '@/features/Vision/Questions/copy';
import { request } from '@/features/Vision/Questions/request';
import type {
  Candidate,
  Data,
  Intent,
  Question,
} from '@/types/Vision/Questions';
import { Alert } from './Alert';
import { CandidateForm } from './CandidateForm';
import { Models } from './Models';
import { SetDetails } from './SetDetails';
import { Tabs } from './Tabs';
import { card, link, secondary } from './styles';

type Screen = 'index' | 'approve' | 'active' | 'history';
const intentKeys = Object.keys(intents) as Intent[];
function ErrorText(error: unknown): string {
  return error instanceof Error ? error.message : 'Не удалось связаться с сервером.';
}
function difference(items: Question[], other: Question[]): Question[] {
  const key = (item: Question) =>
    JSON.stringify([
      item.text.normalize('NFKC').trim().replace(/\s+/gu, ' ').toLocaleLowerCase('ru'),
      item.intent,
    ]);
  const counts = new Map<string, number>();
  for (const item of other) counts.set(key(item), (counts.get(key(item)) ?? 0) + 1);
  return items.filter((item) => {
    const k = key(item);
    const remaining = counts.get(k) ?? 0;
    if (remaining === 0) return true;
    counts.set(k, remaining - 1);
    return false;
  });
}
function source(origin: string): string {
  return (
    {
      questionnaire: 'Из анкеты',
      manual: 'Добавлен вручную',
      suggested: 'Предложен',
      active_set: 'Из активного набора',
    }[origin] ?? 'Источник не указан'
  );
}
export default function Workspace({ screen }: { screen: Screen }) {
  const { brand } = usePage<{ brand: { id: string } | null }>().props;
  return <WorkspaceBody key={brand?.id ?? 'none'} screen={screen} brandId={brand?.id ?? ''} />;
}
function WorkspaceBody({ screen, brandId }: { screen: Screen; brandId: string }) {
  const privateContent = useRef<HTMLDivElement>(null);
  const [data, setData] = useState<Data | null>(null);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [busy, setBusy] = useState(false);
  const [filter, setFilter] = useState<Intent | 'all'>('all');
  const [selected, setSelected] = useState<string[]>([]);
  const [models, setModels] = useState<Record<string, string>>({});
  const [modal, setModal] = useState<'add' | 'edit' | 'import' | null>(null);
  const [editing, setEditing] = useState<Candidate | null>(null);
  const [importIntent, setImportIntent] = useState<Intent>('choose');
  const [page, setPage] = useState(1);
  const [compare, setCompare] = useState<string | null>(null);
  const load = useCallback(async () => {
    try {
      const result = await request<Data>('/vision/questions/data', brandId);
      setData(result);
      setError('');
      return true;
    } catch (failure) {
      setError(ErrorText(failure));
      return false;
    }
  }, [brandId]);
  useEffect(() => {
    let active = true;
    request<Data>('/vision/questions/data', brandId)
      .then((result) => {
        if (active) {
          setData(result);
          setError('');
        }
      })
      .catch((failure) => {
        if (active) setError(ErrorText(failure));
      });
    return () => {
      active = false;
    };
  }, [brandId]);
  useEffect(() => {
    const hidePrivateContent = () => {
      if (privateContent.current) privateContent.current.style.visibility = 'hidden';
    };
    const revalidateRestoredPage = (event: PageTransitionEvent) => {
      if (event.persisted) window.location.reload();
    };
    window.addEventListener('pagehide', hidePrivateContent);
    window.addEventListener('pageshow', revalidateRestoredPage);
    return () => {
      window.removeEventListener('pagehide', hidePrivateContent);
      window.removeEventListener('pageshow', revalidateRestoredPage);
    };
  }, []);
  async function action(path: string, method: 'POST' | 'PATCH' | 'DELETE', body?: unknown) {
    setBusy(true);
    setError('');
    try {
      await request(path, brandId, method, body);
      return await load();
    } catch (failure) {
      setError(ErrorText(failure));
      return false;
    } finally {
      setBusy(false);
    }
  }
  async function saveQuestion(text: string, intent: Intent) {
    const item = editing;
    const ok = await action(
      item ? `/vision/questions/candidates/${item.id}` : '/vision/questions/candidates',
      item ? 'PATCH' : 'POST',
      { text, intent },
    );
    if (ok) {
      setModal(null);
      setEditing(null);
      setNotice(item ? 'Вопрос сохранён.' : 'Вопрос добавлен.');
    }
  }
  async function importQuestions() {
    setBusy(true);
    setError('');
    try {
      const result = await request<{ added: number; skipped: number }>(
        '/vision/questions/import-profile',
        brandId,
        'POST',
        { intent: importIntent },
      );
      if (!(await load())) return;
      setNotice(`Добавлено: ${result.added}. Пропущено: ${result.skipped}.`);
      setModal(null);
    } catch (failure) {
      setError(ErrorText(failure));
    } finally {
      setBusy(false);
    }
  }
  const visible =
    data?.candidates.filter((item) => filter === 'all' || item.intent === filter) ?? [];
  const totalPages = Math.max(1, Math.ceil(visible.length / 8));
  const pageRows = visible.slice(
    (Math.min(page, totalPages) - 1) * 8,
    Math.min(page, totalPages) * 8,
  );
  const chosen =
    data?.candidates.filter((item) => item.selected && item.status !== 'disabled') ?? [];
  return (
    <CabinetLayout breadcrumbs={[{ label: 'Видение', href: '/vision' }, { label: 'Вопросы' }]}>
      <div ref={privateContent} className="space-y-5">
        <header>
          <h1 className="text-3xl font-bold">
            {screen === 'index'
              ? 'Вопросы, которые мы зададим нейросетям'
              : screen === 'approve'
                ? 'Утвердить набор вопросов'
                : screen === 'active'
                  ? 'Активный набор'
                  : 'История наборов'}
          </h1>
          <p className="mt-2 text-base text-(--color-ink-muted)">
            {screen === 'index'
              ? 'Подготовьте и выберите вопросы для будущих замеров.'
              : screen === 'approve'
                ? 'Проверьте вопросы и модели перед утверждением.'
                : screen === 'active'
                  ? 'Утверждённые вопросы для замеров.'
                  : 'Версии наборов и изменения между ними.'}
          </p>
        </header>
        <Tabs screen={screen} />
        <Alert message={error} />
        {notice && (
          <p role="status" className="rounded-lg bg-(--color-brand-primary-soft) p-4 text-sm">
            {notice}
          </p>
        )}
        {!data ? (
          <div className={card} role="status">
            {error ? (
              <button type="button" className={secondary} onClick={() => void load()}>
                Попробовать снова
              </button>
            ) : (
              <span>Загружаем вопросы…</span>
            )}
          </div>
        ) : (
          <>
            {screen === 'index' && (
              <>
                <div className="flex flex-wrap items-center justify-between gap-3">
                  <p className="text-sm text-(--color-ink-muted)">
                    Вопросов в активном наборе: {data.active_set?.size ?? '—'} · лимит по тарифу:{' '}
                    {limit(data.limits.questions)}
                  </p>
                  {data.can_edit && (
                    <div className="flex flex-wrap gap-2">
                      <Button
                        variant="secondary"
                        onClick={() => setModal('import')}
                        disabled={!data.has_profile || busy}
                      >
                        Добавить из анкеты
                      </Button>
                      <Button
                        onClick={() => {
                          setEditing(null);
                          setModal('add');
                        }}
                        disabled={busy}
                      >
                        Добавить свой
                      </Button>
                    </div>
                  )}
                </div>
                {data.generation.status === 'blocked' && (
                  <p className="rounded-lg bg-(--color-surface-muted) p-4 text-sm text-(--color-ink-muted)">
                    Автоматическое предложение вопросов пока недоступно. Добавьте свои вопросы или
                    перенесите их из анкеты.
                  </p>
                )}
                <div className="flex flex-wrap gap-2">
                  <button
                    className={secondary}
                    aria-pressed={filter === 'all'}
                    onClick={() => {
                      setFilter('all');
                      setPage(1);
                    }}
                  >
                    Все · {data.candidates.length}
                  </button>
                  {intentKeys.map((key) => (
                    <button
                      key={key}
                      className={secondary}
                      aria-pressed={filter === key}
                      onClick={() => {
                        setFilter(key);
                        setPage(1);
                      }}
                    >
                      {intents[key]} ·{' '}
                      {data.candidates.filter((item) => item.intent === key).length}
                    </button>
                  ))}
                </div>
                <section className={card} aria-label="Предложенные вопросы">
                  {visible.length === 0 ? (
                    <p className="py-8 text-center text-sm text-(--color-ink-muted)">
                      Пока нет вопросов в этом разделе.
                    </p>
                  ) : (
                    <>
                      <div className="flex flex-wrap items-center gap-3 border-b border-(--color-border) pb-3 text-sm">
                        <span>Выбрано для действий: {selected.length}</span>
                        {data.can_edit && (
                          <>
                            <Button
                              variant="secondary"
                              disabled={!selected.length || busy}
                              onClick={async () => {
                                for (const id of selected) {
                                  if (
                                    !(await action(`/vision/questions/candidates/${id}`, 'PATCH', {
                                      status: 'disabled',
                                      selected: false,
                                    }))
                                  )
                                    break;
                                }
                                setSelected([]);
                              }}
                            >
                              Отключить выбранные
                            </Button>
                            <button className={link} onClick={() => setSelected([])}>
                              Снять выделение
                            </button>
                          </>
                        )}
                      </div>
                      <ul>
                        {pageRows.map((item) => (
                          <li
                            key={item.id}
                            className="flex flex-wrap items-center gap-3 border-b border-(--color-border) py-4"
                          >
                            <label className="flex min-w-[220px] flex-1 cursor-pointer items-start gap-3 text-sm">
                              <input
                                type="checkbox"
                                checked={selected.includes(item.id)}
                                onChange={(event) =>
                                  setSelected(
                                    event.target.checked
                                      ? [...selected, item.id]
                                      : selected.filter((id) => id !== item.id),
                                  )
                                }
                              />
                              <span className="break-words">{item.text}</span>
                            </label>
                            <span className="rounded-full bg-(--color-brand-primary-soft) px-3 py-1 text-sm">
                              {intents[item.intent] ?? 'Без намерения'}
                            </span>
                            <span className="text-sm">
                              {item.status === 'disabled'
                                ? 'Отключён'
                                : item.status === 'active'
                                  ? 'Активен'
                                  : item.selected
                                    ? 'Выбран'
                                    : 'Предложен'}
                            </span>
                            <span className="text-sm text-(--color-ink-muted)">
                              {source(item.origin)}
                            </span>
                            {data.can_edit && (
                              <div className="flex flex-wrap gap-2">
                                <button
                                  className={secondary}
                                  disabled={busy}
                                  onClick={() =>
                                    void action(
                                      `/vision/questions/candidates/${item.id}`,
                                      'PATCH',
                                      item.status === 'disabled'
                                        ? { status: 'proposed' }
                                        : { selected: !item.selected },
                                    )
                                  }
                                >
                                  {item.status === 'disabled'
                                    ? 'Включить'
                                    : item.selected
                                      ? 'Исключить'
                                      : 'В набор'}
                                </button>
                                <button
                                  className={secondary}
                                  disabled={busy}
                                  onClick={() => {
                                    setEditing(item);
                                    setModal('edit');
                                  }}
                                >
                                  Изменить
                                </button>
                                {item.can_delete && (
                                  <button
                                    className={secondary}
                                    disabled={busy}
                                    onClick={() => {
                                      if (window.confirm('Удалить этот вопрос?'))
                                        void action(
                                          `/vision/questions/candidates/${item.id}`,
                                          'DELETE',
                                        );
                                    }}
                                  >
                                    Удалить
                                  </button>
                                )}
                              </div>
                            )}
                          </li>
                        ))}
                      </ul>
                      <div className="mt-4 flex items-center justify-between gap-2 text-sm">
                        <span>
                          Страница {Math.min(page, totalPages)} из {totalPages}
                        </span>
                        <div className="flex gap-2">
                          <button
                            className={secondary}
                            disabled={page <= 1}
                            onClick={() => setPage(page - 1)}
                          >
                            Назад
                          </button>
                          <button
                            className={secondary}
                            disabled={page >= totalPages}
                            onClick={() => setPage(page + 1)}
                          >
                            Вперёд
                          </button>
                        </div>
                      </div>
                    </>
                  )}
                </section>
                {data.can_edit && (
                  <div className="flex justify-end">
                    <Link href="/vision/questions/approve" className={secondary}>
                      Перейти к утверждению →
                    </Link>
                  </div>
                )}
              </>
            )}
            {screen === 'approve' && (
              <>
                <div className="grid items-start gap-4 lg:grid-cols-2">
                  <section className={card}>
                    <h2 className="text-xl font-bold">Выбрано {chosen.length} вопросов</h2>
                    <p className="mt-2 text-sm text-(--color-ink-muted)">
                      Лимит по тарифу: {limit(data.limits.questions)}
                    </p>
                    <h3 className="mt-5 font-semibold">Покрытие намерений</h3>
                    <ul className="mt-3 space-y-2">
                      {intentKeys.map((key) => (
                        <li key={key} className="flex justify-between gap-3 text-sm">
                          <span>{intents[key]}</span>
                          <strong>{chosen.filter((item) => item.intent === key).length}</strong>
                        </li>
                      ))}
                    </ul>
                    {intentKeys.some((key) => chosen.every((item) => item.intent !== key)) && (
                      <p className="mt-4 rounded-lg bg-amber-50 p-3 text-sm text-amber-900">
                        Не все намерения покрыты вопросами. Вы можете добавить вопросы.
                      </p>
                    )}
                  </section>
                  <Models models={data.models} selected={models} onChange={setModels} />
                </div>
                <section className={card}>
                  <h2 className="text-xl font-bold">Настройки замера</h2>
                  <p className="mt-2 text-sm text-(--color-ink-muted)">
                    Расписание и запуск замера будут доступны позже. Утверждение набора не запускает
                    замер.
                  </p>
                  <p className="mt-2 text-sm text-(--color-ink-muted)">
                    Лимит моделей: {limit(data.limits.models)}
                  </p>
                </section>
                {data.can_edit && (
                  <div className="flex flex-wrap gap-3">
                    <Button
                      disabled={!chosen.length || !Object.keys(models).length || busy}
                      loading={busy}
                      onClick={async () => {
                        if (
                          await action('/vision/questions/approve', 'POST', {
                            candidate_ids: chosen.map((item) => item.id),
                            models,
                            frequency: null,
                          })
                        )
                          window.location.assign('/vision/questions/active');
                      }}
                    >
                      Утвердить без запуска
                    </Button>
                    <Link href="/vision/questions" className={secondary}>
                      К вопросам
                    </Link>
                  </div>
                )}
              </>
            )}
            {screen === 'active' && (
              <>
                {data.active_set ? (
                  <SetDetails
                    value={data.active_set}
                    canEdit={data.can_edit}
                    busy={busy}
                    onCopy={async () => {
                      setBusy(true);
                      setError('');
                      try {
                        const result = await request<{ added: number; skipped: number }>(
                          '/vision/questions/copy-active',
                          brandId,
                          'POST',
                          {},
                        );
                        setNotice(`Скопировано: ${result.added}. Пропущено: ${result.skipped}.`);
                        if (await load()) window.location.assign('/vision/questions');
                      } catch (failure) {
                        setError(ErrorText(failure));
                      } finally {
                        setBusy(false);
                      }
                    }}
                  />
                ) : (
                  <section className={`${card} py-12 text-center`}>
                    <h2 className="text-xl font-semibold">Активного набора пока нет</h2>
                    <p className="mt-3 text-sm text-(--color-ink-muted)">
                      После утверждения здесь появятся вопросы для замеров.
                    </p>
                    <Link href="/vision/questions" className={`${secondary} mt-4`}>
                      Перейти к вопросам
                    </Link>
                  </section>
                )}
              </>
            )}
            {screen === 'history' && (
              <section className={card}>
                {!data.history.length ? (
                  <div className="py-12 text-center">
                    <h2 className="text-xl font-semibold">Истории наборов пока нет</h2>
                    <p className="mt-3 text-sm text-(--color-ink-muted)">
                      После утверждения первого набора здесь появятся версии.
                    </p>
                    <Link className={`${secondary} mt-4`} href="/vision/questions">
                      Перейти к вопросам
                    </Link>
                  </div>
                ) : (
                  <div className="space-y-4">
                    {data.history.map((item, index) => {
                      const previous = data.history[index + 1];
                      const added = previous ? difference(item.questions, previous.questions) : [];
                      const removed = previous
                        ? difference(previous.questions, item.questions)
                        : [];
                      return (
                        <article
                          key={item.id}
                          className="rounded-lg border border-(--color-border) p-4"
                        >
                          <div className="flex flex-wrap justify-between gap-3">
                            <h2 className="font-semibold">
                              Версия {item.version} {item.active && '· Активный'}
                            </h2>
                            <span className="text-sm text-(--color-ink-muted)">
                              {dateUtc(item.created_at)}
                            </span>
                          </div>
                          <p className="mt-2 text-sm">
                            Вопросов: {item.size} · моделей:{' '}
                            {Object.keys(item.providers ?? {}).length}
                          </p>
                          <p className="mt-1 text-sm text-(--color-ink-muted)">
                            {previous
                              ? `Добавлено: ${added.length} · удалено: ${removed.length}`
                              : 'Первый набор'}
                          </p>
                          {previous && (
                            <button
                              type="button"
                              className={link}
                              aria-expanded={compare === item.id}
                              onClick={() => setCompare(compare === item.id ? null : item.id)}
                            >
                              Сравнить с предыдущим
                            </button>
                          )}
                          {compare === item.id && previous && (
                            <div className="mt-3 grid gap-4 md:grid-cols-2">
                              <div>
                                <h3 className="font-semibold">Добавлено</h3>
                                {added.length ? (
                                  <ul className="list-inside list-disc text-sm">
                                    {added.map((q) => (
                                      <li key={q.id}>{q.text}</li>
                                    ))}
                                  </ul>
                                ) : (
                                  <p className="text-sm">Нет</p>
                                )}
                              </div>
                              <div>
                                <h3 className="font-semibold">Удалено</h3>
                                {removed.length ? (
                                  <ul className="list-inside list-disc text-sm">
                                    {removed.map((q) => (
                                      <li key={q.id}>{q.text}</li>
                                    ))}
                                  </ul>
                                ) : (
                                  <p className="text-sm">Нет</p>
                                )}
                              </div>
                            </div>
                          )}
                        </article>
                      );
                    })}
                  </div>
                )}
              </section>
            )}
          </>
        )}
        {(modal === 'add' || modal === 'edit') && (
          <CandidateForm
            key={editing?.id ?? 'new'}
            open
            onClose={() => {
              setModal(null);
              setEditing(null);
            }}
            onSave={(text, intent) => void saveQuestion(text, intent)}
            initial={editing}
            all={data?.candidates ?? []}
            busy={busy}
            error={error}
          />
        )}
        <Modal
          open={modal === 'import'}
          title="Добавить вопросы из анкеты"
          onClose={() => setModal(null)}
        >
          <p className="text-sm">
            Перенесём вопросы, которые вы сами записали в профиле. Повторный импорт не создаёт
            дубликаты. ИИ не вызывается.
          </p>
          <fieldset className="mt-4">
            <legend className="font-semibold">Намерение для импортируемых вопросов</legend>
            <div className="mt-2 space-y-2">
              {intentKeys.map((key) => (
                <label key={key} className="flex min-h-9 cursor-pointer items-center gap-2">
                  <input
                    type="radio"
                    name="import-intent"
                    checked={importIntent === key}
                    onChange={() => setImportIntent(key)}
                  />
                  {intents[key]}
                </label>
              ))}
            </div>
          </fieldset>
          <div className="mt-5 flex justify-end gap-2">
            <Button variant="secondary" onClick={() => setModal(null)}>
              Отмена
            </Button>
            <Button loading={busy} onClick={() => void importQuestions()}>
              Добавить
            </Button>
          </div>
        </Modal>
      </div>
    </CabinetLayout>
  );
}

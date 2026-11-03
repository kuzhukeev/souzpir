import { useCallback, useEffect, useRef, useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import CabinetLayout from '@/layouts/CabinetLayout';
import { Button } from '@/components/ui/forms';
import { Modal } from '@/components/ui/overlay';
import { intents, dateUtc, limit } from '@/features/Vision/Questions/copy';
import { request } from '@/features/Vision/Questions/request';
import type {
  Candidate,
  Data as BaseData,
  Intent,
  Question,
} from '@/types/Vision/Questions';
import { Alert } from '@/features/Vision/Questions/Alert';
import { CandidateForm } from '@/features/Vision/Questions/CandidateForm';
import { Models } from '@/features/Vision/Questions/Models';
import { SetDetails } from '@/features/Vision/Questions/SetDetails';
import { Tabs } from '@/features/Vision/Questions/Tabs';
import { card, link, secondary } from '@/features/Vision/Questions/styles';

type Data = Omit<BaseData, 'history'> & { history: (BaseData['history'][number] & { measurements?: { id: string; number: number; status: string; created_at: string }[] })[] };
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
  const { brand, measurementBrandRevision, can } = usePage<{ brand: { id: string } | null; measurementBrandRevision?: string; can?: Record<string, boolean> }>().props;
  return <WorkspaceBody key={brand?.id ?? 'none'} screen={screen} brandId={brand?.id ?? ''} revision={measurementBrandRevision ?? ''} canRun={!!can?.['vision.measure.run']} />;
}
function WorkspaceBody({ screen, brandId, revision, canRun }: { screen: Screen; brandId: string; revision: string; canRun: boolean }) {
  const privateContent = useRef<HTMLDivElement>(null);
  const [data, setData] = useState<Data | null>(null);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [busy, setBusy] = useState(false);
  const [runs, setRuns] = useState(1);
  const [frequency, setFrequency] = useState('');
  const [scheduleEnabled, setScheduleEnabled] = useState(false);
  const [weekday, setWeekday] = useState(1);
  const [localTime, setLocalTime] = useState('09:00');
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
          setFrequency(result.active_set?.frequency ?? '');
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
                    Вопросов в активном наборе: {data.active_set?.size ?? '—'} · слотов по тарифу:{' '}
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
                      <div role="table" aria-label="Вопросы" className="mt-3">
                        <div role="row" className="hidden gap-3 border-b border-(--color-border) py-3 text-sm font-semibold lg:grid lg:grid-cols-[minmax(220px,2fr)_1fr_1fr_1fr_2fr]"><span role="columnheader">Вопрос</span><span role="columnheader">Намерение</span><span role="columnheader">Статус</span><span role="columnheader">Источник</span><span role="columnheader">Действия</span></div>
                        {pageRows.map((item) => (
                          <div role="row" key={item.id} className="flex flex-wrap items-center gap-3 border-b border-(--color-border) py-4 lg:grid lg:grid-cols-[minmax(220px,2fr)_1fr_1fr_1fr_2fr]">
                            <label role="cell" className="flex min-w-[220px] flex-1 cursor-pointer items-start gap-3 text-sm">
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
                            <span role="cell" className="rounded-full bg-(--color-brand-primary-soft) px-3 py-1 text-sm">
                              {intents[item.intent] ?? 'Без намерения'}
                            </span>
                            <span role="cell" className="text-sm">
                              {item.status === 'disabled'
                                ? 'Отключён'
                                : item.status === 'active'
                                  ? 'Активен'
                                  : item.selected
                                    ? 'Выбран'
                                    : 'Предложен'}
                            </span>
                            <span role="cell" className="text-sm text-(--color-ink-muted)">
                              {source(item.origin)}
                            </span>
                            {data.can_edit && (
                              <div role="cell" className="flex flex-wrap gap-2">
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
                                <details><summary className="cursor-pointer rounded-lg border border-(--color-border) px-3 py-2" aria-label="Действия с вопросом">⋯</summary><div className="mt-2 flex flex-wrap gap-2">
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
                              </div></details></div>
                            )}
                          </div>
                        ))}
                      </div>
                      <div className="mt-4 flex items-center justify-between gap-2 text-sm">
                        <span>
                          {(Math.min(page, totalPages) - 1) * 8 + 1}–{Math.min(Math.min(page, totalPages) * 8, visible.length)} из {visible.length} · Страница {Math.min(page, totalPages)} из {totalPages}
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
                    <h2 className="text-xl font-bold">Выбрано вопросов: {chosen.length}</h2>
                    <p className="mt-2 text-sm text-(--color-ink-muted)">
                      Лимит по тарифу: {limit(data.limits.questions)}
                    </p>
                    <h3 className="mt-5 font-semibold">Покрытие намерений</h3>
                    <ul className="mt-3 space-y-2">
                      {intentKeys.map((key) => (
                        <li key={key} className="text-sm"><div className="flex justify-between gap-3"><span>{intents[key]}</span><strong>{chosen.filter((item) => item.intent === key).length}</strong></div><div className="mt-1 h-2 overflow-hidden rounded-full bg-(--color-border)" aria-hidden="true"><div className="h-full rounded-full bg-(--color-brand-primary)" style={{ width: `${chosen.length ? chosen.filter((item) => item.intent === key).length / chosen.length * 100 : 0}%` }} /></div></li>
                      ))}
                    </ul>
                    {intentKeys.some((key) => chosen.every((item) => item.intent !== key)) && (
                      <p className="mt-4 rounded-lg bg-amber-50 p-3 text-sm text-amber-900">
                        Не все намерения покрыты вопросами. Вы можете добавить вопросы.
                      </p>
                    )}
                  </section>
                  <Models models={data.models} selected={models} onChange={setModels} limit={data.limits.models} />
                </div>
                <section className={card}>
                  <h2 className="text-xl font-bold">Настройки первого замера</h2>
                  <label className="mt-3 flex items-center gap-3 text-sm"><input type="checkbox" checked={scheduleEnabled} disabled={!canRun} onChange={event => { setScheduleEnabled(event.target.checked); if (event.target.checked && !frequency) setFrequency('weekly'); }} />Включить автоматическое расписание</label>
                  <div className="mt-3 flex flex-wrap gap-3">
                    <label className="grid gap-1 text-sm">Частота<select aria-label="Частота" value={frequency} onChange={event=>setFrequency(event.target.value)} className="min-h-11 cursor-pointer rounded-lg border border-(--color-border) bg-(--color-surface) px-3"><option value="" disabled={scheduleEnabled}>Вручную</option><option value="weekly">Еженедельно</option><option value="biweekly">Раз в две недели</option><option value="monthly">Ежемесячно</option></select></label>
                    {scheduleEnabled && <><label className="grid gap-1 text-sm">День недели<select aria-label="День недели" value={weekday} onChange={event => setWeekday(Number(event.target.value))} className="min-h-11 cursor-pointer rounded-lg border border-(--color-border) bg-(--color-surface) px-3">{['Понедельник', 'Вторник', 'Среда', 'Четверг', 'Пятница', 'Суббота', 'Воскресенье'].map((day, index) => <option key={day} value={index + 1}>{day}</option>)}</select></label><label className="grid gap-1 text-sm">Время бренда<input aria-label="Время бренда" type="time" value={localTime} onChange={event => setLocalTime(event.target.value)} className="min-h-11 rounded-lg border border-(--color-border) bg-(--color-surface) px-3" /></label></>}
                  </div>
                  <p className="mt-2 text-sm text-(--color-ink-muted)">{scheduleEnabled ? 'Расписание включится после утверждения набора.' : 'Частота сохраняется в наборе; автоматический запуск выключен.'}</p>
                  <p className="mt-2 text-sm">План ответов: {chosen.length} × {Object.keys(models).length} × {runs} = {chosen.length * Object.keys(models).length * runs} (вопросы × модели × прогоны)</p><Link href="/vision/questions" className="mt-2 inline-flex min-h-11 cursor-pointer items-center text-sm font-semibold text-(--color-link)">Добавить вопросы</Link>
                  <label className="mt-3 block text-sm">Количество прогонов<select className="ml-3 min-h-11 cursor-pointer rounded-lg border border-(--color-border) bg-(--color-surface) px-3" value={runs} onChange={e=>setRuns(Number(e.target.value))}>{[1,2,3].map(n=><option key={n} value={n}>{n}</option>)}</select></label>
                  <p className="mt-2 text-sm">План: {chosen.length} вопросов × {Object.keys(models).length} моделей × {runs} = {chosen.length * Object.keys(models).length * runs} ответов. Стоимость в слотах определяется тарифом.</p>
                  <Link href="/vision/runs" className={link}>Настроить замеры</Link>
                  <p className="mt-2 text-sm text-(--color-ink-muted)">
                    Лимит моделей: {limit(data.limits.models)}
                  </p>
                </section>
                {data.can_edit && (
                  <div className="flex flex-wrap gap-3">
                    <Button disabled={!chosen.length || !Object.keys(models).length || busy || !canRun || !revision || (scheduleEnabled && (!frequency || !localTime))} loading={busy} onClick={async () => {
                      if (await action('/vision/questions/approve', 'POST', { candidate_ids: chosen.map((item) => item.id), models, frequency: frequency || null, schedule: scheduleEnabled ? { enabled: true, weekday, local_time: localTime } : null })) {
                        router.post('/vision/runs', { runs }, { headers: { 'X-Brand-Id': brandId, 'X-Brand-Revision': revision }, onStart: () => setBusy(true), onFinish: () => setBusy(false), onError: () => setError('Набор утверждён, но замер не запущен. Проверьте настройки замера.'), onHttpException: () => { setError('Набор утверждён, но замер не запущен. Проверьте доступ и настройки.'); return false; }, onNetworkError: () => { setError('Набор утверждён, результат запуска неизвестен. Проверьте список замеров.'); return false; } });
                      }
                    }}>Утвердить и запустить</Button>
                    <Button
                      disabled={!chosen.length || !Object.keys(models).length || busy || (scheduleEnabled && (!frequency || !localTime))}
                      loading={busy}
                      onClick={async () => {
                        if (
                          await action('/vision/questions/approve', 'POST', {
                            candidate_ids: chosen.map((item) => item.id),
                            models,
                            frequency: frequency || null,
                            schedule: scheduleEnabled ? { enabled: true, weekday, local_time: localTime } : null,
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
                  <div className="overflow-x-auto">
                    <table className="w-full min-w-[850px] text-left text-sm">
                      <thead><tr className="border-b border-(--color-border)"><th className="p-3">Версия</th><th className="p-3">Дата</th><th className="p-3">Автор</th><th className="p-3">Вопросы</th><th className="p-3">Модели</th><th className="p-3">Изменения</th><th className="p-3">Замеры</th><th className="p-3">Действия</th></tr></thead>
                      {data.history.map((item, index) => {
                        const previous = data.history[index + 1];
                        const added = previous ? difference(item.questions, previous.questions) : [];
                        const removed = previous ? difference(previous.questions, item.questions) : [];
                        return <tbody key={item.id}>
                          <tr className="border-b border-(--color-border)">
                            <td className="p-3 font-semibold">Версия {item.version} {item.active && '· Активный'}</td>
                            <td className="p-3">{dateUtc(item.created_at)}</td><td className="p-3">{item.approver_name ?? 'Не указан'}</td>
                            <td className="p-3">{item.size}</td><td className="p-3">{Object.keys(item.providers ?? {}).length}</td>
                            <td className="p-3">{previous ? `+${added.length} / −${removed.length}` : 'Первый набор'}</td>
                            <td className="p-3">{item.measurements?.length ? item.measurements.map((measurement) => `№ ${measurement.number}`).join(', ') : 'Нет'}</td>
                            <td className="p-3"><div className="flex items-center gap-3">{previous && <button type="button" className={link} aria-expanded={compare === item.id} onClick={() => setCompare(compare === item.id ? null : item.id)}>Сравнить с предыдущим</button>}<details><summary className="inline-flex cursor-pointer rounded-lg border border-(--color-border) px-3 py-2" aria-label={`Действия с версией ${item.version}`}>⋯</summary><div className="mt-2">{item.measurements?.length ? <Link className={link} href="/vision/runs">Посмотреть замеры</Link> : <span className="text-(--color-ink-muted)">Замеров нет</span>}</div></details></div></td>
                          </tr>
                          {compare === item.id && previous && <tr><td colSpan={8} className="p-3"><div className="grid gap-4 md:grid-cols-2"><div><h3 className="font-semibold">Добавлено</h3>{added.length ? <ul className="list-inside list-disc">{added.map((q) => <li key={q.id}>{q.text}</li>)}</ul> : <p>Нет</p>}</div><div><h3 className="font-semibold">Удалено</h3>{removed.length ? <ul className="list-inside list-disc">{removed.map((q) => <li key={q.id}>{q.text}</li>)}</ul> : <p>Нет</p>}</div></div></td></tr>}
                        </tbody>;
                      })}
                    </table>
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

import { Head, router, usePage } from '@inertiajs/react';
import { Fragment, useEffect, useRef, useState } from 'react';
import { BarChart3 } from 'lucide-react';
import * as Dialog from '@radix-ui/react-dialog';
import CabinetLayout from '@/layouts/CabinetLayout';
import ConfirmationProgress from '@/features/Vision/Measurements/ConfirmationProgress';
import RunProgress from '@/features/Vision/Measurements/RunProgress';
import {
  profileContextIsStable,
  subscribeProfileContext,
} from '@/features/Shell/Profile/contextFence';

type Row = {
  id: string;
  status: string;
  confirmed?: boolean;
  launch_source?: string | null;
  pending_clarifications?: number | null;
  estimated_minutes?: number | null;
  visibility?: number | null;
  visibility_delta?: number | null;
  visibility_before?: number | null;
  mentions?: number | null;
  created_at: string;
  started_at?: string | null;
  finished_at?: string | null;
  answers?: { question_n: number; provider: string; requested_model: string; run_no: number; status: string; answer_text: string | null; error_code: string | null }[];
};
type Schedule = { enabled: boolean; frequency: string | null; weekday: number | null; local_time: string | null; timezone: string; next_run_at: string | null; models: string[]; available_models: string[] };
type Listing = { revision: string; data: Row[]; page: number; last_page: number; total: number; schedule?: Schedule; plan?: { questions: number; models: number } | null };
const button =
  'min-h-11 cursor-pointer rounded-lg border border-(--color-border) px-4 text-sm font-semibold text-(--color-link) focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--color-focus) disabled:cursor-not-allowed disabled:opacity-50';
const isPageVisible = () => document.visibilityState !== 'hidden';
const stateLabel: Record<string, string> = { pending: 'Ожидает', in_progress: 'Выполняется', running: 'Выполняется', ok: 'Готов', completed: 'Завершён', partial: 'Частично готов', failed: 'Ошибка', uncertain: 'Неизвестный исход' };
const errorLabel: Record<string, string> = { provider_rejected: 'Провайдер отклонил запрос', provider_uncertain: 'Исход запроса неизвестен', knowledge_unavailable: 'Данные бренда недоступны', empty_response: 'Пустой ответ' };
function date(value: string | null): string {
  if (!value) return '—';
  const parsed = new Date(value);
  return Number.isNaN(parsed.getTime())
    ? '—'
    : new Intl.DateTimeFormat('ru-RU', { dateStyle: 'medium', timeStyle: 'short' }).format(parsed);
}

function AnswerPanel({ answers }: { answers: NonNullable<Row['answers']> }) {
  const [provider, setProvider] = useState('');
  const [status, setStatus] = useState('');
  const filtered = answers.filter(answer => (!provider || answer.provider === provider) && (!status || answer.status === status));
  return <><div className="mt-3 flex flex-wrap gap-3"><label>Модель<select value={provider} onChange={event => setProvider(event.target.value)} className="ml-2 min-h-11 rounded-lg border border-(--color-border) bg-(--color-surface) px-3"><option value="">Все</option>{Array.from(new Set(answers.map(answer => answer.provider))).map(value => <option key={value} value={value}>{value}</option>)}</select></label><label>Статус<select value={status} onChange={event => setStatus(event.target.value)} className="ml-2 min-h-11 rounded-lg border border-(--color-border) bg-(--color-surface) px-3"><option value="">Все</option>{Array.from(new Set(answers.map(answer => answer.status))).map(value => <option key={value} value={value}>{stateLabel[value] ?? value}</option>)}</select></label></div>{filtered.length === 0 ? <p className="mt-3">Ответов по фильтрам нет.</p> : <ul className="mt-3 grid gap-2">{filtered.map(answer => <li key={`${answer.question_n}-${answer.provider}-${answer.run_no}`} className="rounded-lg bg-(--color-surface-muted) p-3"><p className="font-medium">Вопрос {answer.question_n} · {answer.provider} · повтор {answer.run_no}</p><p className="mt-1 text-(--color-ink-muted)">{stateLabel[answer.status] ?? answer.status} · {answer.requested_model}</p>{answer.error_code && <p className="mt-2 text-(--color-danger-ink)">Ошибка: {errorLabel[answer.error_code] ?? answer.error_code}</p>}{answer.answer_text && <p className="mt-2 whitespace-pre-wrap">{answer.answer_text}</p>}{answer.answer_text && <Dialog.Root><Dialog.Trigger className={`${button} mt-2`}>Открыть ответ</Dialog.Trigger><Dialog.Portal><Dialog.Overlay className="fixed inset-0 z-40 bg-black/50" /><Dialog.Content className="fixed inset-y-0 right-0 z-50 w-full max-w-xl overflow-y-auto bg-(--color-surface) p-6 text-(--color-ink)"><Dialog.Title className="text-xl font-bold">Ответ</Dialog.Title><Dialog.Description className="mt-2 text-sm">Вопрос {answer.question_n} · {answer.provider} · повтор {answer.run_no}</Dialog.Description><p className="mt-4 whitespace-pre-wrap">{answer.answer_text}</p><Dialog.Close className={`${button} mt-5`}>Закрыть</Dialog.Close></Dialog.Content></Dialog.Portal></Dialog.Root>}</li>)}</ul>}</>;
}

function ScheduleEditor({ schedule, brandId, revision, canEdit, refreshed }: { schedule: Schedule; brandId: string; revision: string; canEdit: boolean; refreshed: () => void }) {
  const [enabled, setEnabled] = useState(schedule.enabled);
  const [frequency, setFrequency] = useState(schedule.frequency ?? 'weekly');
  const [weekday, setWeekday] = useState(schedule.weekday ?? 1);
  const [localTime, setLocalTime] = useState(schedule.local_time ?? '09:00');
  const [models, setModels] = useState(schedule.models.length ? schedule.models : schedule.available_models);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const save = () => {
    setError('');
    router.put('/vision/runs/schedule', { enabled, frequency, weekday, local_time: localTime, models }, {
      headers: { 'X-Brand-Id': brandId, 'X-Brand-Revision': revision },
      onStart: () => setSaving(true),
      onFinish: () => setSaving(false),
      onSuccess: refreshed,
      onError: (errors) => setError(Object.values(errors)[0] ?? 'Не удалось сохранить расписание.'),
      onNetworkError: () => { setError('Связь прервалась. Обновите страницу и проверьте расписание.'); return false; },
    });
  };
  return <section aria-labelledby="schedule-title" className="mb-5 rounded-2xl border border-(--color-border) bg-(--color-surface) p-4 shadow-(--shadow-card) sm:p-6">
    <h2 id="schedule-title" className="text-xl font-bold">Следующий замер</h2>
    <p className="mt-2 text-sm text-(--color-ink-muted)">{schedule.enabled ? `${date(schedule.next_run_at)} · ${schedule.frequency === 'weekly' ? 'еженедельно' : schedule.frequency === 'biweekly' ? 'раз в две недели' : 'ежемесячно'} · ${schedule.models.length} ${schedule.models.length === 1 ? 'модель' : schedule.models.length < 5 ? 'модели' : 'моделей'}` : 'Автозапуск выключен'}</p>
    <div className="mt-4 grid gap-3 sm:grid-cols-3">
      <label className="text-sm font-semibold">Частота<select value={frequency} onChange={(event) => setFrequency(event.target.value)} disabled={!canEdit} className="mt-1 block w-full cursor-pointer rounded-lg border border-(--color-border) bg-(--color-surface) p-2 text-sm"><option value="weekly">Еженедельно</option><option value="biweekly">Раз в две недели</option><option value="monthly">Ежемесячно</option></select></label>
      <label className="text-sm font-semibold">День недели<select value={weekday} onChange={(event) => setWeekday(Number(event.target.value))} disabled={!canEdit} className="mt-1 block w-full cursor-pointer rounded-lg border border-(--color-border) bg-(--color-surface) p-2 text-sm">{['Понедельник', 'Вторник', 'Среда', 'Четверг', 'Пятница', 'Суббота', 'Воскресенье'].map((day, index) => <option key={day} value={index + 1}>{day}</option>)}</select></label>
      <label className="text-sm font-semibold">Время ({schedule.timezone})<input type="time" value={localTime} onChange={(event) => setLocalTime(event.target.value)} disabled={!canEdit} className="mt-1 block w-full rounded-lg border border-(--color-border) bg-(--color-surface) p-2 text-sm" /></label>
    </div>
    <fieldset className="mt-4"><legend className="text-sm font-semibold">Модели</legend><div className="mt-2 flex flex-wrap gap-3">{schedule.available_models.map((model) => <label key={model} className="flex cursor-pointer items-center gap-2 text-sm"><input type="checkbox" checked={models.includes(model)} disabled={!canEdit} onChange={(event) => setModels((current) => event.target.checked ? [...current, model] : current.filter((item) => item !== model))} />{model}</label>)}</div></fieldset>
    <div className="mt-4 flex flex-wrap items-center gap-4"><label className="flex cursor-pointer items-center gap-2 text-sm font-semibold"><input type="checkbox" checked={enabled} onChange={(event) => setEnabled(event.target.checked)} disabled={!canEdit} />Автозапуск</label><button type="button" className={button} disabled={!canEdit || saving} onClick={save}>{saving ? 'Сохраняем…' : 'Сохранить расписание'}</button></div>
    {error && <p role="alert" className="mt-3 text-sm text-(--color-danger-ink)">{error}</p>}
  </section>;
}

export default function Index({ measurementBrandRevision, status }: { measurementBrandRevision: string; status?: string | null }) {
  const { brand, can } = usePage<{ brand: { id: string } | null; can: Record<string, boolean> }>().props;
  const headingRef = useRef<HTMLHeadingElement>(null);
  const brandId = brand?.id ?? '';
  const contextKey = JSON.stringify([brandId, measurementBrandRevision]);
  const [navigation, setNavigation] = useState({ contextKey, page: 1, epoch: 0 });
  // A returning context must not reuse data from an earlier visit.
  if (navigation.contextKey !== contextKey) {
    setNavigation({ contextKey, page: 1, epoch: navigation.epoch + 1 });
  }
  const page = navigation.contextKey === contextKey ? navigation.page : 1;
  const [attempt, setAttempt] = useState(0);
  const [runs, setRuns] = useState(1);
  const [month, setMonth] = useState('');
  const [launchOpen, setLaunchOpen] = useState(false);
  const [starting, setStarting] = useState(false);
  const [startError, setStartError] = useState('');
  const [acting, setActing] = useState('');
  const act = (id: string, action: 'next' | 'repeat' | 'confirm') => {
    setStartError('');
    setActing(`${id}-${action}`);
    router.post(`/vision/runs/${id}/${action}`, {}, {
      headers: { 'X-Brand-Id': brandId, 'X-Brand-Revision': measurementBrandRevision },
      onSuccess: () => setAttempt((value) => value + 1),
      onError: (errors) => setStartError(Object.values(errors)[0] ?? 'Не удалось выполнить действие.'),
      onFinish: () => setActing(''),
      onNetworkError: () => { setStartError('Связь прервалась. Обновите замеры перед повтором.'); return false; },
    });
  };
  const start = () => {
    setStartError('');
    router.post('/vision/runs', { runs }, {
      headers: { 'X-Brand-Id': brandId, 'X-Brand-Revision': measurementBrandRevision },
      onStart: () => setStarting(true),
      onFinish: () => setStarting(false),
      onError: (errors) => setStartError(Object.values(errors)[0] ?? 'Не удалось начать замер.'),
      onNetworkError: () => { setStartError('Не удалось связаться с сервером. Проверьте состояние замера перед повтором.'); return false; },
      onHttpException: () => { setStartError('Не удалось получить результат. Проверьте состояние замера перед повтором.'); return false; },
    });
  };
  const requestKey = JSON.stringify([
    brandId,
    measurementBrandRevision,
    page,
    month,
    attempt,
    navigation.epoch,
  ]);
  const [state, setState] = useState<{
    key: string;
    data: Listing | null;
    error: string;
    loading: boolean;
  }>({
    key: requestKey,
    data: null,
    error: '',
    loading: true,
  });
  const view = state.key === requestKey ? state : { data: null, error: '', loading: true };
  const hasActiveRun = Boolean(view.data?.data.some(row => ['pending', 'running', 'in_progress'].includes(row.status)));
  useEffect(() => {
    if (!hasActiveRun) return;
    const timer = window.setInterval(() => { if (isPageVisible() && profileContextIsStable()) setAttempt(value => value + 1); }, 15000);
    return () => window.clearInterval(timer);
  }, [hasActiveRun]);

  useEffect(() => {
    let active = true;
    const controller = new AbortController();
    const timeout = window.setTimeout(() => controller.abort(), 10000);
    const hide = () =>
      document.querySelectorAll<HTMLElement>('[data-private-profile]').forEach((element) => {
        element.hidden = true;
      });
    const invalidate = () => {
      hide();
      active = false;
      controller.abort();
      setState({
        key: requestKey,
        data: null,
        loading: false,
        error: 'Контекст бренда изменился. Обновите страницу.',
      });
    };
    const visibility = () => {
      if (document.visibilityState === 'hidden') invalidate();
    };
    const pageShow = (event: PageTransitionEvent) => {
      if (event.persisted) window.location.reload();
    };
    const unsubscribe = subscribeProfileContext(invalidate);
    document.addEventListener('visibilitychange', visibility);
    window.addEventListener('pagehide', invalidate);
    window.addEventListener('pageshow', pageShow);
    const headers = {
      Accept: 'application/json',
      'X-Brand-Id': brandId,
      'X-Brand-Revision': measurementBrandRevision,
    };
    const request = async () => {
      if (
        !brandId ||
        !/^[a-f0-9]{64}$/.test(measurementBrandRevision) ||
        !profileContextIsStable() ||
        !isPageVisible()
      )
        throw new Error();
      const response = await fetch(`/vision/runs/data?page=${page}&view=details${month ? `&month=${encodeURIComponent(month)}` : ''}`, {
        credentials: 'same-origin',
        cache: 'no-store',
        headers,
        signal: controller.signal,
      });
      if (!response.ok) throw new Error();
      const data = (await response.json()) as Listing;
      if (data.revision !== measurementBrandRevision) throw new Error();
      // A response can have been generated before a brand switch and arrive afterwards.
      const verification = await fetch('/vision/runs/revision', {
        credentials: 'same-origin',
        cache: 'no-store',
        headers,
        signal: controller.signal,
      });
      if (
        !verification.ok ||
        (await verification.json()).revision !== measurementBrandRevision ||
        !profileContextIsStable() ||
        !isPageVisible()
      )
        throw new Error();
      if (active) setState({ key: requestKey, data, error: '', loading: false });
    };
    void request()
      .catch(() => {
        if (active)
          setState({
            key: requestKey,
            data: null,
            loading: false,
            error: 'Не удалось загрузить замеры. Проверьте доступ и обновите страницу.',
          });
      })
      .finally(() => window.clearTimeout(timeout));
    return () => {
      active = false;
      window.clearTimeout(timeout);
      controller.abort();
      unsubscribe();
      document.removeEventListener('visibilitychange', visibility);
      window.removeEventListener('pagehide', invalidate);
      window.removeEventListener('pageshow', pageShow);
    };
  }, [brandId, measurementBrandRevision, page, month, attempt, requestKey]);

  return (
    <CabinetLayout breadcrumbs={[{ label: 'Видение', href: '/vision' }, { label: 'Замеры' }]}>
      <Head title="Замеры" />
      <div className="mx-auto max-w-[1500px]">
        <header className="mb-6 flex flex-wrap items-end justify-between gap-4">
          <div>
            <h1 className="text-3xl font-bold">Замеры</h1><a href="/vision/clarifications" className="mt-2 inline-flex min-h-11 cursor-pointer items-center text-sm font-semibold text-(--color-link)">Уточнения</a>
            <p className="mt-2 text-base text-(--color-ink-muted)">
              История проверок видимости бренда
            </p>
          </div>
          <div className="flex flex-wrap items-end gap-2">
            <fieldset><legend className="text-sm font-semibold">Повторов</legend><div className="mt-1 flex gap-1">{[1,2,3].map(n=><button key={n} type="button" aria-pressed={runs===n} onClick={()=>setRuns(n)} className={`${button} ${runs===n?'bg-(--color-surface-muted)':''}`}>{n}</button>)}</div></fieldset>
            <button type="button" className={button} onClick={() => setLaunchOpen(true)}>Параметры замера</button>
            <button type="button" className={button} disabled={starting || !can?.['vision.measure.run'] || !brandId || !profileContextIsStable()} onClick={start}>Начать замер</button>
          </div>
        </header>
        <Dialog.Root open={launchOpen} onOpenChange={setLaunchOpen}><Dialog.Portal><Dialog.Overlay className="fixed inset-0 z-40 bg-black/50" /><Dialog.Content className="fixed left-1/2 top-1/2 z-50 w-[min(95vw,32rem)] -translate-x-1/2 -translate-y-1/2 rounded-2xl bg-(--color-surface) p-6 text-(--color-ink) shadow-xl"><Dialog.Title className="text-xl font-bold">Запуск замера</Dialog.Title><Dialog.Description className="mt-2 text-sm">План строится по утверждённым вопросам и моделям текущего бренда.</Dialog.Description>{Boolean(view.data?.schedule?.available_models.length) && <p className="mt-3 text-sm">Модели: {view.data?.schedule?.available_models.join(', ')}</p>}<label className="mt-4 block text-sm">Повторов<input type="number" min={1} max={3} value={runs} onChange={event => setRuns(Number(event.target.value))} className="mt-2 block w-24 rounded-lg border border-(--color-border) bg-(--color-surface) p-2" /></label><div role="group" aria-label="Число прогонов" className="mt-3 flex gap-1">{[1,2,3].map(n=><button key={n} type="button" aria-pressed={runs===n} onClick={()=>setRuns(n)} className={`${button} ${runs===n?'bg-(--color-surface-muted)':''}`}>{n}</button>)}</div><p className="mt-4 text-sm">{view.data?.plan ? `Вопросы: ${view.data.plan.questions} · Модели: ${view.data.plan.models} · Повторы: ${runs} · Всего слотов: ${view.data.plan.questions * view.data.plan.models * runs}` : 'Утверждённый набор вопросов недоступен.'}</p><p className="mt-2 text-sm text-(--color-ink-muted)">Оценка времени: 20–40 минут.</p><a href="/vision/questions" className="mt-2 inline-flex min-h-11 cursor-pointer items-center text-sm text-(--color-link)">Открыть набор вопросов →</a><div className="mt-4 flex gap-3"><button type="button" className={button} disabled={starting || !can?.['vision.measure.run'] || !brandId || !profileContextIsStable()} onClick={() => { setLaunchOpen(false); start(); }}>Начать замер</button><Dialog.Close className={button}>Отмена</Dialog.Close></div></Dialog.Content></Dialog.Portal></Dialog.Root>
        <p className="mb-4 text-sm text-(--color-ink-muted)">Запуск создаёт план по утверждённым вопросам бренда. При включённой очереди слоты обрабатываются автоматически.</p>
        {status && <p role="status" className="mb-4 rounded-lg bg-(--color-success-soft) p-3 text-sm text-(--color-success-ink)">{status}</p>}
        {startError && <p role="alert" className="mb-4 rounded-lg bg-(--color-danger-soft) p-3 text-sm text-(--color-danger-ink)">{startError}</p>}
        {view.data?.schedule && view.data.revision === measurementBrandRevision && <ScheduleEditor key={JSON.stringify([contextKey, view.data.schedule])} schedule={view.data.schedule} brandId={brandId} revision={measurementBrandRevision} canEdit={!!can?.['vision.measure.run'] && profileContextIsStable()} refreshed={() => setAttempt((value) => value + 1)} />}
        <section
          aria-labelledby="measurements-title"
          aria-busy={view.loading}
          className="rounded-2xl border border-(--color-border) bg-(--color-surface) p-4 shadow-(--shadow-card) sm:p-6"
        >
          <h2
            id="measurements-title"
            ref={headingRef}
            tabIndex={-1}
            className="text-xl font-bold focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--color-focus)"
          >
            История замеров
          </h2>
          <label className="mt-4 block text-sm font-semibold">Месяц<input type="month" value={month} onChange={event => { setMonth(event.target.value); setNavigation(current => ({ ...current, page: 1 })); }} className="mt-1 block min-h-11 rounded-lg border border-(--color-border) bg-(--color-surface) px-3" /></label>
          {view.loading && (
            <div role="status" className="mt-5 text-sm text-(--color-ink-muted)">
              <p>Загружаем замеры…</p>
              <div
                aria-hidden="true"
                className="mt-4 animate-pulse space-y-3 motion-reduce:animate-none"
              >
                {[0, 1, 2].map((row) => (
                  <div
                    key={row}
                    className="flex h-12 items-center justify-between gap-4 rounded-lg bg-(--color-surface-muted) px-4"
                  >
                    <span className="h-3 w-20 rounded bg-(--color-border)" />
                    <span className="h-3 w-28 rounded bg-(--color-border)" />
                  </div>
                ))}
              </div>
            </div>
          )}
          {view.error && (
            <div
              role="alert"
              className="mt-5 rounded-lg bg-(--color-danger-soft) p-4 text-sm text-(--color-danger-ink)"
            >
              <p>{view.error}</p>
              <button
                type="button"
                className={`${button} mt-3`}
                onClick={() => {
                  headingRef.current?.focus();
                  setState({ key: requestKey, data: null, error: '', loading: true });
                  setAttempt((value) => value + 1);
                }}
              >
                Повторить
              </button>
            </div>
          )}
          {view.data &&
            view.data.revision === measurementBrandRevision &&
            view.data.page === page && (
              <div data-private-profile className="mt-5">
                {view.data.total === 0 ? (
                  <div
                    role="status"
                    className="rounded-xl bg-(--color-surface-muted) p-8 text-center"
                  >
                    <BarChart3
                      aria-hidden="true"
                      className="mx-auto mb-3 text-(--color-ink-muted)"
                    />
                    <p className="font-semibold">Замеров пока нет</p>
                    <p className="mt-2 text-sm text-(--color-ink-muted)">
                      Здесь появится история сохранённых замеров этого бренда.
                    </p>
                  </div>
                ) : view.data.data.length === 0 ? (
                  <p role="status" className="text-sm">
                    На этой странице замеров нет.
                  </p>
                ) : (
                  <>
                    <div className="overflow-x-auto"><table className="w-full min-w-[850px] text-left text-sm"><thead><tr className="border-b border-(--color-border)"><th className="p-3">№</th><th className="p-3">Дата</th><th className="p-3">Статус</th><th className="p-3">Запуск</th><th className="p-3">Вопросы × модели × прогоны</th><th className="p-3">Видимость</th><th className="p-3">Упоминания</th><th className="p-3">Длительность</th><th className="p-3">Действия</th></tr></thead><tbody>{view.data.data.map((row,index)=>{const answers=row.answers ?? [];const duration=row.started_at&&row.finished_at?Math.max(0,Math.round((new Date(row.finished_at).getTime()-new Date(row.started_at).getTime())/60000)):null;return <Fragment key={row.id}><tr className="border-b border-(--color-border)"><td className="p-3">{view.data!.total-(page-1)*25-index}</td><td className="p-3">{date(row.created_at)}</td><td className="p-3">{stateLabel[row.status] ?? row.status}</td><td className="p-3">{row.launch_source === 'manual' ? 'Вручную' : row.launch_source === 'repeat' ? 'Повтор' : row.launch_source === 'scheduled' ? 'По расписанию' : 'Не указан'}</td><td className="p-3">{answers.length?`${new Set(answers.map(a=>a.question_n)).size} × ${new Set(answers.map(a=>a.provider)).size} × ${new Set(answers.map(a=>a.run_no)).size}`:'—'}</td><td className="p-3">{row.visibility == null ? '—' : row.visibility_before != null ? `${row.visibility_before} % → ${row.visibility} %` : `${row.visibility} %`}{row.visibility_delta != null && <span className="ml-2 text-(--color-ink-muted)">{row.visibility_delta > 0 ? '+' : ''}{row.visibility_delta} п.п.</span>}</td><td className="p-3">{row.mentions ?? '—'}</td><td className="p-3">{duration===null?'—':`${duration} мин`}</td><td className="p-3"><details className="relative"><summary className={`${button} inline-flex cursor-pointer list-none items-center`}>Действия</summary><div className="absolute right-0 z-10 mt-1 min-w-48 rounded-lg border border-(--color-border) bg-(--color-surface) p-2 shadow-(--shadow-card)"><a href={`/vision/runs/${row.id}/answers`} className="block rounded-lg px-3 py-2 hover:bg-(--color-surface-muted)">Ответы</a><button type="button" className="w-full cursor-pointer rounded-lg px-3 py-2 text-left hover:bg-(--color-surface-muted) disabled:opacity-50" disabled={!!acting || !can?.['vision.measure.run']} onClick={()=>act(row.id,'repeat')}>Повторить замер</button></div></details></td></tr><tr><td colSpan={9} className="p-3"><details open><summary className="cursor-pointer font-semibold">Замер · {stateLabel[row.status] ?? row.status} — ответы и прогресс</summary>{(['pending','running','in_progress'].includes(row.status) || (row.status === 'partial' && !row.finished_at)) && <RunProgress answers={answers} status={row.status} createdAt={row.created_at} startedAt={row.started_at} finishedAt={row.finished_at} />}{answers.some(answer=>answer.status==='pending')&&<button type="button" className={`${button} mt-3`} disabled={!!acting || !can?.['vision.measure.run']} onClick={()=>act(row.id,'next')}>Выполнить следующий слот</button>}{row.estimated_minutes != null && <p className="mt-3">Ориентир по предыдущему сопоставимому замеру: {row.estimated_minutes} мин.</p>}{row.pending_clarifications != null && <p className="mt-3">Ожидают уточнения: {row.pending_clarifications}</p>}<ConfirmationProgress status={row.status} answers={answers} confirmed={row.confirmed} canConfirm={Boolean(can?.['vision.measure.run'])} loading={Boolean(acting)} onConfirm={()=>act(row.id,'confirm')} /><details className="mt-4" open={answers.length>0}><summary className="cursor-pointer font-semibold">Ответы и слоты ({answers.length})</summary><AnswerPanel answers={answers} /></details></details></td></tr></Fragment>;})}</tbody></table></div>
                  </>
                )}
                {view.data.last_page > 1 && (
                  <nav
                    aria-label="Страницы замеров"
                    className="mt-5 flex flex-wrap items-center justify-between gap-3"
                  >
                    <p className="text-sm">
                      Страница {view.data.page} из {view.data.last_page}
                    </p>
                    <div className="flex gap-2">
                      <button
                        type="button"
                        className={button}
                        disabled={page <= 1}
                        onClick={() => {
                          headingRef.current?.focus();
                          setState({ key: requestKey, data: null, error: '', loading: true });
                          setNavigation({ ...navigation, page: page - 1 });
                        }}
                      >
                        Назад
                      </button>
                      <button
                        type="button"
                        className={button}
                        disabled={page >= view.data.last_page}
                        onClick={() => {
                          headingRef.current?.focus();
                          setState({ key: requestKey, data: null, error: '', loading: true });
                          setNavigation({ ...navigation, page: page + 1 });
                        }}
                      >
                        Вперёд
                      </button>
                    </div>
                  </nav>
                )}
              </div>
            )}
        </section>
      </div>
    </CabinetLayout>
  );
}

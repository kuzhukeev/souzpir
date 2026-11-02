import { Head, router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { BarChart3 } from 'lucide-react';
import CabinetLayout from '@/layouts/CabinetLayout';
import ConfirmationProgress from '@/features/Vision/Measurements/ConfirmationProgress';
import {
  profileContextIsStable,
  subscribeProfileContext,
} from '@/features/Shell/Profile/contextFence';

type Row = {
  id: string;
  status: string;
  created_at: string;
  answers?: { question_n: number; provider: string; requested_model: string; run_no: number; status: string; answer_text: string | null; error_code: string | null }[];
};
type Listing = { revision: string; data: Row[]; page: number; last_page: number; total: number };
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
  const [starting, setStarting] = useState(false);
  const [startError, setStartError] = useState('');
  const [acting, setActing] = useState('');
  const act = (id: string, action: 'next' | 'repeat') => {
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
      const response = await fetch(`/vision/runs/data?page=${page}&view=details`, {
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
  }, [brandId, measurementBrandRevision, page, attempt, requestKey]);

  return (
    <CabinetLayout breadcrumbs={[{ label: 'Видение', href: '/vision' }, { label: 'Замеры' }]}>
      <Head title="Замеры" />
      <div className="mx-auto max-w-[1500px]">
        <header className="mb-6 flex flex-wrap items-end justify-between gap-4">
          <div>
            <h1 className="text-3xl font-bold">Замеры</h1>
            <p className="mt-2 text-base text-(--color-ink-muted)">
              История проверок видимости бренда
            </p>
          </div>
          <div className="flex flex-wrap items-end gap-2">
            <label className="text-sm font-semibold">Повторов
              <input type="number" min={1} max={3} value={runs} onChange={(event) => setRuns(Number(event.target.value))} className="mt-1 block w-24 rounded-lg border border-(--color-border) bg-(--color-surface) px-3 py-2 text-sm" />
            </label>
            <button type="button" className={button} disabled={starting || !can?.['vision.measure.run'] || !brandId || !profileContextIsStable()} onClick={start}>Начать замер</button>
          </div>
        </header>
        <p className="mb-4 text-sm text-(--color-ink-muted)">Запуск создаёт план по утверждённым вопросам бренда. В тестовом режиме слоты выполняются по одному.</p>
        {status && <p role="status" className="mb-4 rounded-lg bg-(--color-success-soft) p-3 text-sm text-(--color-success-ink)">{status}</p>}
        {startError && <p role="alert" className="mb-4 rounded-lg bg-(--color-danger-soft) p-3 text-sm text-(--color-danger-ink)">{startError}</p>}
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
                    <ul className="grid gap-4" aria-label="История замеров">
                      {view.data.data.map((row) => (
                        <li key={row.id} className="rounded-xl border border-(--color-border) p-4 text-sm">
                          <div className="flex flex-wrap items-center justify-between gap-3">
                            <div><p className="font-semibold">Замер · {stateLabel[row.status] ?? row.status}</p><p className="mt-1 text-(--color-ink-muted)">{date(row.created_at)}</p></div>
                            <div className="flex flex-wrap gap-2">
                              {row.answers?.some((answer) => answer.status === 'pending') && <button type="button" className={button} disabled={!!acting || !can?.['vision.measure.run']} onClick={() => act(row.id, 'next')}>Выполнить следующий слот</button>}
                              <button type="button" className={button} disabled={!!acting || !can?.['vision.measure.run']} onClick={() => act(row.id, 'repeat')}>Повторить замер</button>
                            </div>
                          </div>
                          <ConfirmationProgress status={row.status} answers={row.answers ?? []} />
                          <details className="mt-4" open={(row.answers?.length ?? 0) > 0}>
                            <summary className="cursor-pointer font-semibold">Ответы и слоты ({row.answers?.length ?? 0})</summary>
                            <ul className="mt-3 grid gap-2">
                              {row.answers?.map((answer) => <li key={`${answer.question_n}-${answer.provider}-${answer.run_no}`} className="rounded-lg bg-(--color-surface-muted) p-3">
                                <p className="font-medium">Вопрос {answer.question_n} · {answer.provider} · повтор {answer.run_no}</p>
                                <p className="mt-1 text-(--color-ink-muted)">{stateLabel[answer.status] ?? answer.status} · {answer.requested_model}</p>
                                {answer.answer_text && <p className="mt-2 whitespace-pre-wrap">{answer.answer_text}</p>}
                                {answer.error_code && <p className="mt-2 text-(--color-danger-ink)">Ошибка: {errorLabel[answer.error_code] ?? answer.error_code}</p>}
                              </li>)}
                            </ul>
                          </details>
                        </li>
                      ))}
                    </ul>
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

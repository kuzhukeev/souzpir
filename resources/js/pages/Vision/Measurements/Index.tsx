import { Head, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { BarChart3 } from 'lucide-react';
import CabinetLayout from '@/layouts/CabinetLayout';
import {
  profileContextIsStable,
  subscribeProfileContext,
} from '@/features/Shell/Profile/contextFence';

type Row = {
  created_at: string;
};
type Listing = { revision: string; data: Row[]; page: number; last_page: number; total: number };
const button =
  'min-h-11 cursor-pointer rounded-lg border border-(--color-border) px-4 text-sm font-semibold text-(--color-link) focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--color-focus) disabled:cursor-not-allowed disabled:opacity-50';
const isPageVisible = () => document.visibilityState !== 'hidden';
function date(value: string | null): string {
  if (!value) return '—';
  const parsed = new Date(value);
  return Number.isNaN(parsed.getTime())
    ? '—'
    : new Intl.DateTimeFormat('ru-RU', { dateStyle: 'medium', timeStyle: 'short' }).format(parsed);
}

export default function Index({ measurementBrandRevision }: { measurementBrandRevision: string }) {
  const { brand } = usePage<{ brand: { id: string } | null }>().props;
  const brandId = brand?.id ?? '';
  const contextKey = JSON.stringify([brandId, measurementBrandRevision]);
  const [navigation, setNavigation] = useState({ contextKey, page: 1, epoch: 0 });
  // A returning context must not reuse data from an earlier visit.
  if (navigation.contextKey !== contextKey) {
    setNavigation({ contextKey, page: 1, epoch: navigation.epoch + 1 });
  }
  const page = navigation.contextKey === contextKey ? navigation.page : 1;
  const [attempt, setAttempt] = useState(0);
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
      const response = await fetch(`/vision/runs/data?page=${page}`, {
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
          <span className="rounded-lg bg-(--color-surface-muted) px-4 py-3 text-sm text-(--color-ink-muted)">
            Запуск замеров — скоро
          </span>
        </header>
        <section
          aria-labelledby="measurements-title"
          aria-busy={view.loading}
          className="rounded-2xl border border-(--color-border) bg-(--color-surface) p-4 shadow-(--shadow-card) sm:p-6"
        >
          <h2 id="measurements-title" className="text-xl font-bold">
            История замеров
          </h2>
          {view.loading && (
            <p role="status" className="mt-5 text-sm text-(--color-ink-muted)">
              Загружаем замеры…
            </p>
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
                <p className="mb-4 text-sm text-(--color-ink-muted)">
                  Детали и результаты будут доступны позже.
                </p>
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
                    <div
                      className="hidden overflow-x-auto md:block"
                      role="region"
                      aria-label="История замеров"
                      tabIndex={0}
                    >
                      <table className="w-full border-collapse text-left text-sm">
                        <thead className="bg-(--color-surface-muted)">
                          <tr>
                            <th scope="col" className="px-4 py-3">
                              Замер
                            </th>
                            <th scope="col" className="px-4 py-3">
                              Создан
                            </th>
                          </tr>
                        </thead>
                        <tbody>
                          {view.data.data.map((row, index) => (
                            <tr
                              key={`${row.created_at}-${index}`}
                              className="border-b border-(--color-border)"
                            >
                              <th scope="row" className="px-4 py-4 font-medium">
                                Замер
                              </th>
                              <td className="px-4 py-4">{date(row.created_at)}</td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                    <ul className="grid gap-3 md:hidden" aria-label="История замеров">
                      {view.data.data.map((row, index) => (
                        <li
                          key={`${row.created_at}-${index}`}
                          className="rounded-xl border border-(--color-border) p-4 text-sm"
                        >
                          <p className="mb-3 font-semibold">Замер</p>
                          <dl className="space-y-3">
                            <div>
                              <dt className="text-(--color-ink-muted)">Создан</dt>
                              <dd className="mt-1">{date(row.created_at)}</dd>
                            </div>
                          </dl>
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

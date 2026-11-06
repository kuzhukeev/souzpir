import { Head, Link, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import CabinetLayout from '@/layouts/CabinetLayout';
import RunProgress from './ProgressDetails';
import MeasurementSubscription from './MeasurementSubscription';
import { profileContextIsStable, subscribeProfileContext } from '@/features/Shell/Profile/contextFence';

type Answer = { question_n: number; provider: string; requested_model: string; status: string };
type Measurement = { status: string; created_at: string; started_at: string | null; finished_at: string | null; estimated_minutes: number | null; pending_clarifications: number | null; answers: Answer[] };

export default function Progress({ measurement, measurementBrandRevision }: { measurement: string; measurementBrandRevision: string }) {
  const { brand } = usePage<{ brand: { id: string } | null }>().props;
  const brandId = brand?.id ?? '';
  const [attempt, setAttempt] = useState(0);
  const key = `${brandId}:${measurementBrandRevision}:${measurement}:${attempt}`;
  const [state, setState] = useState<{ key: string; data: Measurement | null; error: string; observedAt: number }>({ key, data: null, error: '', observedAt: 0 });
  const view = state.key === key ? state : { data: null, error: '', observedAt: 0 };
  useEffect(() => {
    let active = true;
    let timer: ReturnType<typeof setTimeout> | undefined;
    const controller = new AbortController();
    const clear = () => { active = false; controller.abort(); if (timer) clearTimeout(timer); setState({ key, data: null, error: 'Контекст бренда изменился. Обновите страницу.', observedAt: 0 }); };
    const unsubscribe = subscribeProfileContext(clear);
    const hide = () => { if (document.visibilityState === 'hidden') clear(); };
    document.addEventListener('visibilitychange', hide);
    window.addEventListener('pagehide', clear);
    const load = async () => {
      try {
        if (!brandId || !profileContextIsStable()) throw new Error();
        const headers = { Accept: 'application/json', 'X-Brand-Id': brandId, 'X-Brand-Revision': measurementBrandRevision };
        const response = await fetch(`/vision/runs/${measurement}/progress/data`, { headers, credentials: 'same-origin', cache: 'no-store', signal: controller.signal });
        if (!response.ok) throw new Error();
        const result = await response.json();
        const check = await fetch('/vision/runs/revision', { headers, credentials: 'same-origin', cache: 'no-store', signal: controller.signal });
        if (!check.ok || (await check.json()).revision !== measurementBrandRevision || result.revision !== measurementBrandRevision || !profileContextIsStable()) throw new Error();
        if (active) { setState({ key, data: result.measurement, error: '', observedAt: Date.now() }); if (['pending', 'running', 'in_progress'].includes(result.measurement.status)) timer = setTimeout(load, 5000); }
      } catch { if (active) setState({ key, data: null, error: 'Не удалось загрузить ход замера. Обновите страницу.', observedAt: 0 }); }
    };
    void load();
    return () => { active = false; controller.abort(); if (timer) clearTimeout(timer); unsubscribe(); document.removeEventListener('visibilitychange', hide); window.removeEventListener('pagehide', clear); };
  }, [brandId, key, measurement, measurementBrandRevision]);
  const row = view.data;
  const elapsed = row?.started_at ? Math.max(0, (view.observedAt - new Date(row.started_at).getTime()) / 60000) : 0;
  const remaining = row?.estimated_minutes != null ? Math.max(0, Math.ceil(row.estimated_minutes - elapsed)) : null;
  return <CabinetLayout breadcrumbs={[{ label: 'Видение', href: '/vision' }, { label: 'Замеры', href: '/vision/runs' }, { label: 'Ход замера' }]}><Head title="Ход замера"/><main className="mx-auto max-w-5xl pb-12"><h1 className="text-3xl font-bold">Ход замера</h1><Link href="/vision/runs" className="mt-3 inline-flex min-h-11 cursor-pointer items-center text-sm text-(--color-link)">← Все замеры</Link>{view.error ? <div role="alert" className="mt-4 rounded-xl bg-(--color-danger-soft) p-4 text-sm text-(--color-danger-ink)"><p>{view.error}</p><button type="button" onClick={() => setAttempt(value => value + 1)} className="mt-2 min-h-11 cursor-pointer font-semibold underline">Повторить</button></div> : !row ? <ProgressLoading/> : <>{row.status === 'completed' && <div role="status" className="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-xl bg-(--color-success-soft) p-4 text-sm text-(--color-success-ink)"><div><strong>Замер готов.</strong>{row.pending_clarifications === null ? <p className="mt-1">Количество уточнений пока недоступно.</p> : <p className="mt-1">Ожидают уточнения: {row.pending_clarifications}</p>}</div>{row.pending_clarifications !== null && row.pending_clarifications > 0 && <Link href={`/vision/runs/${measurement}/clarify/mentions`} className="inline-flex min-h-11 cursor-pointer items-center rounded-lg bg-(--color-success-ink) px-4 font-semibold text-white">Проверить уточнения</Link>}</div>}<section className="mt-5 rounded-2xl border border-(--color-border) bg-(--color-surface) p-5"><h2 className="text-xl font-semibold">Прогресс</h2>{['pending', 'running', 'in_progress'].includes(row.status) && <p className="mt-2 text-sm">{remaining === null ? 'Оценка оставшегося времени недоступна.' : remaining > 0 ? `Осталось примерно ${remaining} мин. по предыдущему сопоставимому замеру.` : 'Оценка времени истекла; замер продолжается.'} Обновляется автоматически.</p>}{['pending', 'running', 'in_progress'].includes(row.status) && <MeasurementSubscription measurement={measurement} brandId={brandId} revision={measurementBrandRevision}/>}<RunProgress answers={row.answers ?? []} status={row.status} createdAt={row.created_at} startedAt={row.started_at} finishedAt={row.finished_at}/>{row.status !== 'completed' && row.pending_clarifications != null && <p className="mt-4 text-sm">Ожидают уточнения: {row.pending_clarifications}</p>}</section></>}</main></CabinetLayout>;
}

function ProgressLoading() {
 return <div role="status" aria-busy="true" className="mt-5 space-y-4"><span className="sr-only">Загрузка хода замера…</span><div aria-hidden="true" className="animate-pulse space-y-4"><div className="rounded-xl border border-(--color-border) bg-(--color-surface) p-5"><div className="h-6 w-48 rounded bg-(--color-surface-muted)"/><div className="mt-4 h-4 rounded bg-(--color-surface-muted)"/><div className="mt-4 h-4 w-40 rounded bg-(--color-surface-muted)"/></div><div className="rounded-xl border border-(--color-border) bg-(--color-surface) p-5"><div className="h-6 w-36 rounded bg-(--color-surface-muted)"/><div className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">{[0,1,2,3].map(i=><div key={i} className="h-20 rounded bg-(--color-surface-muted)"/>)}</div></div><div className="h-28 rounded-xl border border-(--color-border) bg-(--color-surface)"/></div></div>;
}

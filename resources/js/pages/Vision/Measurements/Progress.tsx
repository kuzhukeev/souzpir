import { Head, Link, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import CabinetLayout from '@/layouts/CabinetLayout';
import RunProgress from './ProgressDetails';
import { profileContextIsStable, subscribeProfileContext } from '@/features/Shell/Profile/contextFence';

type Answer = { question_n: number; provider: string; requested_model: string; status: string };
type Measurement = { status: string; created_at: string; started_at: string | null; finished_at: string | null; estimated_minutes: number | null; pending_clarifications: number | null; answers: Answer[] };

export default function Progress({ measurement, measurementBrandRevision }: { measurement: string; measurementBrandRevision: string }) {
  const { brand } = usePage<{ brand: { id: string } | null }>().props;
  const brandId = brand?.id ?? '';
  const key = `${brandId}:${measurementBrandRevision}:${measurement}`;
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
  return <CabinetLayout breadcrumbs={[{ label: 'Видение', href: '/vision' }, { label: 'Замеры', href: '/vision/runs' }, { label: 'Ход замера' }]}><Head title="Ход замера"/><main className="mx-auto max-w-5xl pb-12"><h1 className="text-3xl font-bold">Ход замера</h1><Link href="/vision/runs" className="mt-3 inline-flex min-h-11 cursor-pointer items-center text-sm text-(--color-link)">← Все замеры</Link>{view.error ? <p role="alert" className="mt-4 text-sm">{view.error}</p> : !row ? <p role="status" className="mt-4 text-sm">Загрузка хода замера…</p> : <section className="mt-5 rounded-2xl border border-(--color-border) bg-(--color-surface) p-5"><h2 className="text-xl font-semibold">Прогресс</h2>{['pending', 'running', 'in_progress'].includes(row.status) && <p className="mt-2 text-sm">{remaining === null ? 'Оценка оставшегося времени недоступна.' : remaining > 0 ? `Осталось примерно ${remaining} мин. по предыдущему сопоставимому замеру.` : 'Оценка времени истекла; замер продолжается.'} Обновляется автоматически.</p>}<RunProgress answers={row.answers ?? []} status={row.status} createdAt={row.created_at} startedAt={row.started_at} finishedAt={row.finished_at}/>{row.pending_clarifications != null && <p className="mt-4 text-sm">Ожидают уточнения: {row.pending_clarifications}</p>}</section>}</main></CabinetLayout>;
}

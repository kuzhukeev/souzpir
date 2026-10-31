import { Crown } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Button, EmptyState } from '@/components/ui';
import type { BillingPlan } from '@/types/Shell/Billing';
import { Notice, panel } from './Shared';
import { deliveryMessages, submitPlanRequest } from './request';
import { clearPlanRequest, confirmPlanRequest, storedPlanRequest, rememberPlanRequest, type PendingPlanRequest, type StoredPlanRequest } from './submission';

type Props = { plans: BillingPlan[]; canManage: boolean; enabled: boolean; scope?: string | null; initialKey?: string | null };
const storageMessage = 'Не удаётся сохранить попытку в этом браузере. Создание заявки недоступно.';

export function PlanRequests({ plans, canManage, enabled, scope, initialKey }: Props) {
  const active = useRef(true);
  const busy = useRef(false);
  const nextKey = useRef(initialKey ?? '');
  const [state, setState] = useState<{ record: StoredPlanRequest | null; storageError: boolean }>(() => {
    if (!enabled) return { record: null, storageError: false };
    try { return { record: storedPlanRequest(scope ?? ''), storageError: false }; }
    catch { return { record: null, storageError: true }; }
  });
  const [loading, setLoading] = useState<string | null>(null);
  const [failure, setFailure] = useState('');
  useEffect(() => { active.current = true; return () => { active.current = false; }; }, []);

  const send = async (planId: string) => {
    if (busy.current || !enabled || !canManage || state.storageError) return;
    busy.current = true;
    setFailure('');
    let pending: PendingPlanRequest;
    try {
      pending = rememberPlanRequest(scope ?? '', planId, nextKey.current);
      setState({ record: { state: 'pending', request: pending }, storageError: false });
    } catch {
      setState((previous) => ({ ...previous, storageError: true }));
      setFailure(storageMessage); busy.current = false; return;
    }
    setLoading(planId);
    try {
      const reply = await submitPlanRequest(pending);
      if (reply.next_submission_key === pending.submission_key) throw new Error('Не удалось подтвердить создание заявки. Повторите ту же попытку.');
      if (!active.current) return;
      try {
        confirmPlanRequest(scope ?? '', pending, reply);
        setState({ record: { state: 'confirmed', request: pending, receipt: reply }, storageError: false });
      } catch {
        setState({ record: { state: 'pending', request: pending }, storageError: true });
      }
    } catch (error) {
      if (active.current) setFailure(error instanceof Error ? error.message : 'Не удалось подтвердить создание заявки. Повторите ту же попытку.');
    } finally {
      busy.current = false;
      if (active.current) setLoading(null);
    }
  };
  const newRequest = () => {
    if (state.record?.state !== 'confirmed' || state.storageError) return;
    try {
      clearPlanRequest(scope ?? '');
      nextKey.current = state.record.receipt.next_submission_key;
      setState({ record: null, storageError: false });
    } catch { setState((previous) => ({ ...previous, storageError: true })); }
  };
  const pending = state.record?.state === 'pending' ? state.record.request : null;
  const receipt = state.record?.state === 'confirmed' ? state.record.receipt : null;

  return <>
    <Notice>{enabled ? 'Автоматическая смена тарифа появится позже. Вы можете создать заявку.' : 'Автоматическая смена тарифа и заявка в поддержку пока недоступны.'}</Notice>
    {pending && <div className="mb-5 rounded-xl bg-(--color-info-soft) p-4 text-sm text-(--color-info-ink)"><p>Результат предыдущей попытки ещё не подтверждён. Повторите её, прежде чем выбрать другой тариф.</p><Button className="mt-3 cursor-pointer" variant="secondary" disabled={!enabled || !canManage || state.storageError || loading !== null} loading={loading !== null} onClick={() => void send(pending.plan_id)}>Повторить заявку</Button></div>}
    {state.storageError && <p role="alert" className="mb-5 rounded-xl bg-(--color-danger-soft) p-4 text-sm text-(--color-danger-ink)">{storageMessage}</p>}
    {failure && !state.storageError && <p role="alert" className="mb-5 rounded-xl bg-(--color-danger-soft) p-4 text-sm text-(--color-danger-ink)">{failure}</p>}
    {receipt && <div role="status" className="mb-5 rounded-xl bg-(--color-success-soft) p-4 text-sm text-(--color-success-ink)"><p>{receipt.created ? 'Заявка создана.' : 'Ранее созданная заявка найдена.'}</p><p>{deliveryMessages[receipt.delivery_status]}</p><Button className="mt-3 cursor-pointer" variant="secondary" disabled={state.storageError} onClick={newRequest}>Новая заявка</Button></div>}
    {plans.length ? <div className="grid gap-5 md:grid-cols-2 xl:grid-cols-3">{plans.map((plan) => <article key={plan.id} className={`${panel} flex flex-col`}><span className="mb-4 grid size-12 place-items-center rounded-full bg-(--color-success-soft) text-(--color-success)"><Crown size={23} aria-hidden="true" /></span><h2 className="break-words text-xl font-bold">{plan.name}</h2><p className="mt-3 flex-1 text-sm text-(--color-ink-muted)">Условия тарифа уточнит поддержка.</p><Button className="mt-6 w-full cursor-pointer" disabled={!canManage || !enabled || state.storageError || loading !== null || state.record !== null} onClick={() => void send(plan.id)}>{!enabled ? 'Заявка скоро появится' : 'Оставить заявку'}</Button></article>)}</div> : <section className={panel}><EmptyState title="Тарифы пока не добавлены" description="Когда планы будут настроены, они появятся здесь." /></section>}
  </>;
}

import { useEffect, useRef, useState } from 'react';
import { profileContextIsStable, subscribeProfileContext } from '@/features/Shell/Profile/contextFence';

type Outcome = { status: 'completed' | 'failed' };

export function useReconcile(brandId: string, revision: string, refresh: () => void) {
  const [selected, setSelected] = useState<string | null>(null);
  const [pending, setPending] = useState(false);
  const [error, setError] = useState('');
  const [result, setResult] = useState<Outcome | null>(null);
  const request = useRef<AbortController | null>(null);
  useEffect(() => {
    const invalidate = () => { request.current?.abort(); };
    const unsubscribe = subscribeProfileContext(invalidate);
    return () => { invalidate(); unsubscribe(); };
  }, [brandId, revision]);
  const select = (id: string) => { setSelected(id); setError(''); setResult(null); };
  const cancel = () => { setSelected(null); setError(''); };
  const confirm = async () => {
    if (!selected || pending || !profileContextIsStable() || document.visibilityState !== 'visible') return;
    const controller = new AbortController();
    request.current = controller;
    setPending(true); setError('');
    const csrf = document.cookie.split('; ').find((entry) => entry.startsWith('XSRF-TOKEN='));
    try {
      const response = await fetch(`/ai/recovery/calls/${encodeURIComponent(selected)}/reconcile`, {
        method: 'POST', credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
        headers: { Accept: 'application/json', 'X-Brand-Id': brandId, 'X-Brand-Revision': revision,
          ...(csrf ? { 'X-XSRF-TOKEN': decodeURIComponent(csrf.slice(11)) } : {}) },
      });
      if (!response.ok || response.redirected) throw new Error();
      const data: unknown = await response.json();
      if (!data || typeof data !== 'object') throw new Error();
      const value = data as Record<string, unknown>;
      if ((value.status !== 'completed' && value.status !== 'failed')) throw new Error();
      if (controller.signal.aborted || !profileContextIsStable() || document.visibilityState !== 'visible') return;
      setResult({ status: value.status });
      setSelected(null);
      refresh();
    } catch {
      if (!controller.signal.aborted && profileContextIsStable()) setError('Не удалось получить результат сверки. Обновите список перед повтором; без подтверждения провайдера резерв не освобождается.');
    } finally {
      if (!controller.signal.aborted) setPending(false);
      request.current = null;
    }
  };
  return { selected, pending, error, result, select, cancel, confirm };
}

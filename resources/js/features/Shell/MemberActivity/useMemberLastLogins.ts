import { useEffect, useState } from 'react';
import {
  profileContextIsStable,
  subscribeProfileContext,
} from '@/features/Shell/Profile/contextFence';
import { parseLastLogins, validMembershipIds } from './parseLastLogins';
import type { LastLoginStatus } from './LastLogin';

type State = { key: string; status: LastLoginStatus; values: Record<string, string | null> };
const hide = () =>
  document.querySelectorAll<HTMLElement>('[data-private-profile]').forEach((element) => {
    element.hidden = true;
  });

/** Pass the visible Team membership IDs, selected brand ID and teamBrandRevision. */
export function useMemberLastLogins(ids: string[], brandId: string, revision: string) {
  const scope = JSON.stringify([ids, brandId, revision]);
  const [binding, setBinding] = useState({ scope, epoch: 0 });
  if (binding.scope !== scope) setBinding({ scope, epoch: binding.epoch + 1 });
  const epoch = binding.scope === scope ? binding.epoch : binding.epoch + 1;
  const [attempt, setAttempt] = useState(0);
  const key = JSON.stringify([scope, epoch, attempt]);
  const [state, setState] = useState<State>({ key, status: 'loading', values: {} });
  const current: State =
    ids.length === 0
      ? { key, status: 'ready', values: {} }
      : state.key === key
        ? state
        : { key, status: 'loading', values: {} };

  useEffect(() => {
    if (ids.length === 0) return;
    let active = true;
    let refresh: number | undefined;
    let timeout: number | undefined;
    let stale: number | undefined;
    const controller = new AbortController();
    const stop = () => {
      hide();
      active = false;
      controller.abort();
      window.clearTimeout(refresh);
      window.clearTimeout(timeout);
      window.clearTimeout(stale);
      setState({ key, status: 'unavailable', values: {} });
    };
    const visibility = () => {
      if (document.visibilityState !== 'visible') stop();
      else setAttempt((value) => value + 1);
    };
    const pageShow = (event: PageTransitionEvent) => {
      if (event.persisted) window.location.reload();
    };
    const unsubscribe = subscribeProfileContext(stop);
    document.addEventListener('visibilitychange', visibility);
    window.addEventListener('pagehide', stop);
    window.addEventListener('pageshow', pageShow);
    const load = async () => {
      const started = performance.now();
      timeout = window.setTimeout(() => controller.abort(), 10_000);
      try {
        if (
          !validMembershipIds(ids) ||
          !brandId ||
          !/^[a-f0-9]{64}$/.test(revision) ||
          !profileContextIsStable() ||
          document.visibilityState !== 'visible'
        )
          throw new Error();
        const params = new URLSearchParams();
        ids.forEach((id) => params.append('ids[]', id));
        const read = async () => {
          const response = await fetch(`/team/member-last-logins?${params}`, {
            credentials: 'same-origin',
            cache: 'no-store',
            signal: controller.signal,
            headers: {
              Accept: 'application/json',
              'X-Brand-Id': brandId,
              'X-Brand-Revision': revision,
            },
          });
          if (!response.ok || response.redirected) throw new Error();
          return parseLastLogins(await response.json(), ids, revision);
        };
        await read();
        if (!active || !profileContextIsStable() || document.visibilityState !== 'visible')
          throw new Error();
        const values = await read();
        const elapsed = performance.now() - started;
        if (
          !active ||
          !profileContextIsStable() ||
          document.visibilityState !== 'visible' ||
          elapsed >= 10_000
        )
          throw new Error();
        setState({ key, status: 'ready', values });
        window.clearTimeout(stale);
        stale = window.setTimeout(stop, 10_000 - elapsed);
        refresh = window.setTimeout(
          () => {
            void load();
          },
          Math.max(0, 5_000 - elapsed),
        );
      } catch {
        if (active) stop();
      } finally {
        window.clearTimeout(timeout);
      }
    };
    void load();
    return () => {
      active = false;
      controller.abort();
      window.clearTimeout(timeout);
      window.clearTimeout(refresh);
      window.clearTimeout(stale);
      unsubscribe();
      document.removeEventListener('visibilitychange', visibility);
      window.removeEventListener('pagehide', stop);
      window.removeEventListener('pageshow', pageShow);
    };
    // Scope encodes the ID values; no request should restart merely because a caller made a new array.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [scope, key]);

  const retry = () => {
    hide();
    setAttempt((value) => value + 1);
  };
  return { status: current.status, values: current.values, retry };
}

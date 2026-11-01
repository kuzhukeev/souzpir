import { useEffect, useState } from 'react';
import {
  profileContextIsStable,
  subscribeProfileContext,
} from '@/features/Shell/Profile/contextFence';
import { parseRecoveryPage, type RecoveryPage } from './listing';

type Navigation = { context: string; cursors: (string | null)[]; page: number; epoch: number };
type View = { key: string; data: RecoveryPage | null; error: string; loading: boolean };
const hidden = () =>
  document.querySelectorAll<HTMLElement>('[data-private-profile]').forEach((element) => {
    element.hidden = true;
  });

export function useRecovery(brandId: string, revision: string) {
  const context = JSON.stringify([brandId, revision]);
  const [navigation, setNavigation] = useState<Navigation>({
    context,
    cursors: [null],
    page: 0,
    epoch: 0,
  });
  if (navigation.context !== context)
    setNavigation({ context, cursors: [null], page: 0, epoch: navigation.epoch + 1 });
  const page = navigation.context === context ? navigation.page : 0;
  const cursor = navigation.context === context ? navigation.cursors[page] : null;
  const [attempt, setAttempt] = useState(0);
  const epoch = navigation.context === context ? navigation.epoch : navigation.epoch + 1;
  const key = JSON.stringify([context, cursor, page, attempt, epoch]);
  const [state, setState] = useState<View>({ key, data: null, error: '', loading: true });
  const view = state.key === key ? state : { data: null, error: '', loading: true };

  useEffect(() => {
    let active = true;
    let refresh: number | undefined;
    let timeout: number | undefined;
    let stale: number | undefined;
    const controller = new AbortController();
    const stop = (message: string) => {
      hidden();
      active = false;
      controller.abort();
      window.clearTimeout(refresh);
      window.clearTimeout(timeout);
      window.clearTimeout(stale);
      setState({
        key,
        data: null,
        loading: false,
        error: message,
      });
    };
    const invalidate = () => stop('Контекст изменился. Обновите страницу и проверьте доступ.');
    const visibility = () => {
      if (document.visibilityState !== 'visible') invalidate();
      else setAttempt((value) => value + 1);
    };
    const pageShow = (event: PageTransitionEvent) => {
      if (event.persisted) window.location.reload();
    };
    const unsubscribe = subscribeProfileContext(invalidate);
    document.addEventListener('visibilitychange', visibility);
    window.addEventListener('pagehide', invalidate);
    window.addEventListener('pageshow', pageShow);
    const load = async () => {
      const started = performance.now();
      timeout = window.setTimeout(() => controller.abort(), 10_000);
      try {
        if (
          !brandId ||
          !/^[a-f0-9]{64}$/.test(revision) ||
          !profileContextIsStable() ||
          document.visibilityState !== 'visible'
        )
          throw new Error();
        const query = cursor ? `?after=${encodeURIComponent(cursor)}` : '';
        const read = async () => {
          const response = await fetch(`/ai/recovery/calls${query}`, {
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
          return parseRecoveryPage(await response.json());
        };
        await read();
        if (!active || !profileContextIsStable() || document.visibilityState !== 'visible')
          throw new Error();
        // A response generated before a brand/session change can arrive afterwards.
        const data = await read();
        if (
          !active ||
          !profileContextIsStable() ||
          document.visibilityState !== 'visible' ||
          (cursor && data.nextCursor === cursor)
        )
          throw new Error();
        const elapsed = performance.now() - started;
        if (elapsed >= 10_000) throw new Error();
        setState({ key, data, error: '', loading: false });
        // A hung verification cannot extend a previously displayed snapshot.
        window.clearTimeout(stale);
        stale = window.setTimeout(
          () => stop('Не удалось вовремя подтвердить доступ. Повторите попытку.'),
          10_000 - elapsed,
        );
        // A successful refresh keeps keyboard focus and scroll position intact.
        refresh = window.setTimeout(
          () => {
            void load();
          },
          Math.max(0, 5_000 - elapsed),
        );
      } catch {
        if (active) stop('Не удалось подтвердить доступ или загрузить список. Попробуйте снова.');
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
      window.removeEventListener('pagehide', invalidate);
      window.removeEventListener('pageshow', pageShow);
    };
  }, [brandId, revision, cursor, key]);

  const retry = () => {
    hidden();
    setAttempt((value) => value + 1);
  };
  const previous = () => {
    hidden();
    setNavigation((value) => ({ ...value, page: Math.max(0, value.page - 1) }));
  };
  const next = () => {
    if (!view.data?.nextCursor) return;
    const nextCursor = view.data.nextCursor;
    hidden();
    setNavigation((value) => ({
      ...value,
      cursors: [...value.cursors.slice(0, value.page + 1), nextCursor],
      page: value.page + 1,
    }));
  };
  return { view, page: page + 1, previous, next, retry };
}

import { router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { flushSync } from 'react-dom';
import { ProfilePage } from '@/features/Shell/Profile/shared';
import type { NotificationSettings, ProfileData, SecurityData } from '@/types/Shell/Profile';
import Profile from './Profile';
import Notifications from './Notifications';
import Security from './Security';

type Screen = 'profile' | 'notifications' | 'security';
type Data = { profile: ProfileData; timezones: string[] } | { notificationSettings: NotificationSettings } | { security: SecurityData };

// Inertia receives only an anonymous shell. Personal data never become page props.
export default function Local({ screen }: { screen: Screen }) {
  const page = usePage();
  const [data, setData] = useState<Data | null>(null);
  const [failure, setFailure] = useState(false);
  const target = useRef<HTMLDivElement>(null);

  useEffect(() => {
    let controller: AbortController | null = null;
    let statusRequest: AbortController | null = null;
    let current = true;
    const element = target.current;
    const conceal = () => {
      controller?.abort();
      controller = null;
      statusRequest?.abort();
      statusRequest = null;
      // Hide synchronously before a document can be put in BFCache.
      const wasVisible = element && !element.hasAttribute('hidden');
      element?.setAttribute('hidden', '');
      if (wasVisible) flushSync(() => setData(null));
      else setData(null);
    };
    const refresh = () => {
      conceal();
      if (document.visibilityState !== 'visible') return;
      setFailure(false);
      const request = new AbortController();
      controller = request;
      void fetch(`/profile/local-data/${screen}`, {
        credentials: 'same-origin', cache: 'no-store', redirect: 'error', signal: request.signal,
        headers: { Accept: 'application/json' },
      }).then(async response => {
        if (!response.ok || !response.headers.get('content-type')?.includes('application/json')) throw new Error('Unavailable');
        return response.json() as Promise<Data>;
      }).then(payload => {
        if (!current || request.signal.aborted || document.visibilityState !== 'visible') return;
        flushSync(() => setData(payload));
        element?.removeAttribute('hidden');
      }).catch(() => { if (current && !request.signal.aborted) setFailure(true); });
    };
    const onVisibility = () => { if (document.visibilityState === 'visible') refresh(); else conceal(); };
    const timer = window.setInterval(() => {
      if (document.visibilityState !== 'visible' || !element || element.hasAttribute('hidden') || statusRequest) return;
      const request = new AbortController();
      statusRequest = request;
      void fetch('/profile/local-data/status', {
        credentials: 'same-origin', cache: 'no-store', redirect: 'error', signal: request.signal,
        headers: { Accept: 'application/json' },
      }).then(response => {
        if (!response.ok) throw new Error('Session unavailable');
      }).catch(() => {
        if (current && !request.signal.aborted) { conceal(); setFailure(true); }
      }).finally(() => { if (statusRequest === request) statusRequest = null; });
    }, 3000);
    const onPageHide = () => conceal();
    const onPageShow = () => refresh();
    const removeSuccess = router.on('success', () => refresh());
    window.addEventListener('pagehide', onPageHide);
    window.addEventListener('pageshow', onPageShow);
    window.addEventListener('popstate', refresh);
    window.addEventListener('focus', refresh);
    document.addEventListener('visibilitychange', onVisibility);
    refresh();
    return () => {
      current = false;
      controller?.abort();
      statusRequest?.abort();
      window.clearInterval(timer);
      element?.setAttribute('hidden', '');
      removeSuccess();
      window.removeEventListener('pagehide', onPageHide);
      window.removeEventListener('pageshow', onPageShow);
      window.removeEventListener('popstate', refresh);
      window.removeEventListener('focus', refresh);
      document.removeEventListener('visibilitychange', onVisibility);
    };
  }, [screen, page.url]);

  return <>
    {!data && <ProfilePage active={screen}><p role={failure ? 'alert' : 'status'}>{failure ? 'Не удалось загрузить данные. Проверьте вход и обновите страницу.' : 'Загружаем данные…'}</p></ProfilePage>}
    <div ref={target} hidden>
      {data && screen === 'profile' && 'profile' in data && <Profile key={screen} {...data} />}
      {data && screen === 'notifications' && 'notificationSettings' in data && <Notifications key={screen} {...data} />}
      {data && screen === 'security' && 'security' in data && <Security key={screen} {...data} />}
    </div>
  </>;
}

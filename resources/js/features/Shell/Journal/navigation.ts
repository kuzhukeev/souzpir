import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

export function useJournalNavigation() {
  const [loading, setLoading] = useState(false);
  const [failed, setFailed] = useState(false);
  const lastGet = useRef<string | null>(null);
  useEffect(() => {
    const start = router.on('start', (event) => {
      if (event.detail.visit.method === 'get') lastGet.current = event.detail.visit.url.toString();
      setFailed(false);
      setLoading(true);
    });
    const finish = router.on('finish', () => setLoading(false));
    const network = router.on('networkError', (event) => {
      event.preventDefault();
      setFailed(true);
    });
    const http = router.on('httpException', (event) => {
      event.preventDefault();
      setFailed(true);
    });
    return () => {
      start();
      finish();
      network();
      http();
    };
  }, []);
  const retry = () =>
    router.get(
      lastGet.current ?? window.location.href,
      {},
      { preserveState: true, preserveScroll: true, replace: true },
    );
  return { loading, failed, retry };
}

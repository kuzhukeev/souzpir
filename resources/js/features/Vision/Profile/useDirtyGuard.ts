import { router } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
export function useDirtyGuard(dirty: boolean) {
  const allow = useRef(false);
  useEffect(() => {
    const warn = (event: BeforeUnloadEvent) => {
      if (dirty && !allow.current) {
        event.preventDefault();
        event.returnValue = '';
      }
    };
    const stop = router.on('before', (event) => {
      if (dirty && !allow.current) {
        if (!window.confirm('У вас есть несохранённые изменения. Покинуть страницу?'))
          event.preventDefault();
        else allow.current = true;
      }
    });
    const reset = router.on('finish', () => {
      allow.current = false;
    });
    window.addEventListener('beforeunload', warn);
    return () => {
      stop();
      reset();
      window.removeEventListener('beforeunload', warn);
    };
  }, [dirty]);
  return allow;
}

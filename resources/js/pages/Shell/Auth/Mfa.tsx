import { Link } from '@inertiajs/react';
import { Button } from '@/components/ui';
import { AuthFrame, Field, linkClass } from '@/features/Shell/Auth/AuthFrame';
import type { FormEvent } from 'react';
import { useEffect, useRef, useState } from 'react';
import { flushSync } from 'react-dom';

export default function Mfa() {
  const [code, setCode] = useState('');
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const pending = useRef<AbortController | null>(null);
  const [recovery, setRecovery] = useState(false);
  useEffect(() => {
    const clear = () => {
      pending.current?.abort();
      pending.current = null;
      flushSync(() => { setCode(''); setBusy(false); });
    };
    window.addEventListener('pagehide', clear);
    return () => { window.removeEventListener('pagehide', clear); pending.current?.abort(); pending.current = null; };
  }, []);
  const submit = (event: FormEvent) => {
    event.preventDefault();
    if (busy) return;
    const controller = new AbortController();
    pending.current = controller;
    setBusy(true);
    setError('');
    const cookie = document.cookie.split('; ').find((item) => item.startsWith('XSRF-TOKEN='));
    if (!cookie) { setCode(''); setBusy(false); setError('Не удалось проверить код. Обновите страницу.'); return; }
    void fetch('/mfa/challenge', {
      method: 'POST', credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)) },
      body: JSON.stringify({ code }),
    }).then(async (response) => {
      if (!response.ok || !response.redirected || new URL(response.url).origin !== location.origin) {
        throw new Error('Challenge failed');
      }
      if (!controller.signal.aborted) location.assign(response.url);
    }).catch(() => {
      if (!controller.signal.aborted) setError('Не удалось подтвердить вход. Проверьте код и соединение. После нескольких ошибок повторите попытку через 15 минут.');
    }).finally(() => {
      if (pending.current === controller) { pending.current = null; setCode(''); setBusy(false); }
    });
  };
  return (
    <AuthFrame
      title="Двухфакторная проверка"
      subtitle={recovery ? 'Введите один из сохранённых резервных кодов.' : 'Введите шестизначный код из приложения-аутентификатора.'}
    >
      <form onSubmit={submit} className="space-y-5">
        <Field
          label={recovery ? 'Резервный код' : 'Код подтверждения'}
          autoComplete={recovery ? 'off' : 'one-time-code'}
          inputMode={recovery ? 'text' : 'numeric'}
          pattern={recovery ? '([a-fA-F0-9]{8}-){3}[a-fA-F0-9]{8}|[a-fA-F0-9]{32}' : '[0-9]{6}'}
          maxLength={recovery ? 35 : 6}
          required
          value={code}
          onChange={(e) => setCode(e.target.value)}
          error={error}
        />
        <Button type="submit" loading={busy} className="min-h-12 w-full">
          Подтвердить
        </Button>
        <button type="button" className="cursor-pointer text-sm text-(--color-link)" onClick={() => { setRecovery(!recovery); setCode(''); setError(''); }}>
          {recovery ? 'Использовать код приложения' : 'Использовать резервный код'}
        </button>
      </form>
      <p className="mt-6 text-center text-sm">
        <Link href="/login" className={linkClass}>
          Вернуться ко входу
        </Link>
      </p>
    </AuthFrame>
  );
}

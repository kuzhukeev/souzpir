import { Link, router, useForm } from '@inertiajs/react';
import { Button } from '@/components/ui';
import { AuthFrame, Field } from '@/features/Shell/Auth/AuthFrame';
import { useState, type FormEvent } from 'react';
import { beginProfileContextChange, finishProfileContextChange } from '@/features/Shell/Profile/contextFence';

const logoutRetryKey = 'souzpir.profile-logout.retry';
const xsrf = () => document.cookie.split('; ').find((item) => item.startsWith('XSRF-TOKEN=')) ?? '';
function pendingLogout(): { key: string; xsrf: string } | null {
  try {
    const value = JSON.parse(sessionStorage.getItem(logoutRetryKey) ?? 'null');
    if (typeof value?.key !== 'string' || typeof value?.xsrf !== 'string') return null;
    if (value.xsrf !== xsrf()) {
      sessionStorage.removeItem(logoutRetryKey);
      finishProfileContextChange(value.key);
      return null;
    }
    return value;
  } catch { return null; }
}

export default function Ready({ mfaEnabled, canOpenJournal }: { mfaEnabled: boolean; canOpenJournal: boolean }) {
  const setup = useForm({ password: '', code: '' });
  const [logout, setLogout] = useState(pendingLogout);
  const [logoutError, setLogoutError] = useState('');
  const finishLogout = (key: string) => {
    try { sessionStorage.removeItem(logoutRetryKey); } catch { /* Keep private data closed. */ }
    finishProfileContextChange(key);
    setLogout(null);
  };
  const retryLogout = async (key: string) => {
    try {
      const response = await fetch('/logout/recover', { headers: { 'X-Context-Change-Key': key, Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store' });
      if (response.ok && (await response.json()).logged_out === true) {
        finishLogout(key);
        window.location.assign('/login');
        return;
      }
    } catch { /* The original request can still be retried with the same key. */ }
    sendLogout(key);
  };
  const sendLogout = (key: string) => {
    setLogoutError('');
    router.post('/logout', {}, {
      headers: { 'X-Context-Change-Key': key },
      onSuccess: () => finishLogout(key),
      onError: () => setLogoutError('Не удалось выйти. Повторите запрос.'),
      onNetworkError: () => { setLogoutError('Ответ сервера потерян. Повторите выход.'); return false; },
      onHttpException: () => { setLogoutError('Не удалось подтвердить выход. Повторите запрос.'); return false; },
    });
  };
  const submit = (event: FormEvent) => {
    event.preventDefault();
    setup.post(mfaEnabled ? '/mfa/disable' : '/mfa/setup', {
      onFinish: () => setup.reset('password', 'code'),
    });
  };
  return (
    <AuthFrame
      title="Вход выполнен"
      subtitle="Доступ подтверждён. Основной кабинет находится в разработке."
    >
      {canOpenJournal && <Link href="/journal" className="mb-5 inline-flex min-h-11 w-full cursor-pointer items-center justify-center rounded-lg bg-(--color-action) px-4 text-sm font-semibold text-white hover:bg-(--color-action-hover)">Перейти в кабинет</Link>}
      <p className="text-sm text-(--color-ink-muted)">
        Двухфакторная проверка:{' '}
        <strong className="text-(--color-ink)">{mfaEnabled ? 'включена' : 'не включена'}</strong>
      </p>
      <form onSubmit={submit} className="mt-5 space-y-4">
        <Field
          label="Текущий пароль"
          type="password"
          autoComplete="current-password"
          required
          value={setup.data.password}
          onChange={(e) => setup.setData('password', e.target.value)}
          error={setup.errors.password}
        />
        {mfaEnabled && (
          <Field
            label="Код из приложения"
            autoComplete="one-time-code"
            inputMode="numeric"
            pattern="[0-9]{6}"
            maxLength={6}
            required
            value={setup.data.code}
            onChange={(e) => setup.setData('code', e.target.value)}
            error={setup.errors.code}
          />
        )}
        <Button type="submit" loading={setup.processing} className="min-h-12 w-full">
          {mfaEnabled ? 'Отключить двухфакторную проверку' : 'Настроить двухфакторную проверку'}
        </Button>
      </form>
      <Button variant="ghost" className="mt-4 w-full" onClick={() => {
        if (logout) { void retryLogout(logout.key); return; }
        const key = beginProfileContextChange();
        if (!key) { setLogoutError('Не удалось подтвердить сессию. Обновите страницу.'); return; }
        const saved = { key, xsrf: xsrf() };
        try { sessionStorage.setItem(logoutRetryKey, JSON.stringify(saved)); }
        catch { finishProfileContextChange(key); setLogoutError('Не удалось сохранить запрос выхода.'); return; }
        setLogout(saved);
        sendLogout(key);
      }}>
        {logout ? 'Повторить выход' : 'Выйти'}
      </Button>
      {logoutError && <p role="alert" className="mt-2 text-sm text-(--color-danger-ink)">{logoutError}</p>}
    </AuthFrame>
  );
}

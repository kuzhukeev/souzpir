import { useEffect, useRef, useState, type FormEvent } from 'react';
import { flushSync } from 'react-dom';
import { useForm } from '@inertiajs/react';
import { supportsPrivateHistory } from '@/features/Shell/Profile/browser';
import { KeyRound, LockKeyhole, Monitor, ShieldCheck } from 'lucide-react';
import { Badge, Button, EmptyState, Input, Modal } from '@/components/ui';
import { Errors, Notice, ProfilePage, panel } from '@/features/Shell/Profile/shared';
import { QrCode } from '@/features/Shell/Profile/QrCode';
import { dateTime, useFeedback, useProfileText } from '@/features/Shell/Profile/hooks';
import type { SecurityData } from '@/types/Shell/Profile';

export default function Security({
  security,
  status,
}: {
  security: SecurityData;
  status?: string | null;
}) {
  const t = useProfileText();
  const privateHistory = supportsPrivateHistory();
  const password = useForm({ current_password: '', password: '', password_confirmation: '' });
  const mfa = useForm({ password: '', code: '' });
  const sessions = useForm({});
  const feedback = useFeedback();
  const [mfaOpen, setMfaOpen] = useState(false);
  const [dismissedSetup, setDismissedSetup] = useState<string | null>(null);
  const [targetSession, setTargetSession] = useState<string | null>(null);
  const [copyStatus, setCopyStatus] = useState('');
  const [recoveryOpen, setRecoveryOpen] = useState(false);
  const [recoveryInput, setRecoveryInput] = useState('');
  const [recoveryCodes, setRecoveryCodes] = useState<string[]>([]);
  const [recoveryError, setRecoveryError] = useState('');
  const [recoveryBusy, setRecoveryBusy] = useState(false);
  const recoveryRequest = useRef<AbortController | null>(null);
  useEffect(() => {
    const clear = () => {
      recoveryRequest.current?.abort();
      recoveryRequest.current = null;
      flushSync(() => { setRecoveryCodes([]); setRecoveryInput(''); setRecoveryOpen(false); setRecoveryBusy(false); });
    };
    window.addEventListener('pagehide', clear);
    return () => { window.removeEventListener('pagehide', clear); recoveryRequest.current?.abort(); recoveryRequest.current = null; };
  }, []);
  const setup =
    privateHistory && security.setup && security.setup.secret !== dismissedSetup
      ? security.setup
      : null;
  const savePassword = (event: FormEvent) => {
    event.preventDefault();
    feedback.clear();
    password.put('/profile/security/password', {
      preserveScroll: true,
      onSuccess: () => feedback.setNotice(t('password_saved')),
      onFinish: () => password.reset(),
      onNetworkError: feedback.failure,
      onHttpException: feedback.failure,
    });
  };
  const submitMfa = (event: FormEvent) => {
    event.preventDefault();
    if (!security.mfa_enabled && !privateHistory) return;
    feedback.clear();
    setCopyStatus('');
    const endpoint = security.mfa_enabled ? 'disable' : setup ? 'confirm' : 'setup';
    mfa.post(`/profile/security/mfa/${endpoint}`, {
      preserveScroll: true,
      onSuccess: () => {
        setMfaOpen(false);
        setDismissedSetup(null);
      },
      onFinish: () => mfa.reset(),
      onNetworkError: feedback.failure,
      onHttpException: feedback.failure,
    });
  };
  const closeMfa = () => {
    if (mfa.processing) return;
    mfa.reset();
    mfa.clearErrors();
    setCopyStatus('');
    setMfaOpen(false);
    if (setup) {
      setDismissedSetup(setup.secret);
      mfa.delete('/profile/security/mfa/setup', {
        preserveScroll: true,
        onNetworkError: feedback.failure,
        onHttpException: feedback.failure,
      });
    }
  };
  const copyKey = async () => {
    if (!setup) return;
    try {
      await navigator.clipboard.writeText(setup.secret);
      setCopyStatus(t('copied'));
    } catch {
      setCopyStatus(t('copy_failed'));
    }
  };
  const issueRecovery = async (event: FormEvent) => {
    event.preventDefault();
    if (recoveryBusy || !privateHistory) return;
    setRecoveryError('');
    setRecoveryBusy(true);
    const controller = new AbortController();
    recoveryRequest.current = controller;
    try {
      const cookie = document.cookie.split('; ').find((item) => item.startsWith('XSRF-TOKEN='));
      if (!cookie) throw new Error('Missing CSRF cookie');
      const response = await fetch('/profile/security/mfa/recovery', {
        method: 'POST', credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)) },
        body: JSON.stringify({ code: recoveryInput }),
      });
      if (!response.ok) throw new Error('Recovery codes unavailable');
      const payload: unknown = await response.json();
      if (!payload || typeof payload !== 'object' || !('codes' in payload) || !Array.isArray(payload.codes)
        || payload.codes.length !== 10 || !payload.codes.every((code: unknown) => typeof code === 'string' && /^([A-F0-9]{8}-){3}[A-F0-9]{8}$/.test(code))) {
        throw new Error('Invalid response');
      }
      if (recoveryRequest.current === controller && !controller.signal.aborted) setRecoveryCodes(payload.codes as string[]);
    } catch {
      if (!controller.signal.aborted) setRecoveryError('Не удалось получить коды. Проверьте код приложения и попробуйте снова. Если ответ потерялся, создайте новый набор.');
    } finally {
      if (recoveryRequest.current === controller) {
        recoveryRequest.current = null;
        setRecoveryInput('');
        setRecoveryBusy(false);
      }
    }
  };
  const endSessions = () => {
    if (!targetSession) return;
    feedback.clear();
    sessions.delete(
      targetSession === 'others'
        ? '/profile/security/sessions'
        : `/profile/security/sessions/${encodeURIComponent(targetSession)}`,
      {
        preserveScroll: true,
        onSuccess: () => setTargetSession(null),
        onNetworkError: feedback.failure,
        onHttpException: feedback.failure,
      },
    );
  };
  return (
    <ProfilePage active="security">
      <Notice>{feedback.notice || status}</Notice>
      <Errors errors={sessions.errors} transport={feedback.transport} />
      <div className="grid items-start gap-5 xl:grid-cols-2">
        <section className={panel}>
          <div className="mb-5 flex items-start gap-3">
            <span className="grid size-11 shrink-0 place-items-center rounded-full bg-(--color-info-soft) text-(--color-link)">
              <LockKeyhole size={23} aria-hidden="true" />
            </span>
            <div>
              <h2 className="text-xl font-bold">{t('password_title')}</h2>
              <p className="mt-1 text-sm leading-6 text-(--color-ink-muted)">
                {t('password_description')}
              </p>
            </div>
          </div>
          <Errors errors={password.errors} />
          <form onSubmit={savePassword} className="space-y-4">
            <Input
              label={t('current_password')}
              type="password"
              autoComplete="current-password"
              value={password.data.current_password}
              required
              error={password.errors.current_password}
              onChange={(event) => password.setData('current_password', event.target.value)}
            />
            <Input
              label={t('new_password')}
              type="password"
              autoComplete="new-password"
              value={password.data.password}
              required
              minLength={12}
              hint={t('password_hint')}
              error={password.errors.password}
              onChange={(event) => password.setData('password', event.target.value)}
            />
            <Input
              label={t('repeat_password')}
              type="password"
              autoComplete="new-password"
              value={password.data.password_confirmation}
              required
              minLength={12}
              error={password.errors.password_confirmation}
              onChange={(event) => password.setData('password_confirmation', event.target.value)}
            />
            <Button type="submit" className="w-full cursor-pointer" loading={password.processing}>
              {t('save_password')}
            </Button>
          </form>
        </section>
        <section className={panel}>
          <div className="flex items-start gap-3">
            <span className="grid size-11 shrink-0 place-items-center rounded-full bg-(--color-success-soft) text-(--color-success-ink)">
              <ShieldCheck size={24} aria-hidden="true" />
            </span>
            <div>
              <h2 className="text-xl font-bold">{t('mfa_title')}</h2>
              <div className="mt-2">
                <Badge tone={security.mfa_enabled ? 'success' : 'warning'}>
                  {t(security.mfa_enabled ? 'enabled' : 'disabled')}
                </Badge>
              </div>
            </div>
          </div>
          <p className="mt-5 text-sm leading-6 text-(--color-ink-muted)">{t('mfa_description')}</p>
          {!privateHistory && !security.mfa_enabled && (
            <p className="mt-3 text-sm leading-6 text-(--color-ink-muted)">
              {t('secure_mfa_required')}
            </p>
          )}
          <Button
            disabled={!security.mfa_enabled && !privateHistory}
            variant={security.mfa_enabled ? 'secondary' : 'primary'}
            className="mt-5 w-full cursor-pointer"
            onClick={() => {
              mfa.clearErrors();
              setMfaOpen(true);
            }}
          >
            {t(security.mfa_enabled ? 'mfa_disable' : 'mfa_enable')}
          </Button>
          <div className="mt-6 border-t border-(--color-border) pt-5">
            <div className="flex items-center gap-2">
              <KeyRound size={20} className="text-(--color-link)" aria-hidden="true" />
              <h3 className="text-base font-bold">{t('backup_title')}</h3>
            </div>
            <p className="mt-2 text-sm leading-6 text-(--color-ink-muted)">
              Сохраните одноразовые коды в безопасном месте. Создание нового набора отменит предыдущий и завершит другие сессии.
            </p>
            <Button variant="secondary" className="mt-4 cursor-pointer" disabled={!security.mfa_enabled || !privateHistory} onClick={() => { setRecoveryError(''); setRecoveryOpen(true); }}>
              Создать резервные коды
            </Button>
            {security.mfa_enabled && !privateHistory && typeof window !== 'undefined' && window.location.hostname === 'souzpir.local' && (
              <p className="mt-3 text-sm"><a className="cursor-pointer text-(--color-link) underline" href="/profile/security/mfa/recovery-local">Открыть локальное создание кодов</a></p>
            )}
          </div>
        </section>
      </div>
      <section className={`${panel} mt-5`}>
        <div className="mb-5 flex flex-wrap items-start justify-between gap-4">
          <div className="flex items-start gap-3">
            <Monitor size={25} className="mt-1 shrink-0 text-(--color-link)" aria-hidden="true" />
            <div>
              <h2 className="text-xl font-bold">{t('sessions_title')}</h2>
              <p className="mt-1 text-sm leading-6 text-(--color-ink-muted)">
                {t('sessions_description')}
              </p>
            </div>
          </div>
          <Button
            variant="secondary"
            className="max-w-full cursor-pointer whitespace-normal"
            disabled={!security.sessions.some((session) => !session.is_current)}
            onClick={() => setTargetSession('others')}
          >
            {t('end_others')}
          </Button>
        </div>
        {security.sessions.length === 0 ? (
          <EmptyState title={t('sessions_empty')} description={t('sessions_empty_description')} />
        ) : (
          <ul className="divide-y divide-(--color-border)">
            {security.sessions.map((session) => (
              <li
                key={session.id}
                className="grid gap-3 py-4 first:pt-0 md:grid-cols-[minmax(0,1fr)_minmax(150px,200px)_auto] md:items-center"
              >
                <div className="min-w-0">
                  <p className="break-words text-sm font-semibold">
                    {session.device || t('no_data')}
                  </p>
                  <p className="mt-1 text-sm text-(--color-ink-muted)">
                    {t('ip_address')}: {session.ip_address ?? '—'}
                  </p>
                </div>
                <div>
                  <p className="text-sm text-(--color-ink-muted)">{t('last_active')}</p>
                  <time dateTime={session.last_active_at} className="text-sm">
                    {dateTime(session.last_active_at, security.timezone)}
                  </time>
                </div>
                <div>
                  {session.is_current ? (
                    <Badge tone="success">{t('current_session')}</Badge>
                  ) : (
                    <Button
                      variant="secondary"
                      className="cursor-pointer text-(--color-danger-ink)"
                      onClick={() => setTargetSession(session.id)}
                    >
                      {t('end_session')}
                    </Button>
                  )}
                </div>
              </li>
            ))}
          </ul>
        )}
      </section>
      <Modal
        open={mfaOpen || !!setup}
        title={t(security.mfa_enabled ? 'mfa_disable_title' : 'mfa_setup_title')}
        onClose={closeMfa}
      >
        <Errors errors={mfa.errors} transport={feedback.transport} />
        <form onSubmit={submitMfa} className="space-y-4">
          {setup ? (
            <>
              <p className="leading-6 text-(--color-ink-muted)">{t('mfa_scan')}</p>
              <QrCode value={setup.otpauth_url} label={t('qr_label')} />
              <div className="rounded-xl bg-(--color-surface-muted) p-4">
                <p className="mb-2 font-semibold">{t('mfa_key')}</p>
                <code className="block break-all text-base select-all">{setup.secret}</code>
                <Button
                  variant="secondary"
                  className="mt-3 cursor-pointer"
                  onClick={() => void copyKey()}
                >
                  {t('copy_key')}
                </Button>
                <p role="status" className="mt-2 text-sm">
                  {copyStatus}
                </p>
              </div>
              <p className="text-sm text-(--color-ink-muted)">
                {t('setup_expires')} {dateTime(setup.expires_at, security.timezone)}
              </p>
            </>
          ) : (
            <>
              <p className="leading-6 text-(--color-ink-muted)">
                {t(security.mfa_enabled ? 'mfa_disable_hint' : 'mfa_password_hint')}
              </p>
              <Input
                label={t('current_password')}
                type="password"
                autoComplete="current-password"
                required
                value={mfa.data.password}
                error={mfa.errors.password}
                onChange={(event) => mfa.setData('password', event.target.value)}
              />
            </>
          )}
          {(setup || security.mfa_enabled) && (
            <Input
              label={t('code')}
              autoComplete="one-time-code"
              inputMode="numeric"
              pattern="[0-9]{6}"
              maxLength={6}
              required
              value={mfa.data.code}
              hint={t('code_hint')}
              error={mfa.errors.code}
              onChange={(event) => mfa.setData('code', event.target.value)}
            />
          )}
          <div className="flex flex-wrap justify-end gap-2">
            <Button
              variant="secondary"
              className="cursor-pointer"
              disabled={mfa.processing}
              onClick={closeMfa}
            >
              {t('cancel')}
            </Button>
            <Button
              type="submit"
              className="cursor-pointer"
              variant={security.mfa_enabled ? 'danger' : 'primary'}
              loading={mfa.processing}
            >
              {t(security.mfa_enabled ? 'mfa_disable' : setup ? 'confirm_mfa' : 'continue')}
            </Button>
          </div>
        </form>
      </Modal>
      <Modal
        open={recoveryOpen}
        title="Резервные коды"
        onClose={() => { if (!recoveryBusy) { setRecoveryCodes([]); setRecoveryInput(''); setRecoveryError(''); setRecoveryOpen(false); } }}
      >
        {recoveryCodes.length ? (
          <div className="space-y-4">
            <p>Сохраните коды сейчас. Каждый код работает один раз; повторно показать их нельзя.</p>
            <ul className="grid gap-2 rounded-xl bg-(--color-surface-muted) p-4 font-mono text-sm sm:grid-cols-2">
              {recoveryCodes.map((code) => <li key={code}>{code}</li>)}
            </ul>
            <Button onClick={() => { setRecoveryCodes([]); setRecoveryOpen(false); }}>Готово</Button>
          </div>
        ) : (
          <form onSubmit={(event) => void issueRecovery(event)} className="space-y-4">
            <p>Введите текущий код приложения-аутентификатора. Если вы только что использовали его, дождитесь нового кода. Предыдущие резервные коды перестанут действовать.</p>
            <Input label="Код приложения" inputMode="numeric" autoComplete="one-time-code" pattern="[0-9]{6}" maxLength={6} required value={recoveryInput} onChange={(event) => setRecoveryInput(event.target.value)} />
            <Errors transport={recoveryError} />
            <Button type="submit" loading={recoveryBusy}>Создать коды</Button>
          </form>
        )}
      </Modal>
      <Modal
        open={!!targetSession}
        title={t(targetSession === 'others' ? 'end_others_title' : 'end_session_title')}
        onClose={() => !sessions.processing && setTargetSession(null)}
      >
        <p className="mb-5 leading-6 text-(--color-ink-muted)">{t('end_description')}</p>
        <Errors errors={sessions.errors} transport={feedback.transport} />
        <div className="flex flex-wrap justify-end gap-2">
          <Button
            variant="secondary"
            className="cursor-pointer"
            disabled={sessions.processing}
            onClick={() => setTargetSession(null)}
          >
            {t('cancel')}
          </Button>
          <Button
            variant="danger"
            className="cursor-pointer"
            loading={sessions.processing}
            onClick={endSessions}
          >
            {t('end_session')}
          </Button>
        </div>
      </Modal>
    </ProfilePage>
  );
}

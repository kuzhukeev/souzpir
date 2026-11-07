import { useEffect, useRef, useState, type FormEvent } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import { supportsPrivateHistory } from '@/features/Shell/Profile/browser';
import { finishProfileContextRecovery, pendingProfileContextChanges, profileContextIsStable, subscribeProfileContext } from '@/features/Shell/Profile/contextFence';
import { Camera, CircleUserRound, Mail, ShieldCheck } from 'lucide-react';
import { Badge, Button, Combobox, Input, Modal, Select } from '@/components/ui';
import { Errors, Notice, ProfilePage, panel } from '@/features/Shell/Profile/shared';
import { useFeedback, useProfileText } from '@/features/Shell/Profile/hooks';
import type { ProfileData } from '@/types/Shell/Profile';
import type { profileCopy } from '@/features/Shell/Profile/copy';

type Props = {
  profile: ProfileData;
  timezones: string[];
  profileBrandRevision: string;
  status?: string | null;
  emailConfirmationUrl?: string;
};

// A response may have been generated before a switch but arrive afterward.
// Verify its revision over a fresh request before rendering private content.
export default function Profile(props: Props) {
  const { version } = usePage();
  const [verified, setVerified] = useState<{ revision: string; profile: ProfileData } | null>(null);
  const [failed, setFailed] = useState(false);
  const [recovering, setRecovering] = useState(false);
  const [recoveryError, setRecoveryError] = useState('');
  const recover = async () => {
    const markers = pendingProfileContextChanges();
    const cookie = document.cookie.split('; ').find((item) => item.startsWith('XSRF-TOKEN='));
    if (!markers.length || !cookie) { setRecoveryError('Не удалось восстановить доступ. Войдите снова.'); return; }
    setRecovering(true);
    setRecoveryError('');
    try {
      const response = await fetch('/brand/switch', {
        method: 'POST', credentials: 'same-origin', redirect: 'error', cache: 'no-store',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(cookie.slice(11)) },
        body: JSON.stringify({ recover_context: true }),
      });
      const result: unknown = await response.json();
      if (!response.ok || typeof result !== 'object' || result === null || !('status' in result) || result.status !== 'recovered') throw new Error();
      finishProfileContextRecovery(markers);
      window.location.reload();
    } catch { setRecoveryError('Не удалось подтвердить восстановление. Войдите снова или повторите попытку.'); }
    finally { setRecovering(false); }
  };
  useEffect(() => {
    let sequence = 0;
    let current: AbortController | undefined;
    let disposed = false;
    const check = () => {
      const attempt = ++sequence;
      current?.abort();
      setVerified(null);
      setFailed(false);
      const fail = () => { if (!disposed && attempt === sequence) setFailed(true); };
      if (typeof props.profileBrandRevision !== 'string' || !/^[a-f0-9]{64}$/.test(props.profileBrandRevision) || !profileContextIsStable() || document.visibilityState === 'hidden') { fail(); return; }
      const controller = new AbortController();
      current = controller;
      const timeout = window.setTimeout(() => controller.abort(), 10000);
      void fetch('/profile', {
        credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
        headers: { Accept: 'text/html, application/xhtml+xml', 'X-Requested-With': 'XMLHttpRequest', 'X-Inertia': 'true', 'X-Inertia-Version': version ?? '' },
      }).then(async (response) => {
        if (!response.ok || response.headers.get('X-Inertia') !== 'true') { fail(); return; }
        const page: unknown = await response.json();
        if (typeof page !== 'object' || page === null || !('props' in page)) { fail(); return; }
        const pageProps = (page as { props?: { profileBrandRevision?: unknown } }).props;
        if (!disposed && attempt === sequence && pageProps?.profileBrandRevision === props.profileBrandRevision && profileContextIsStable() && document.visibilityState !== 'hidden') setVerified({ revision: props.profileBrandRevision, profile: props.profile });
        else fail();
      }).catch(fail).finally(() => window.clearTimeout(timeout));
    };
    const hide = () => document.querySelectorAll<HTMLElement>('[data-private-profile]').forEach((element) => { element.hidden = true; });
    const visibility = () => { if (document.visibilityState === 'hidden') hide(); check(); };
    const pageHide = () => { hide(); sequence++; current?.abort(); setVerified(null); };
    const pageShow = (event: PageTransitionEvent) => { if (event.persisted) check(); };
    const unsubscribe = subscribeProfileContext(check);
    document.addEventListener('visibilitychange', visibility);
    window.addEventListener('pagehide', pageHide);
    window.addEventListener('pageshow', pageShow);
    check();
    return () => { disposed = true; sequence++; current?.abort(); unsubscribe(); document.removeEventListener('visibilitychange', visibility); window.removeEventListener('pagehide', pageHide); window.removeEventListener('pageshow', pageShow); };
  }, [props.profileBrandRevision, props.profile, version]);

  if (verified?.revision !== props.profileBrandRevision || verified.profile !== props.profile) return <main className="min-h-dvh bg-(--color-bg) p-6 text-(--color-ink)"><div role="status" className={`${panel} mx-auto max-w-[800px] text-sm`}>{failed ? 'Не удалось подтвердить доступ к профилю.' : 'Проверяем доступ к профилю.'}{failed && pendingProfileContextChanges().length > 0 && <Button className="mt-4" loading={recovering} onClick={() => void recover()}>Восстановить доступ</Button>}{recoveryError && <p role="alert" className="mt-2">{recoveryError}</p>}</div></main>;
  return <div data-private-profile><ProfileContent {...props} /></div>;
}

function ProfileContent({
  profile,
  timezones,
  status,
  emailConfirmationUrl,
  profileBrandRevision,
}: Props) {
  const t = useProfileText();
  const privateHistory = supportsPrivateHistory();
  const form = useForm({
    name: profile.name,
    phone: profile.phone ?? '',
    timezone: profile.timezone ?? '',
    language: profile.language,
  });
  const avatar = useForm<{ avatar: File | null }>({ avatar: null });
  const email = useForm({ email: '', current_password: '' });
  const confirmation = useForm({});
  const [emailOpen, setEmailOpen] = useState(false);
  const feedback = useFeedback();
  const fileInput = useRef<HTMLInputElement>(null);
  const roleKey = ['owner', 'admin', 'manager', 'member', 'viewer', 'specialist'].includes(
    profile.role,
  )
    ? (profile.role as keyof typeof profileCopy)
    : null;
  const initials = profile.name
    .trim()
    .split(/\s+/)
    .slice(0, 2)
    .map((part) => part[0] ?? '')
    .join('')
    .toLocaleUpperCase('ru');
  const save = (event: FormEvent) => {
    event.preventDefault();
    feedback.clear();
    form.put('/profile', {
      preserveScroll: true,
      headers: { 'X-Brand-Revision': profileBrandRevision },
      onSuccess: () => feedback.setNotice(t('saved')),
      onNetworkError: feedback.failure,
      onHttpException: feedback.failure,
    });
  };
  const upload = (file: File | undefined) => {
    if (!file) return;
    feedback.clear();
    avatar.transform(() => ({ avatar: file }));
    avatar.post('/profile/avatar', {
      forceFormData: true,
      preserveScroll: true,
      onSuccess: () => feedback.setNotice(t('avatar_updated')),
      onFinish: () => {
        avatar.reset();
        if (fileInput.current) fileInput.current.value = '';
      },
      onNetworkError: feedback.failure,
      onHttpException: feedback.failure,
    });
  };
  const removeAvatar = () => {
    feedback.clear();
    avatar.delete('/profile/avatar', {
      preserveScroll: true,
      onSuccess: () => feedback.setNotice(t('avatar_removed')),
      onNetworkError: feedback.failure,
      onHttpException: feedback.failure,
    });
  };
  const changeEmail = (event: FormEvent) => {
    event.preventDefault();
    if (!privateHistory || !profile.email_confirmation_secure) return;
    feedback.clear();
    email.post('/profile/email', {
      preserveScroll: true,
      onSuccess: () => {
        setEmailOpen(false);
        email.reset();
      },
      onFinish: () => email.reset('current_password'),
      onNetworkError: feedback.failure,
      onHttpException: feedback.failure,
    });
  };
  const cancelEmail = () => {
    feedback.clear();
    confirmation.delete('/profile/email', {
      preserveScroll: true,
      onNetworkError: feedback.failure,
      onHttpException: feedback.failure,
    });
  };
  const confirmEmail = () => {
    if (!emailConfirmationUrl || !privateHistory || !profile.email_confirmation_secure) return;
    feedback.clear();
    confirmation.post(emailConfirmationUrl, {
      preserveScroll: true,
      onNetworkError: feedback.failure,
      onHttpException: feedback.failure,
    });
  };
  return (
    <ProfilePage active="profile">
      <Notice>{feedback.notice || status}</Notice>
      <Errors
        errors={{ ...form.errors, ...avatar.errors, ...confirmation.errors }}
        transport={feedback.transport}
      />
      {emailConfirmationUrl && (
        <section className={`${panel} mb-5 border-(--color-action)`}>
          <h2 className="text-xl font-bold">{t('confirm_email_title')}</h2>
          <p className="mt-2 text-sm leading-6">{t('confirm_email_description')}</p>
          <p className="mt-2 break-all text-base font-semibold">{profile.pending_email}</p>
          <Button
            className="mt-4 cursor-pointer"
            loading={confirmation.processing}
            disabled={!privateHistory || !profile.email_confirmation_secure}
            onClick={confirmEmail}
          >
            {t('confirm_email')}
          </Button>
        </section>
      )}
      <div className="grid items-start gap-5 xl:grid-cols-[minmax(0,1fr)_320px]">
        <section className={panel}>
          <h2 className="text-xl font-bold">{t('personal_data')}</h2>
          <div className="my-6 flex flex-wrap items-center gap-5">
            <div className="grid size-20 shrink-0 place-items-center overflow-hidden rounded-full bg-(--color-action) text-2xl font-bold text-(--color-on-action)">
              {profile.avatar_url ? (
                <img
                  src={profile.avatar_url}
                  alt={profile.name}
                  className="size-full object-cover"
                />
              ) : (
                <span aria-hidden="true">{initials || <CircleUserRound size={40} />}</span>
              )}
            </div>
            <div className="min-w-0 flex-1">
              <input
                ref={fileInput}
                type="file"
                accept="image/png,image/jpeg,image/webp"
                aria-label={t('upload_photo')}
                className="sr-only"
                tabIndex={-1}
                onChange={(event) => upload(event.target.files?.[0])}
              />
              <div className="flex flex-wrap gap-2">
                <Button
                  variant="secondary"
                  className="cursor-pointer"
                  loading={avatar.processing}
                  onClick={() => fileInput.current?.click()}
                >
                  <Camera size={18} aria-hidden="true" />
                  {t('upload_photo')}
                </Button>
                {profile.avatar_url && (
                  <Button
                    variant="ghost"
                    className="cursor-pointer"
                    onClick={removeAvatar}
                    disabled={avatar.processing}
                  >
                    {t('remove_photo')}
                  </Button>
                )}
              </div>
              <p className="mt-2 text-sm leading-6 text-(--color-ink-muted)">{t('avatar_hint')}</p>
            </div>
          </div>
          <form onSubmit={save} className="space-y-5">
            <div className="grid gap-5 sm:grid-cols-2">
              <Input
                label={t('name')}
                autoComplete="name"
                value={form.data.name}
                error={form.errors.name}
                maxLength={120}
                required
                onChange={(event) => form.setData('name', event.target.value)}
              />
              <div>
                <label
                  className="mb-1.5 block text-sm font-semibold"
                  htmlFor="profile-current-email"
                >
                  {t('email_address')}
                </label>
                <div className="flex flex-wrap items-center gap-2 rounded-lg border border-(--color-control-border) p-2">
                  <input
                    id="profile-current-email"
                    type="email"
                    value={profile.email}
                    readOnly
                    className="min-w-0 flex-1 bg-transparent px-1 py-1 text-sm outline-none"
                  />
                  <Button
                    variant="ghost"
                    size="sm"
                    className="cursor-pointer"
                    disabled={
                      !profile.email_change_available ||
                      !profile.email_confirmation_secure ||
                      !privateHistory
                    }
                    onClick={() => {
                      email.clearErrors();
                      setEmailOpen(true);
                    }}
                  >
                    {t('change_email')}
                  </Button>
                </div>
                <p className="mt-1.5 text-sm text-(--color-ink-muted)">
                  {t(
                    !privateHistory
                      ? 'secure_email_required'
                      : !profile.email_confirmation_secure
                        ? 'email_confirmation_insecure'
                        : profile.email_change_available
                          ? 'email_hint'
                          : 'email_unavailable',
                  )}
                </p>
              </div>
              <Input
                label={t('phone')}
                type="tel"
                autoComplete="tel"
                value={form.data.phone}
                error={form.errors.phone}
                maxLength={40}
                onChange={(event) => form.setData('phone', event.target.value)}
              />
              <Select
                label={t('language')}
                options={[{ value: 'ru', label: t('russian') }]}
                value="ru"
                onValueChange={() => form.setData('language', 'ru')}
                hint={t('english_later')}
              />
            </div>
            <div>
              <Combobox
                label={t('timezone')}
                value={form.data.timezone || `${t('timezone_default')} · ${profile.brand_timezone}`}
                options={[`${t('timezone_default')} · ${profile.brand_timezone}`, ...timezones]}
                onValueChange={(value) =>
                  form.setData('timezone', timezones.includes(value) ? value : '')
                }
              />
              <p
                className={`mt-1.5 text-sm ${form.errors.timezone ? 'text-(--color-danger-ink)' : 'text-(--color-ink-muted)'}`}
              >
                {form.errors.timezone || t('timezone_hint')}
              </p>
            </div>
            <Button
              type="submit"
              className="w-full cursor-pointer sm:w-auto"
              loading={form.processing}
            >
              {t('save')}
            </Button>
          </form>
          {profile.pending_email && !emailConfirmationUrl && (
            <div className="mt-6 rounded-xl border border-(--color-border) bg-(--color-info-soft) p-4">
              <div className="flex gap-2">
                <Mail size={20} className="shrink-0 text-(--color-link)" aria-hidden="true" />
                <p className="text-sm">
                  {t('pending_email')}:{' '}
                  <strong className="break-all">{profile.pending_email}</strong>
                </p>
              </div>
              <Button
                variant="secondary"
                className="mt-3 cursor-pointer"
                loading={confirmation.processing}
                onClick={cancelEmail}
              >
                {t('cancel_email')}
              </Button>
            </div>
          )}
        </section>
        <aside className={panel}>
          <div className="flex flex-wrap items-center gap-3">
            <ShieldCheck size={22} className="text-(--color-link)" aria-hidden="true" />
            <h2 className="text-xl font-bold">{t('role_and_company')}</h2>
          </div>
          <div className="mt-3">
            <Badge>{t('readonly')}</Badge>
          </div>
          <dl className="mt-5 space-y-5">
            <div>
              <dt className="text-sm text-(--color-ink-muted)">{t('role')}</dt>
              <dd className="mt-2 rounded-lg bg-(--color-surface-muted) p-3 text-sm font-semibold">
                {roleKey ? t(roleKey) : profile.role}
              </dd>
            </div>
            <div>
              <dt className="text-sm text-(--color-ink-muted)">{t('company')}</dt>
              <dd className="mt-2 break-words rounded-lg bg-(--color-surface-muted) p-3 text-sm font-semibold">
                {profile.organization_name}
              </dd>
            </div>
          </dl>
        </aside>
      </div>
      <Modal
        open={emailOpen}
        title={t('change_email')}
        onClose={() => {
          if (!email.processing) {
            setEmailOpen(false);
            email.reset('current_password');
          }
        }}
      >
        <p className="mb-5 leading-6 text-(--color-ink-muted)">{t('email_change_description')}</p>
        <Errors errors={email.errors} transport={feedback.transport} />
        <form onSubmit={changeEmail} className="space-y-4">
          <Input
            label={t('new_email')}
            type="email"
            autoComplete="email"
            required
            value={email.data.email}
            error={email.errors.email}
            onChange={(event) => email.setData('email', event.target.value)}
          />
          <Input
            label={t('current_password')}
            type="password"
            autoComplete="current-password"
            required
            value={email.data.current_password}
            error={email.errors.current_password}
            onChange={(event) => email.setData('current_password', event.target.value)}
          />
          <div className="flex flex-wrap justify-end gap-2">
            <Button
              variant="secondary"
              className="cursor-pointer"
              disabled={email.processing}
              onClick={() => {
                setEmailOpen(false);
                email.reset('current_password');
              }}
            >
              {t('cancel')}
            </Button>
            <Button type="submit" className="cursor-pointer" loading={email.processing}>
              {t('send_confirmation')}
            </Button>
          </div>
        </form>
      </Modal>
    </ProfilePage>
  );
}

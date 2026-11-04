import { Link, useForm } from '@inertiajs/react';
import { Mail } from 'lucide-react';
import { useEffect, useState, type FormEvent } from 'react';
import { Button } from '@/components/ui';
import { AuthFrame, Field, linkClass } from '@/features/Shell/Auth/AuthFrame';

type Props = {
  email: string;
  confirmationUrl?: string;
  mailConfigured?: boolean;
  status?: string;
  idempotencyKey?: string;
  resendAfter?: number;
  verified?: boolean;
  continueUrl?: string;
};

export default function VerifyEmail({
  email,
  confirmationUrl,
  mailConfigured = false,
  status,
  idempotencyKey = '',
  resendAfter = 0,
  verified = false,
  continueUrl = '/auth/ready',
}: Props) {
  const [now, setNow] = useState(() => Math.floor(Date.now() / 1000));
  const [changing, setChanging] = useState(false);
  const emailForm = useForm({ email });
  useEffect(() => {
    if (verified || confirmationUrl || resendAfter <= now) return;
    const timer = window.setInterval(() => setNow(Math.floor(Date.now() / 1000)), 1000);
    return () => window.clearInterval(timer);
  }, [verified, confirmationUrl, resendAfter, now]);
  const seconds = Math.max(0, resendAfter - now);
  const changeEmail = (event: FormEvent) => {
    event.preventDefault();
    emailForm.post('/email/change', { onSuccess: () => setChanging(false) });
  };
  if (verified) {
    return (
      <AuthFrame title="Почта подтверждена" subtitle="Теперь можно продолжить работу в кабинете.">
        <Link href={continueUrl} className="inline-flex min-h-12 w-full cursor-pointer items-center justify-center rounded-(--radius-control) bg-(--color-action) px-4 text-sm font-semibold text-white">
          Продолжить
        </Link>
      </AuthFrame>
    );
  }
  return (
    <AuthFrame title="Подтвердите почту" subtitle="Подтверждение защищает доступ к кабинету.">
      <div className="mb-5 grid size-14 place-items-center rounded-full bg-(--color-brand-primary-soft) text-(--color-icon)">
        <Mail size={26} />
      </div>
      <p className="text-sm leading-6 text-(--color-ink-muted)">
        {mailConfigured ? 'Письмо отправлено на' : 'Адрес для подтверждения:'} <strong className="text-(--color-ink)">{email}</strong>
      </p>
      {status && <p role="status" className="mt-4 text-sm text-(--color-action)">{status}</p>}
      {!mailConfigured && !confirmationUrl && (
        <p className="mt-4 text-sm text-(--color-ink-muted)">
          Почтовый сервис пока не подключён. Обратитесь к администратору.
        </p>
      )}
      {confirmationUrl ? (
        <VerificationForm confirmationUrl={confirmationUrl} idempotencyKey={idempotencyKey} />
      ) : (
        <>
          <VerificationForm idempotencyKey={idempotencyKey} seconds={seconds} />
          <p role="status" className="mt-2 min-h-5 text-center text-sm text-(--color-ink-muted)">
            {seconds > 0 ? `Повторная отправка через ${seconds} секунд` : ''}
          </p>
          <button type="button" onClick={() => setChanging(!changing)} className={`mt-4 cursor-pointer ${linkClass}`}>
            Изменить почту
          </button>
          {changing && (
            <form onSubmit={changeEmail} className="mt-4 space-y-3">
              <Field label="Новая рабочая почта" type="email" autoComplete="email" required value={emailForm.data.email} onChange={(event) => emailForm.setData('email', event.target.value)} error={emailForm.errors.email} />
              <Button type="submit" loading={emailForm.processing} className="min-h-12 w-full">Сохранить почту</Button>
            </form>
          )}
        </>
      )}
    </AuthFrame>
  );
}

function VerificationForm({ confirmationUrl, idempotencyKey, seconds = 0 }: { confirmationUrl?: string; idempotencyKey: string; seconds?: number }) {
  const form = useForm({ idempotency_key: idempotencyKey });
  const submit = (event: FormEvent) => {
    event.preventDefault();
    form.post(confirmationUrl || '/email/verification-notification');
  };
  return (
    <form onSubmit={submit} className="mt-6">
      <Button type="submit" loading={form.processing} disabled={seconds > 0} className="min-h-12 w-full">
        {confirmationUrl ? 'Подтвердить адрес' : 'Отправить ещё раз'}
      </Button>
    </form>
  );
}

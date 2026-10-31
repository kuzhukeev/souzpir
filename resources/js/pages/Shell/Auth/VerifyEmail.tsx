import { useForm } from '@inertiajs/react';
import { Mail } from 'lucide-react';
import { Button } from '@/components/ui';
import { AuthFrame } from '@/features/Shell/Auth/AuthFrame';
import type { FormEvent } from 'react';

export default function VerifyEmail({
  email,
  confirmationUrl,
  mailConfigured,
  status,
}: {
  email: string;
  confirmationUrl?: string;
  mailConfigured: boolean;
  status?: string;
}) {
  const form = useForm({});
  const submit = (event: FormEvent) => {
    event.preventDefault();
    form.post(confirmationUrl || '/email/verification-notification');
  };
  return (
    <AuthFrame title="Подтвердите почту" subtitle="Подтверждение защищает доступ к кабинету.">
      <div className="mb-5 grid size-14 place-items-center rounded-full bg-(--color-brand-primary-soft) text-(--color-icon)">
        <Mail size={26} />
      </div>
      <p className="text-sm leading-6 text-(--color-ink-muted)">
        Адрес: <strong className="text-(--color-ink)">{email}</strong>
      </p>
      {status && (
        <p role="status" className="mt-4 text-sm text-(--color-action)">
          {status}
        </p>
      )}
      {!mailConfigured && !confirmationUrl && (
        <p className="mt-4 text-sm text-(--color-ink-muted)">
          Почтовый сервис пока не подключён. Обратитесь к администратору.
        </p>
      )}
      <form onSubmit={submit} className="mt-6">
        <Button type="submit" loading={form.processing} className="min-h-12 w-full">
          {confirmationUrl ? 'Подтвердить адрес' : 'Отправить ещё раз'}
        </Button>
      </form>
    </AuthFrame>
  );
}

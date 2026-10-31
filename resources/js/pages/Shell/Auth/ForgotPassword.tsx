import { Link, useForm } from '@inertiajs/react';
import { Button } from '@/components/ui';
import { AuthFrame, Field, linkClass } from '@/features/Shell/Auth/AuthFrame';
import type { FormEvent } from 'react';

export default function ForgotPassword({
  mailConfigured,
  status,
}: {
  mailConfigured: boolean;
  status?: string;
}) {
  const form = useForm({ email: '' });
  const submit = (event: FormEvent) => {
    event.preventDefault();
    form.post('/forgot-password');
  };
  return (
    <AuthFrame
      title="Забыли пароль?"
      subtitle="Укажите рабочую почту, и мы поможем восстановить доступ."
    >
      {status && (
        <p
          role="status"
          className="mb-5 rounded-lg bg-(--color-success-soft) p-3 text-sm text-(--color-success-ink)"
        >
          {status}
        </p>
      )}
      {!mailConfigured && (
        <p className="mb-5 text-sm text-(--color-ink-muted)">
          Почтовый сервис пока не подключён. Для восстановления обратитесь к администратору.
        </p>
      )}
      <form onSubmit={submit} className="space-y-5">
        <Field
          label="Рабочая почта"
          type="email"
          autoComplete="email"
          required
          value={form.data.email}
          onChange={(e) => form.setData('email', e.target.value)}
          error={form.errors.email}
        />
        <Button type="submit" loading={form.processing} className="min-h-12 w-full">
          Отправить ссылку
        </Button>
      </form>
      <p className="mt-6 text-center text-sm">
        <Link href="/login" className={linkClass}>
          Вернуться ко входу
        </Link>
      </p>
    </AuthFrame>
  );
}

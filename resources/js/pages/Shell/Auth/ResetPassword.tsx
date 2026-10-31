import { Link, useForm } from '@inertiajs/react';
import { Button } from '@/components/ui';
import { AuthFrame, Field, linkClass } from '@/features/Shell/Auth/AuthFrame';
import type { FormEvent } from 'react';

export default function ResetPassword({ token, email }: { token: string; email: string }) {
  const form = useForm({ token, email, password: '', password_confirmation: '' });
  const submit = (event: FormEvent) => {
    event.preventDefault();
    form.post('/reset-password', {
      onFinish: () => form.reset('password', 'password_confirmation'),
    });
  };
  return (
    <AuthFrame title="Новый пароль" subtitle="Придумайте надёжный пароль для входа.">
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
        <Field
          label="Новый пароль"
          type="password"
          autoComplete="new-password"
          minLength={12}
          required
          value={form.data.password}
          onChange={(e) => form.setData('password', e.target.value)}
          error={form.errors.password}
        />
        <Field
          label="Повторите пароль"
          type="password"
          autoComplete="new-password"
          minLength={12}
          required
          value={form.data.password_confirmation}
          onChange={(e) => form.setData('password_confirmation', e.target.value)}
          error={form.errors.password_confirmation}
        />
        <Button type="submit" loading={form.processing} className="min-h-12 w-full">
          Сохранить пароль
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

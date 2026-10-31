import { Link, useForm } from '@inertiajs/react';
import { Button } from '@/components/ui';
import { AuthFrame, Check, Field, linkClass } from '@/features/Shell/Auth/AuthFrame';
import type { FormEvent } from 'react';

export default function Login() {
  const form = useForm({ email: '', password: '', remember: false });
  const submit = (event: FormEvent) => {
    event.preventDefault();
    form.post('/login', { onFinish: () => form.reset('password') });
  };
  return (
    <AuthFrame title="Вход в кабинет" subtitle="Рады видеть вас снова">
      <form onSubmit={submit} className="space-y-5">
        <Field
          label="Рабочая почта"
          type="email"
          autoComplete="username"
          required
          value={form.data.email}
          onChange={(e) => form.setData('email', e.target.value)}
          error={form.errors.email}
        />
        <Field
          label="Пароль"
          type="password"
          autoComplete="current-password"
          required
          value={form.data.password}
          onChange={(e) => form.setData('password', e.target.value)}
          error={form.errors.password}
        />
        <div className="flex flex-wrap items-center justify-between gap-3">
          <Check
            label="Запомнить меня"
            checked={form.data.remember}
            onChange={(e) => form.setData('remember', e.target.checked)}
          />
          <Link href="/forgot-password" className={`${linkClass} text-sm`}>
            Забыли пароль?
          </Link>
        </div>
        <Button type="submit" loading={form.processing} className="min-h-12 w-full">
          Войти
        </Button>
      </form>
      <p className="mt-6 border-t border-(--color-border) pt-5 text-center text-sm text-(--color-ink-muted)">
        Нет аккаунта?{' '}
        <Link href="/register" className={linkClass}>
          Создать аккаунт
        </Link>
      </p>
    </AuthFrame>
  );
}

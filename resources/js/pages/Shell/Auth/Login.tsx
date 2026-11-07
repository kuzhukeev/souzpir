import { Link, useForm } from '@inertiajs/react';
import { Check as CheckIcon } from 'lucide-react';
import { Button } from '@/components/ui';
import { AuthFrame, Check, Field, linkClass } from '@/features/Shell/Auth/AuthFrame';
import { useEffect, useState, type FormEvent } from 'react';

export default function Login({ status, lockoutEmail = '', lockoutSeconds = 0 }: { status?: string | null; lockoutEmail?: string; lockoutSeconds?: number }) {
  const form = useForm({ email: lockoutEmail, password: '', remember: false });
  const [remaining, setRemaining] = useState(lockoutSeconds);
  useEffect(() => {
    const timer = window.setTimeout(() => setRemaining(lockoutSeconds), 0);
    return () => window.clearTimeout(timer);
  }, [lockoutSeconds]);
  useEffect(() => {
    if (remaining <= 0) return;
    const timer = window.setTimeout(() => setRemaining((value) => Math.max(0, value - 1)), 1000);
    return () => window.clearTimeout(timer);
  }, [remaining]);
  const locked = remaining > 0 && form.data.email.trim().toLowerCase() === lockoutEmail;
  const submit = (event: FormEvent) => {
    event.preventDefault();
    form.post('/login', { onFinish: () => form.reset('password') });
  };
  if (status === 'Пароль изменён. Войдите снова.') {
    return <AuthFrame centered leading={<div aria-hidden="true" className="mx-auto mb-5 flex size-20 items-center justify-center rounded-full bg-(--color-success-soft) text-(--color-success-ink)"><CheckIcon className="size-10"/></div>} title="Пароль изменён" subtitle="Теперь вы можете войти с новым паролем."><p role="status" className="text-center text-sm text-(--color-success-ink)">{status}</p><Link href="/login" className="mt-5 inline-flex min-h-12 w-full cursor-pointer items-center justify-center rounded-lg bg-(--color-action) px-4 font-semibold text-white">Войти в кабинет</Link></AuthFrame>;
  }
  return (
    <AuthFrame title="Вход в кабинет" subtitle="Рады видеть вас снова">
      {status && <p role="status" className="mb-5 rounded-lg bg-(--color-success-soft) p-3 text-sm">{status}</p>}
      {!locked && form.errors.email === 'Неверная почта или пароль' && <p role="alert" className="mb-5 rounded-lg bg-(--color-danger-soft) p-3 text-sm text-(--color-danger-ink)">Неверная почта или пароль</p>}
      {locked && <p role="alert" className="mb-5 rounded-lg bg-(--color-warning-soft) p-3 text-sm text-(--color-warning-ink)">Слишком много попыток. Повторите через {Math.ceil(remaining / 60)} мин.</p>}
      <form onSubmit={submit} className="space-y-5">
        <Field
          label="Рабочая почта"
          type="email"
          autoComplete="username"
          required
          value={form.data.email}
          onChange={(e) => form.setData('email', e.target.value)}
          error={form.errors.email === 'Неверная почта или пароль' ? undefined : form.errors.email}
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
        <Button type="submit" disabled={locked} loading={form.processing} className="min-h-12 w-full">
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

import { Link, useForm } from '@inertiajs/react';
import { Button } from '@/components/ui';
import { AuthFrame, Check, Field, linkClass } from '@/features/Shell/Auth/AuthFrame';
import type { FormEvent } from 'react';

const roleNames: Record<string, string> = {
  owner: 'Владелец',
  admin: 'Администратор',
  member: 'Участник',
  viewer: 'Наблюдатель',
};
export default function Invitation({
  token,
  email,
  role,
  authenticated,
  verified,
}: {
  token: string;
  email: string;
  role: string;
  authenticated: boolean;
  verified: boolean;
}) {
  const form = useForm({ name: '', password: '', password_confirmation: '', terms: false });
  const submit = (event: FormEvent) => {
    event.preventDefault();
    form.post(
      `/invitations/${encodeURIComponent(token)}/${authenticated ? 'accept' : 'register'}`,
      { onFinish: () => form.reset('password', 'password_confirmation') },
    );
  };
  return (
    <AuthFrame title="Приглашение в команду" subtitle="Присоединитесь к рабочему пространству">
      <div className="mb-5 rounded-xl bg-(--color-bg) p-4 text-sm leading-6">
        <p>
          Вы приглашены как <strong>{roleNames[role] || 'участник'}</strong>.
        </p>
        <p className="text-(--color-ink-muted)">
          Электронная почта: <strong className="text-(--color-ink)">{email}</strong>
        </p>
      </div>
      {authenticated && !verified ? (
        <p className="text-sm">
          Перед принятием приглашения{' '}
          <Link href="/email/verify" className={linkClass}>
            подтвердите почту
          </Link>
          .
        </p>
      ) : (
        <form onSubmit={submit} className="space-y-4">
          {!authenticated && (
            <>
              <Field
                label="Имя"
                autoComplete="name"
                required
                value={form.data.name}
                onChange={(e) => form.setData('name', e.target.value)}
                error={form.errors.name}
              />
              <Field
                label="Пароль"
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
              <Check
                label="Я принимаю условия использования и обработки данных"
                required
                checked={form.data.terms}
                onChange={(e) => form.setData('terms', e.target.checked)}
                error={form.errors.terms}
              />
            </>
          )}
          <Button type="submit" loading={form.processing} className="min-h-12 w-full">
            {authenticated ? 'Принять приглашение' : 'Создать аккаунт'}
          </Button>
        </form>
      )}
      {!authenticated && (
        <p className="mt-6 border-t border-(--color-border) pt-5 text-center text-sm text-(--color-ink-muted)">
          Уже есть аккаунт?{' '}
          <Link href="/login" className={linkClass}>
            Войти
          </Link>
        </p>
      )}
    </AuthFrame>
  );
}

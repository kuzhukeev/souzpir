import { Link, useForm } from '@inertiajs/react';
import { Button } from '@/components/ui';
import { AuthFrame, Check, Field, linkClass } from '@/features/Shell/Auth/AuthFrame';
import type { FormEvent } from 'react';

const roleNames: Record<string, string> = {
  owner: 'Владельца',
  admin: 'Администратора',
  member: 'Редактора',
  viewer: 'Наблюдателя',
};
const states: Record<string, { title: string; message: string }> = {
  expired: { title: 'Срок приглашения истёк', message: 'Ссылка действует 7 дней. Попросите отправить новое приглашение.' },
  revoked: { title: 'Приглашение отозвано', message: 'Попросите отправить новое приглашение.' },
  accepted: { title: 'Приглашение уже принято', message: 'Войдите в аккаунт, чтобы продолжить работу.' },
  email_mismatch: { title: 'Почта не совпадает', message: 'Выйдите из текущего аккаунта и войдите с адресом, указанным в приглашении.' },
};

export default function Invitation({
  email = '',
  role = 'member',
  inviter,
  organization,
  brands = [],
  salesManager = false,
  authenticated = false,
  verified = false,
  state,
}: {
  email?: string;
  role?: string;
  inviter?: string;
  organization?: string;
  brands?: string[];
  salesManager?: boolean;
  authenticated?: boolean;
  verified?: boolean;
  state?: string;
}) {
  const form = useForm({ name: '', password: '', password_confirmation: '', terms: false });
  const submit = (event: FormEvent) => {
    event.preventDefault();
    form.post(
      `/invitations/${authenticated ? 'accept' : 'register'}`,
      { onFinish: () => form.reset('password', 'password_confirmation') },
    );
  };
  if (state && states[state]) {
    return (
      <AuthFrame title={states[state].title} subtitle={states[state].message}>
        <Link href="/login" className={`cursor-pointer ${linkClass}`}>Войти</Link>
        {state === 'email_mismatch' && <Link href="/logout" method="post" as="button" className={`ml-5 cursor-pointer ${linkClass}`}>Выйти из аккаунта</Link>}
      </AuthFrame>
    );
  }
  const roleName = salesManager ? 'Менеджера' : (roleNames[role] || 'участника');
  return (
    <AuthFrame title="Приглашение в команду" subtitle="Присоединитесь к рабочему пространству">
      <div className="mb-5 rounded-xl bg-(--color-bg) p-4 text-sm leading-6">
        <p><strong>{inviter || 'Участник команды'}</strong> приглашает вас в компанию <strong>«{organization || 'Организация'}»</strong> как {roleName}.</p>
        <p className="mt-2 text-(--color-ink-muted)">Доступ к брендам: <strong className="text-(--color-ink)">{brands.length ? brands.join(', ') : 'Не указан'}</strong></p>
        <p className="mt-2">Электронная почта: <strong>{email}</strong></p>
      </div>
      {authenticated && !verified ? (
        <p className="text-sm">
          Перед принятием приглашения{' '}
          <Link href="/email/verify" className={linkClass}>подтвердите почту</Link>.
        </p>
      ) : (
        <form onSubmit={submit} className="space-y-4">
          {!authenticated && (
            <>
              <Field label="Имя" autoComplete="name" required value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} error={form.errors.name} />
              <Field label="Электронная почта" type="email" value={email} readOnly aria-describedby="invitation-email-hint" />
              <p id="invitation-email-hint" className="-mt-2 text-sm text-(--color-ink-muted)">Почта указана в приглашении и не может быть изменена.</p>
              <Field label="Пароль" type="password" autoComplete="new-password" minLength={12} required value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} error={form.errors.password} />
              <Field label="Повторите пароль" type="password" autoComplete="new-password" minLength={12} required value={form.data.password_confirmation} onChange={(e) => form.setData('password_confirmation', e.target.value)} error={form.errors.password_confirmation} />
              <Check label="Я принимаю условия использования и обработки данных" required checked={form.data.terms} onChange={(e) => form.setData('terms', e.target.checked)} error={form.errors.terms} />
            </>
          )}
          <Button type="submit" loading={form.processing} className="min-h-12 w-full">{authenticated ? 'Принять приглашение' : 'Создать аккаунт'}</Button>
        </form>
      )}
      {!authenticated && (
        <p className="mt-6 border-t border-(--color-border) pt-5 text-center text-sm text-(--color-ink-muted)">
          Уже есть аккаунт? <Link href="/login" className={linkClass}>Войти</Link>
        </p>
      )}
    </AuthFrame>
  );
}

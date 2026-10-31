import { router, useForm } from '@inertiajs/react';
import { Button } from '@/components/ui';
import { AuthFrame, Field } from '@/features/Shell/Auth/AuthFrame';
import type { FormEvent } from 'react';

export default function Ready({ mfaEnabled }: { mfaEnabled: boolean }) {
  const setup = useForm({ password: '', code: '' });
  const submit = (event: FormEvent) => {
    event.preventDefault();
    setup.post(mfaEnabled ? '/mfa/disable' : '/mfa/setup', {
      onFinish: () => setup.reset('password', 'code'),
    });
  };
  return (
    <AuthFrame
      title="Вход выполнен"
      subtitle="Доступ подтверждён. Основной кабинет находится в разработке."
    >
      <p className="text-sm text-(--color-ink-muted)">
        Двухфакторная проверка:{' '}
        <strong className="text-(--color-ink)">{mfaEnabled ? 'включена' : 'не включена'}</strong>
      </p>
      <form onSubmit={submit} className="mt-5 space-y-4">
        <Field
          label="Текущий пароль"
          type="password"
          autoComplete="current-password"
          required
          value={setup.data.password}
          onChange={(e) => setup.setData('password', e.target.value)}
          error={setup.errors.password}
        />
        {mfaEnabled && (
          <Field
            label="Код из приложения"
            autoComplete="one-time-code"
            inputMode="numeric"
            pattern="[0-9]{6}"
            maxLength={6}
            required
            value={setup.data.code}
            onChange={(e) => setup.setData('code', e.target.value)}
            error={setup.errors.code}
          />
        )}
        <Button type="submit" loading={setup.processing} className="min-h-12 w-full">
          {mfaEnabled ? 'Отключить двухфакторную проверку' : 'Настроить двухфакторную проверку'}
        </Button>
      </form>
      <Button variant="ghost" className="mt-4 w-full" onClick={() => router.post('/logout')}>
        Выйти
      </Button>
    </AuthFrame>
  );
}

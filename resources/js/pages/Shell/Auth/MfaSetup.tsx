import { useForm } from '@inertiajs/react';
import { Button } from '@/components/ui';
import { AuthFrame, Field } from '@/features/Shell/Auth/AuthFrame';
import type { FormEvent } from 'react';

export default function MfaSetup({ secret }: { secret: string }) {
  const form = useForm({ code: '' });
  const submit = (event: FormEvent) => {
    event.preventDefault();
    form.post('/mfa/setup/confirm', { onFinish: () => form.reset('code') });
  };
  return (
    <AuthFrame
      title="Настройка двухфакторной проверки"
      subtitle="Добавьте ключ в приложение-аутентификатор и введите полученный код."
    >
      <p className="text-sm text-(--color-ink-muted)">Ключ настройки</p>
      <p className="mt-1 break-all rounded-lg bg-(--color-bg) p-3 font-mono text-base font-semibold select-all">
        {secret}
      </p>
      <form onSubmit={submit} className="mt-5 space-y-5">
        <Field
          label="Код из приложения"
          autoComplete="one-time-code"
          inputMode="numeric"
          pattern="[0-9]{6}"
          maxLength={6}
          required
          value={form.data.code}
          onChange={(e) => form.setData('code', e.target.value)}
          error={form.errors.code}
        />
        <Button type="submit" loading={form.processing} className="min-h-12 w-full">
          Включить проверку
        </Button>
      </form>
    </AuthFrame>
  );
}

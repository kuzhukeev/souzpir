import { Link, useForm } from '@inertiajs/react';
import { Button } from '@/components/ui';
import { AuthFrame, Field, linkClass } from '@/features/Shell/Auth/AuthFrame';
import type { FormEvent } from 'react';

export default function Mfa() {
  const form = useForm({ code: '' });
  const submit = (event: FormEvent) => {
    event.preventDefault();
    form.post('/mfa/challenge', { onFinish: () => form.reset('code') });
  };
  return (
    <AuthFrame
      title="Двухфакторная проверка"
      subtitle="Введите шестизначный код из приложения-аутентификатора."
    >
      <form onSubmit={submit} className="space-y-5">
        <Field
          label="Код подтверждения"
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
          Подтвердить
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

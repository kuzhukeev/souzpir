import { Link, useForm } from '@inertiajs/react';
import { Button } from '@/components/ui';
import { AuthFrame, Check, Field, linkClass } from '@/features/Shell/Auth/AuthFrame';
import type { FormEvent } from 'react';

export default function Register() {
  const form = useForm({
    name: '',
    email: '',
    phone: '',
    password: '',
    password_confirmation: '',
    terms: false,
    ai_consent: false,
    marketing: false,
    bootstrap_code: '',
  });
  const submit = (event: FormEvent) => {
    event.preventDefault();
    form.post('/register', {
      onFinish: () => form.reset('password', 'password_confirmation', 'bootstrap_code'),
    });
  };
  return (
    <AuthFrame title="Создать аккаунт" subtitle="Начните работу с EYON">
      <form onSubmit={submit} className="space-y-4">
        <Field
          label="Имя"
          autoComplete="name"
          required
          value={form.data.name}
          onChange={(e) => form.setData('name', e.target.value)}
          error={form.errors.name}
        />
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
          label="Пароль"
          type="password"
          autoComplete="new-password"
          required
          minLength={12}
          value={form.data.password}
          onChange={(e) => form.setData('password', e.target.value)}
          error={form.errors.password}
        />
        <Field
          label="Повторите пароль"
          type="password"
          autoComplete="new-password"
          required
          minLength={12}
          value={form.data.password_confirmation}
          onChange={(e) => form.setData('password_confirmation', e.target.value)}
          error={form.errors.password_confirmation}
        />
        <Field
          label="Телефон · необязательно"
          type="tel"
          autoComplete="tel"
          value={form.data.phone}
          onChange={(e) => form.setData('phone', e.target.value)}
          error={form.errors.phone}
        />
        <Field
          label="Код создания кабинета"
          type="password"
          autoComplete="off"
          required
          value={form.data.bootstrap_code}
          onChange={(e) => form.setData('bootstrap_code', e.target.value)}
          error={form.errors.bootstrap_code}
        />
        <p className="text-sm leading-5 text-(--color-ink-muted)">
          Код нужен только для создания первого владельца. Участники присоединяются по приглашению.
        </p>
        <Check
          label="Я принимаю условия использования и обработки данных"
          required
          checked={form.data.terms}
          onChange={(e) => form.setData('terms', e.target.checked)}
          error={form.errors.terms}
        />
        <Check
          label="Я согласен на обработку данных внешними ИИ-провайдерами"
          required
          checked={form.data.ai_consent}
          onChange={(e) => form.setData('ai_consent', e.target.checked)}
          error={form.errors.ai_consent}
        />
        <Check
          label="Получать новости и обновления"
          checked={form.data.marketing}
          onChange={(e) => form.setData('marketing', e.target.checked)}
        />
        <Button type="submit" loading={form.processing} className="min-h-12 w-full">
          Зарегистрироваться
        </Button>
      </form>
      <p className="mt-6 border-t border-(--color-border) pt-5 text-center text-sm text-(--color-ink-muted)">
        Уже есть аккаунт?{' '}
        <Link href="/login" className={linkClass}>
          Войти
        </Link>
      </p>
    </AuthFrame>
  );
}

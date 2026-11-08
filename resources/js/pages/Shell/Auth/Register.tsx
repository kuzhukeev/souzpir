import { Link, useForm } from '@inertiajs/react';
import { Button } from '@/components/ui';
import { AuthFrame, Check, Field, linkClass } from '@/features/Shell/Auth/AuthFrame';
import type { FormEvent } from 'react';

function strength(password: string): number {
  return [password.length >= 12, /[a-zа-я]/i.test(password) && /[A-ZА-Я]/.test(password), /\d/.test(password), /[^\p{L}\d]/u.test(password)].filter(Boolean).length;
}

function formatPhone(value: string): string {
  const digits = value.replace(/\D/g, '').replace(/^[78]/, '').slice(0, 10);
  if (!digits) return '';
  let result = '+7 (' + digits.slice(0, 3);
  if (digits.length >= 3) result += ')';
  if (digits.length > 3) result += ' ' + digits.slice(3, 6);
  if (digits.length > 6) result += '-' + digits.slice(6, 8);
  if (digits.length > 8) result += '-' + digits.slice(8, 10);
  return result;
}

export default function Register({ needsCode = true }: { needsCode?: boolean }) {
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
        {Object.keys(form.errors).length > 0 && <p role="alert" className="rounded-lg bg-(--color-danger-soft) p-3 text-sm text-(--color-danger-ink)">{!needsCode && form.errors.bootstrap_code ? form.errors.bootstrap_code : 'Проверьте поля и подтвердите обязательные согласия'}</p>}
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
        <div aria-live="polite" className="-mt-2">
          <p className="mb-2 text-sm text-(--color-ink-muted)">Надёжность пароля: {['нет', 'низкая', 'средняя', 'хорошая', 'высокая'][strength(form.data.password)]}</p>
          <div className="grid grid-cols-4 gap-1" role="meter" aria-label="Надёжность пароля" aria-valuemin={0} aria-valuemax={4} aria-valuenow={strength(form.data.password)}>
            {[1, 2, 3, 4].map((part) => <span key={part} className={`h-2 rounded-full ${strength(form.data.password) >= part ? 'bg-(--color-action)' : 'bg-(--color-border)'}`} />)}
          </div>
        </div>
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
          placeholder="+7 (___) ___-__-__"
          inputMode="tel"
          onChange={(e) => form.setData('phone', formatPhone(e.target.value))}
          error={form.errors.phone}
        />
        {needsCode && (
          <>
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
          </>
        )}
        <Check
          label="Я принимаю условия использования и обработки данных"
          required
          checked={form.data.terms}
          onChange={(e) => form.setData('terms', e.target.checked)}
          error={form.errors.terms}
        />
        <Check
          label="Название компании и тексты диалогов обрабатываются внешними ИИ-провайдерами через OpenRouter"
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
          {form.processing ? 'Создаём аккаунт…' : 'Зарегистрироваться'}
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

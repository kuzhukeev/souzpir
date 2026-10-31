import { Head } from '@inertiajs/react';
import { Eye, EyeOff } from 'lucide-react';
import { useId, useState, type InputHTMLAttributes, type ReactNode } from 'react';

export function AuthFrame({
  title,
  subtitle,
  children,
}: {
  title: string;
  subtitle?: string;
  children: ReactNode;
}) {
  return (
    <div className="relative flex min-h-dvh flex-col items-center justify-center overflow-hidden bg-(--color-bg) px-4 py-8 font-(family-name:--font-sans) text-(--color-ink)">
      <Head title={title} />
      <span
        aria-hidden="true"
        className="pointer-events-none absolute -top-40 -left-40 size-80 rounded-full border border-(--color-brand-accent)/30 sm:-top-[330px] sm:-left-[330px] sm:size-[660px]"
      />
      <span
        aria-hidden="true"
        className="pointer-events-none absolute -right-40 -bottom-40 size-80 rounded-full border border-(--color-brand-accent)/30 sm:-right-[315px] sm:-bottom-[315px] sm:size-[660px]"
      />
      <div className="relative mb-7 flex items-center gap-3" role="img" aria-label="EYON">
        <svg aria-hidden="true" viewBox="0 0 48 48" className="size-12">
          <rect width="48" height="48" rx="13" fill="var(--color-brand-primary)" />
          <path
            d="M12 24s4.8-8 12-8 12 8 12 8-4.8 8-12 8-12-8-12-8Z"
            fill="none"
            stroke="white"
            strokeWidth="2.6"
          />
          <circle cx="24" cy="24" r="3.1" fill="none" stroke="white" strokeWidth="2.6" />
        </svg>
        <span
          aria-hidden="true"
          className="text-[40px] font-extrabold leading-none tracking-[-1.8px]"
        >
          eyon<span className="text-(--color-brand-primary)">.</span>
        </span>
      </div>
      <main className="relative w-full max-w-[530px] rounded-(--radius-card) bg-(--color-surface) p-6 shadow-(--shadow-card) sm:p-11">
        <h1 className="text-[30px] font-extrabold leading-tight sm:text-[34px]">{title}</h1>
        {subtitle && (
          <p className="mt-2 text-base leading-6 text-(--color-ink-muted)">{subtitle}</p>
        )}
        <div className="mt-7">{children}</div>
      </main>
      <p className="relative mt-7 text-sm text-(--color-ink-muted)">© EYON</p>
    </div>
  );
}

export function Field({
  label,
  error,
  type = 'text',
  ...props
}: InputHTMLAttributes<HTMLInputElement> & { label: string; error?: string }) {
  const id = useId();
  const [visible, setVisible] = useState(false);
  const password = type === 'password';
  return (
    <div>
      <label className="mb-1.5 block text-sm font-semibold" htmlFor={id}>
        {label}
      </label>
      <div className="relative">
        <input
          {...props}
          id={id}
          type={password && visible ? 'text' : type}
          aria-invalid={!!error || undefined}
          aria-describedby={error ? `${id}-error` : undefined}
          className="h-14 w-full rounded-(--radius-control) border border-(--color-control-border) bg-(--color-surface) px-4 pr-12 text-base text-(--color-ink) placeholder:text-(--color-ink-muted) focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--color-focus)"
        />
        {password && (
          <button
            type="button"
            aria-label={visible ? 'Скрыть пароль' : 'Показать пароль'}
            onClick={() => setVisible(!visible)}
            className="absolute inset-y-0 right-0 flex items-center px-4 text-(--color-icon)"
          >
            {visible ? <EyeOff size={20} /> : <Eye size={20} />}
          </button>
        )}
      </div>
      {error && (
        <p id={`${id}-error`} role="alert" className="mt-1.5 text-sm text-(--color-danger-ink)">
          {error}
        </p>
      )}
    </div>
  );
}

export function Check({
  label,
  error,
  ...props
}: InputHTMLAttributes<HTMLInputElement> & { label: string; error?: string }) {
  const id = useId();
  return (
    <div>
      <label htmlFor={id} className="flex items-start gap-2.5 text-sm leading-5">
        <input
          {...props}
          id={id}
          type="checkbox"
          className="mt-0.5 size-4 shrink-0 accent-(--color-action)"
        />
        <span>{label}</span>
      </label>
      {error && (
        <p role="alert" className="mt-1 text-sm text-(--color-danger-ink)">
          {error}
        </p>
      )}
    </div>
  );
}

export const linkClass = 'font-semibold text-(--color-link) hover:underline';

import type { ReactNode } from 'react';
import { CheckCircle2 } from 'lucide-react';
export const panel =
  'rounded-2xl border border-(--color-border) bg-(--color-surface) p-5 shadow-(--shadow-card) sm:p-6';
export const focus =
  'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--color-focus)';
export const hiddenLabel = '[&>label]:sr-only';
export function FormErrors({
  errors,
  transport,
}: {
  errors: Record<string, string | undefined>;
  transport?: string;
}) {
  const messages = [...new Set([transport, ...Object.values(errors)].filter(Boolean))];
  return messages.length ? (
    <div
      role="alert"
      className="rounded-xl bg-(--color-danger-soft) p-4 text-sm text-(--color-danger-ink)"
    >
      {messages.map((message) => (
        <p key={message}>{message}</p>
      ))}
    </div>
  ) : null;
}
export function Row({
  label,
  hint,
  children,
}: {
  label: string;
  hint?: string;
  children: ReactNode;
}) {
  return (
    <div className="grid gap-3 lg:grid-cols-[minmax(150px,.6fr)_minmax(0,2fr)] lg:gap-6">
      <div>
        <p className="text-sm font-semibold">{label}</p>
        {hint && <p className="mt-1 text-sm leading-6 text-(--color-ink-muted)">{hint}</p>}
      </div>
      <div className="min-w-0">{children}</div>
    </div>
  );
}
export function Saved({ visible }: { visible: boolean }) {
  return visible ? (
    <p role="status" className="flex items-center gap-2 text-sm text-(--color-success)">
      <CheckCircle2 size={18} aria-hidden="true" /> Изменения сохранены
    </p>
  ) : null;
}

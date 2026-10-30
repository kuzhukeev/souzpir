import { useState, type ReactNode } from 'react';
import * as TooltipPrimitive from '@radix-ui/react-tooltip';
import { Button } from './forms';

export function Card({ children, className = '' }: { children: ReactNode; className?: string }) {
  return (
    <div
      className={`rounded-(--radius-card) bg-(--color-surface) p-5 shadow-(--shadow-card) ${className}`}
    >
      {children}
    </div>
  );
}
export function KpiCard({
  label,
  value,
  delta,
}: {
  label: string;
  value: ReactNode;
  delta?: string;
}) {
  return (
    <Card>
      <p className="text-sm text-(--color-ink-muted)">{label}</p>
      <p className="mt-2 text-2xl font-bold">{value}</p>
      {delta && <p className="mt-1 text-sm text-(--color-ink-muted)">{delta}</p>}
    </Card>
  );
}
export function Badge({
  children,
  tone = 'info',
}: {
  children: ReactNode;
  tone?: 'info' | 'success' | 'warning' | 'danger';
}) {
  const colors = {
    info: 'bg-(--color-info-soft) text-(--color-info-ink)',
    success: 'bg-(--color-success-soft) text-(--color-success-ink)',
    warning: 'bg-(--color-warning-soft) text-(--color-warning-ink)',
    danger: 'bg-(--color-danger-soft) text-(--color-danger-ink)',
  };
  return (
    <span className={`inline-flex rounded-full px-2.5 py-1 text-sm font-medium ${colors[tone]}`}>
      {children}
    </span>
  );
}
export function Skeleton({ label = 'Загрузка' }: { label?: string }) {
  return (
    <div
      role="status"
      aria-label={label}
      className="h-12 rounded-lg bg-(--color-surface-muted) motion-safe:animate-pulse"
    >
      <span className="sr-only">{label}</span>
    </div>
  );
}
export function EmptyState({
  title,
  description,
  icon,
  action,
}: {
  title: string;
  description?: string;
  icon?: ReactNode;
  action?: ReactNode;
}) {
  return (
    <div className="px-5 py-12 text-center">
      {icon && (
        <div aria-hidden="true" className="mx-auto mb-4 w-fit text-(--color-action)">
          {icon}
        </div>
      )}
      <h3 className="text-lg font-bold">{title}</h3>
      {description && <p className="mt-2 text-sm text-(--color-ink-muted)">{description}</p>}
      {action && <div className="mt-5">{action}</div>}
    </div>
  );
}
export function ProgressBar({
  label,
  value,
  max = 100,
}: {
  label: string;
  value: number;
  max?: number;
}) {
  const safe = Math.max(0, Math.min(value, max));
  return (
    <div>
      <div className="mb-2 flex justify-between text-sm">
        <span>{label}</span>
        <span>
          {safe} / {max}
        </span>
      </div>
      <progress
        className="h-2.5 w-full accent-(--color-action)"
        aria-label={label}
        value={safe}
        max={max}
      />
    </div>
  );
}
export function Avatar({ name }: { name: string }) {
  const initials = name
    .trim()
    .split(/\s+/)
    .slice(0, 2)
    .map((word) => word[0])
    .join('')
    .toLocaleUpperCase('ru');
  return (
    <span
      role="img"
      aria-label={name}
      className="inline-grid size-10 place-items-center rounded-full bg-(--color-action) text-sm font-bold text-white"
    >
      {initials}
    </span>
  );
}
export function Timeline({
  entries,
}: {
  entries: { id: string; title: string; time: string; detail?: string; metadata?: string }[];
}) {
  return (
    <ol aria-label="Хронология" className="space-y-4 border-l border-(--color-border) pl-5">
      {entries.map((item) => (
        <li key={item.id} className="text-sm">
          <p className="font-semibold">{item.title}</p>
          <time className="text-(--color-ink-muted)">{item.time}</time>
          {item.detail && <p className="mt-1">{item.detail}</p>}
          {item.metadata && <p className="mt-1 text-(--color-ink-muted)">{item.metadata}</p>}
        </li>
      ))}
    </ol>
  );
}
export function CodeBlock({ code }: { code: string }) {
  const [status, setStatus] = useState('');
  async function copy() {
    try {
      await navigator.clipboard.writeText(code);
      setStatus('Скопировано');
    } catch {
      setStatus('Не удалось скопировать');
    }
  }
  return (
    <div className="rounded-xl bg-(--color-surface-muted) p-4">
      <pre className="overflow-x-auto text-sm">
        <code>{code}</code>
      </pre>
      <Button variant="secondary" size="sm" onClick={() => void copy()} className="mt-3">
        Копировать
      </Button>
      <span role="status" className="ml-3 text-sm">
        {status}
      </span>
    </div>
  );
}
export function Tooltip({ label, children }: { label: string; children: ReactNode }) {
  return (
    <TooltipPrimitive.Provider>
      <TooltipPrimitive.Root>
        <TooltipPrimitive.Trigger className="rounded text-sm focus-visible:outline-2 focus-visible:outline-(--color-focus)">
          {children}
        </TooltipPrimitive.Trigger>
        <TooltipPrimitive.Portal>
          <TooltipPrimitive.Content
            sideOffset={6}
            className="z-50 max-w-60 rounded-lg bg-(--color-ink) px-3 py-2 text-sm text-white"
          >
            {label}
            <TooltipPrimitive.Arrow className="fill-(--color-ink)" />
          </TooltipPrimitive.Content>
        </TooltipPrimitive.Portal>
      </TooltipPrimitive.Root>
    </TooltipPrimitive.Provider>
  );
}

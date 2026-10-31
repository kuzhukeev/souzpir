import { useEffect, useRef, type ReactNode } from 'react';
import { Link } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { Button } from '@/components/ui';
import CabinetLayout from '@/layouts/CabinetLayout';
import type { Pagination } from '@/types/Shell/Support';
import { useSupportCopy } from './copy';
import './support.css';
export const panel =
  'rounded-2xl border border-(--color-border) bg-(--color-surface) shadow-(--shadow-card)';
export const focus =
  'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--color-focus)';
export function SupportPage({
  active,
  title,
  subtitle,
  actions,
  children,
}: {
  active: 'help' | 'tickets' | 'status';
  title: string;
  subtitle: string;
  actions?: ReactNode;
  children: ReactNode;
}) {
  const c = useSupportCopy();
  return (
    <div className="support-page">
      <CabinetLayout
        brandSwitchReady
        breadcrumbs={[
          { label: c.help, href: '/help' },
          ...(active === 'help' ? [] : [{ label: title }]),
        ]}
      >
        <div className="mx-auto min-w-0 max-w-[1500px]">
          <header className="mb-6 flex flex-wrap items-start justify-between gap-4">
            <div className="min-w-0">
              <h1 className="break-words text-3xl font-bold leading-tight">{title}</h1>
              <p className="mt-2 text-base text-(--color-ink-muted)">{subtitle}</p>
            </div>
            {actions}
          </header>
          <nav
            aria-label={c.helpNav}
            className="mb-6 flex flex-wrap gap-2 border-b border-(--color-border)"
          >
            {(
              [
                { id: 'help', href: '/help', label: c.help },
                { id: 'tickets', href: '/help/support', label: c.tickets },
                { id: 'status', href: '/help/status', label: c.status },
              ] as const
            ).map((tab) => (
              <Link
                key={tab.id}
                href={tab.href}
                aria-current={active === tab.id ? 'page' : undefined}
                className={[
                  'inline-flex min-h-11 cursor-pointer items-center border-b-2 px-4 text-sm font-semibold',
                  active === tab.id
                    ? 'border-(--color-action) text-(--color-action)'
                    : 'border-transparent text-(--color-link)',
                  focus,
                ].join(' ')}
              >
                {tab.label}
              </Link>
            ))}
          </nav>
          {children}
        </div>
      </CabinetLayout>
    </div>
  );
}
export function ErrorSummary({
  failure,
  focusOnChange = false,
}: {
  failure: { message: string; fields?: Record<string, string> };
  focusOnChange?: boolean;
}) {
  const summary = useRef<HTMLDivElement>(null);
  const messages = Array.from(
    new Set([failure.message, ...Object.values(failure.fields ?? {})].filter(Boolean)),
  );
  useEffect(() => {
    if (focusOnChange && summary.current) {
      summary.current.focus({ preventScroll: true });
      summary.current.scrollIntoView({ block: 'nearest' });
    }
  }, [failure, focusOnChange]);
  return messages.length ? (
    <div
      ref={summary}
      tabIndex={focusOnChange ? -1 : undefined}
      role="alert"
      className="rounded-xl bg-(--color-danger-soft) p-4 text-sm leading-6 text-(--color-danger-ink)"
    >
      {messages.map((message) => (
        <p key={message}>{message}</p>
      ))}
    </div>
  ) : null;
}
export function DateValue({ value }: { value: string | null | undefined }) {
  const c = useSupportCopy();
  const date = value ? new Date(value) : null;
  return date && !Number.isNaN(date.getTime()) ? (
    <time dateTime={value ?? undefined}>
      {date.toLocaleString('ru-RU', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
      })}
    </time>
  ) : (
    <span>{c.noData}</span>
  );
}
export function PaginationBar<T>({
  page,
  busy,
  onChange,
}: {
  page: Pagination<T>;
  busy: boolean;
  onChange: (page: number) => void;
}) {
  const c = useSupportCopy();
  return (
    <div className="flex flex-wrap items-center justify-between gap-3 border-t border-(--color-border) p-4 text-sm">
      <p>
        {page.total ? (page.current_page - 1) * page.per_page + 1 : 0}–
        {Math.min(page.current_page * page.per_page, page.total)} {c.of} {page.total}
      </p>
      {page.last_page > 1 && (
        <nav aria-label={c.pagination} className="flex items-center gap-2">
          <Button
            variant="ghost"
            aria-label={c.previous}
            disabled={busy || page.current_page <= 1}
            onClick={() => onChange(page.current_page - 1)}
          >
            <ChevronLeft size={18} />
          </Button>
          <span>
            {page.current_page} {c.of} {page.last_page}
          </span>
          <Button
            variant="ghost"
            aria-label={c.next}
            disabled={busy || page.current_page >= page.last_page}
            onClick={() => onChange(page.current_page + 1)}
          >
            <ChevronRight size={18} />
          </Button>
        </nav>
      )}
    </div>
  );
}

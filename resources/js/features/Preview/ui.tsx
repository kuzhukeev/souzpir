import { useEffect, useId, useRef, type ButtonHTMLAttributes, type ReactNode } from 'react';
import { X } from 'lucide-react';

export function Button({
  children,
  className = '',
  variant = 'primary',
  ...props
}: ButtonHTMLAttributes<HTMLButtonElement> & { variant?: 'primary' | 'secondary' | 'ghost' }) {
  const variants = {
    primary: 'bg-(--color-action) text-(--color-on-action) hover:bg-(--color-action-hover)',
    secondary:
      'border border-(--color-control-border) bg-(--color-surface) text-(--color-link) hover:bg-(--color-surface-muted)',
    ghost: 'text-(--color-link) hover:bg-(--color-surface-muted)',
  };
  return (
    <button
      type="button"
      {...props}
      className={`inline-flex min-h-10 items-center justify-center gap-2 rounded-(--radius-control) px-4 py-2 text-sm font-semibold transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--color-focus) disabled:cursor-not-allowed disabled:bg-(--color-disabled) disabled:text-(--color-on-disabled) ${variants[variant]} ${className}`}
    >
      {children}
    </button>
  );
}

export function DemoDialog({
  open,
  title,
  children,
  onClose,
  drawer = false,
  side = 'right',
}: {
  open: boolean;
  title: string;
  children: ReactNode;
  onClose: () => void;
  drawer?: boolean;
  side?: 'left' | 'right';
}) {
  const ref = useRef<HTMLDialogElement>(null);
  const titleId = useId();
  useEffect(() => {
    const dialog = ref.current;
    if (open && dialog && !dialog.open) dialog.showModal();
    if (!open && dialog?.open) dialog.close();
  }, [open]);
  return (
    <dialog
      ref={ref}
      onCancel={onClose}
      onClose={onClose}
      aria-labelledby={titleId}
      className={`fixed border-0 bg-(--color-surface) p-0 text-(--color-ink) shadow-xl backdrop:bg-[#10264a]/30 backdrop:backdrop-blur-[2px] ${drawer ? `inset-y-0 m-0 h-dvh max-h-dvh max-w-full ${side === 'left' ? 'left-0 right-auto mr-auto w-[min(320px,100vw)]' : 'left-auto right-0 ml-auto w-[min(440px,100vw)]'}` : 'm-auto w-[min(480px,calc(100vw-32px))] max-w-full rounded-(--radius-card)'}`}
    >
      <div className="flex items-start justify-between gap-4 border-b border-(--color-border) px-6 py-5">
        <h2 id={titleId} className="text-lg font-bold">
          {title}
        </h2>
        <button
          type="button"
          onClick={onClose}
          aria-label="Закрыть окно"
          className="rounded-lg p-1 text-(--color-ink-muted) hover:bg-(--color-surface-muted)"
        >
          <X size={22} />
        </button>
      </div>
      <div className="p-6">{children}</div>
    </dialog>
  );
}

export function Notice({ message, onClose }: { message: string; onClose: () => void }) {
  if (!message) return null;
  return (
    <div
      role="status"
      className="fixed right-4 bottom-4 left-4 z-[80] flex items-start gap-3 rounded-xl border border-(--color-border) bg-(--color-surface) p-4 text-sm text-(--color-ink) shadow-xl sm:left-auto sm:w-[420px]"
    >
      <span className="min-w-0 flex-1">{message}</span>
      <button
        type="button"
        aria-label="Закрыть уведомление"
        onClick={onClose}
        className="rounded p-1"
      >
        <X size={18} />
      </button>
    </div>
  );
}

export function EmptyCheck({ compact = false }: { compact?: boolean }) {
  return (
    <svg
      aria-hidden="true"
      viewBox="0 0 200 180"
      className={compact ? 'mx-auto h-32 w-36' : 'mx-auto h-44 w-48'}
      fill="none"
    >
      <circle cx="100" cy="90" r="63" fill="var(--color-success-soft)" />
      <path
        d="m72 91 20 20 39-43"
        stroke="var(--color-action)"
        strokeWidth="13"
        strokeLinecap="round"
        strokeLinejoin="round"
      />
      <g stroke="#91d4b0" strokeWidth="5" strokeLinecap="round">
        <path d="m21 47 8 7M13 92h7M29 139l-7 6M172 41l-7 7M181 91h-7M170 142l-8-7" />
      </g>
    </svg>
  );
}

export function BrandMark({
  theme,
  compact = false,
}: {
  theme: 'confectionery' | 'pyro' | 'neutral';
  compact?: boolean;
}) {
  const title =
    theme === 'pyro' ? 'Пиротехника' : theme === 'confectionery' ? 'Кондитерская' : 'eyon.';
  const caption =
    theme === 'pyro'
      ? 'Оптовые поставки пиротехники'
      : theme === 'confectionery'
        ? 'Кондитерское производство'
        : 'Кабинет вашей команды';
  return (
    <div className="flex min-w-0 items-center gap-2.5">
      <svg aria-hidden="true" viewBox="0 0 48 48" className="h-11 w-11 shrink-0" fill="none">
        {theme === 'pyro' ? (
          <g stroke="var(--color-brand-accent)" strokeWidth="1.7" strokeLinecap="round">
            {Array.from({ length: 8 }, (_, i) => (
              <g key={i} transform={`rotate(${i * 45} 24 24)`}>
                <path d="M24 5v12" />
                <circle cx="24" cy="3" r="1.4" fill="var(--color-brand-accent)" />
              </g>
            ))}
          </g>
        ) : (
          <>
            <rect x="3" y="3" width="42" height="42" rx="12" fill="var(--color-brand-primary)" />
            <path
              d="M11 24s5-9 13-9 13 9 13 9-5 9-13 9-13-9-13-9Z"
              stroke="white"
              strokeWidth="2.5"
            />
            <circle cx="24" cy="24" r="3.5" stroke="white" strokeWidth="2.5" />
          </>
        )}
      </svg>
      {!compact && (
        <span className="min-w-0">
          <span className="block truncate text-[20px] leading-6 font-extrabold tracking-[-.7px]">
            {title}
          </span>
          <span className="mt-0.5 block text-[13px] leading-[18px] text-(--color-ink-muted)">
            {caption}
          </span>
        </span>
      )}
    </div>
  );
}

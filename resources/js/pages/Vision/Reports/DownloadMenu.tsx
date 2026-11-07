import { useEffect, useRef, useState } from 'react';
import { ChevronDown, Download, Printer } from 'lucide-react';

export default function DownloadMenu() {
  const [open, setOpen] = useState(false);
  const root = useRef<HTMLDivElement>(null);
  const trigger = useRef<HTMLButtonElement>(null);
  const item = useRef<HTMLButtonElement>(null);

  useEffect(() => {
    if (!open) return;
    item.current?.focus();
    const outside = (event: PointerEvent) => {
      if (!root.current?.contains(event.target as Node)) setOpen(false);
    };
    document.addEventListener('pointerdown', outside);
    return () => document.removeEventListener('pointerdown', outside);
  }, [open]);

  const close = () => {
    setOpen(false);
    trigger.current?.focus();
  };

  return (
    <div
      ref={root}
      className="relative inline-block print:hidden"
      onBlur={(event) => {
        if (!event.currentTarget.contains(event.relatedTarget)) setOpen(false);
      }}
      onKeyDown={(event) => {
        if (event.key === 'Escape' && open) {
          event.preventDefault();
          close();
        }
        if ((event.key === 'ArrowDown' || event.key === 'ArrowUp') && open) {
          event.preventDefault();
          item.current?.focus();
        }
      }}
    >
      <button
        ref={trigger}
        type="button"
        aria-haspopup="menu"
        aria-expanded={open}
        onClick={() => setOpen(!open)}
        className="inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-lg bg-(--color-action) px-4 py-2 text-sm font-semibold text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--color-focus)"
      >
        <Download size={18} aria-hidden="true" /> Скачать отчёт{' '}
        <ChevronDown size={16} aria-hidden="true" />
      </button>
      {open && (
        <div
          role="menu"
          aria-label="Скачать отчёт"
          className="absolute right-0 z-20 mt-2 min-w-52 rounded-lg border border-(--color-border) bg-(--color-surface) p-1 shadow-(--shadow-card)"
        >
          <button
            ref={item}
            role="menuitem"
            type="button"
            className="flex min-h-11 w-full cursor-pointer items-center gap-2 rounded-md px-3 text-left text-sm text-(--color-ink) hover:bg-(--color-surface-muted) focus-visible:outline-2 focus-visible:outline-(--color-focus)"
            onClick={() => {
              close();
              window.print();
            }}
          >
            <Printer size={16} aria-hidden="true" /> Печать / PDF
          </button>
        </div>
      )}
    </div>
  );
}


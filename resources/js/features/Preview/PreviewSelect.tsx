import {
  useEffect,
  useId,
  useLayoutEffect,
  useRef,
  useState,
  type KeyboardEvent,
  type ReactNode,
} from 'react';
import { createPortal } from 'react-dom';
import { Check, ChevronDown } from 'lucide-react';

type Option = { value: string; label: string };
type Props = {
  label: string;
  value: string;
  onChange: (value: string) => void;
  options: Option[];
  disabled?: boolean;
  className?: string;
  id?: string;
  icon?: ReactNode;
};

/** Select-only combobox: DOM focus stays on the trigger while the active option changes. */
export default function PreviewSelect({
  label,
  value,
  onChange,
  options,
  disabled = false,
  className = '',
  id,
  icon,
}: Props) {
  const generatedId = useId();
  const triggerId = id ?? `preview-select-${generatedId}`;
  const menuId = `${triggerId}-options`;
  const trigger = useRef<HTMLButtonElement>(null);
  const menu = useRef<HTMLDivElement>(null);
  const search = useRef({ text: '', time: 0 });
  const [open, setOpen] = useState(false);
  const [activeIndex, setActiveIndex] = useState(0);
  const selectedIndex = options.findIndex((option) => option.value === value);
  const isDisabled = disabled || options.length === 0;
  // A temporarily disabled control must not reopen merely because it becomes enabled again.
  if (isDisabled && open) setOpen(false);
  const expanded = open && !isDisabled;
  const active = Math.min(Math.max(activeIndex, 0), Math.max(options.length - 1, 0));

  const show = (index = Math.max(selectedIndex, 0)) => {
    if (isDisabled) return;
    search.current = { text: '', time: 0 };
    setActiveIndex(index);
    setOpen(true);
  };
  const close = (restoreFocus = true) => {
    setOpen(false);
    search.current = { text: '', time: 0 };
    if (restoreFocus) trigger.current?.focus();
  };
  const choose = (index: number) => {
    if (isDisabled || !options[index]) return;
    if (options[index].value !== value) onChange(options[index].value);
    close();
  };

  useLayoutEffect(() => {
    if (!expanded) return;
    let frame = 0;
    const position = () => {
      const anchor = trigger.current;
      const popup = menu.current;
      if (!anchor || !popup) return;
      const rect = anchor.getBoundingClientRect();
      const viewport = window.visualViewport;
      const leftEdge = (viewport?.offsetLeft ?? 0) + 8;
      const topEdge = (viewport?.offsetTop ?? 0) + 8;
      const rightEdge = leftEdge + (viewport?.width ?? window.innerWidth) - 16;
      const bottomEdge = topEdge + (viewport?.height ?? window.innerHeight) - 16;
      const width = Math.min(Math.max(rect.width, 180), rightEdge - leftEdge);
      popup.style.width = `${Math.max(width, 0)}px`;
      const below = Math.max(0, bottomEdge - rect.bottom - 6);
      const above = Math.max(0, rect.top - topEdge - 6);
      const flip = below < Math.min(popup.scrollHeight, 200) && above > below;
      const maxHeight = Math.min(320, flip ? above : below);
      const height = Math.min(popup.scrollHeight, maxHeight);
      popup.style.left = `${Math.max(leftEdge, Math.min(rect.left, rightEdge - width))}px`;
      popup.style.top = `${Math.max(topEdge, Math.min(bottomEdge - height, flip ? rect.top - 6 - height : rect.bottom + 6))}px`;
      popup.style.maxHeight = `${maxHeight}px`;
      popup.style.visibility = 'visible';
    };
    const schedule = () => {
      cancelAnimationFrame(frame);
      frame = requestAnimationFrame(position);
    };
    position();
    window.addEventListener('resize', schedule);
    window.addEventListener('scroll', schedule, true);
    window.visualViewport?.addEventListener('resize', schedule);
    window.visualViewport?.addEventListener('scroll', schedule);
    const observer = typeof ResizeObserver === 'undefined' ? null : new ResizeObserver(schedule);
    if (trigger.current) observer?.observe(trigger.current);
    if (menu.current) observer?.observe(menu.current);
    return () => {
      cancelAnimationFrame(frame);
      window.removeEventListener('resize', schedule);
      window.removeEventListener('scroll', schedule, true);
      window.visualViewport?.removeEventListener('resize', schedule);
      window.visualViewport?.removeEventListener('scroll', schedule);
      observer?.disconnect();
    };
  }, [expanded, options.length]);

  useEffect(() => {
    if (!expanded) return;
    const outside = (event: PointerEvent) => {
      if (
        event.target instanceof Node &&
        !trigger.current?.contains(event.target) &&
        !menu.current?.contains(event.target)
      ) {
        setOpen(false);
      }
    };
    document.addEventListener('pointerdown', outside);
    return () => document.removeEventListener('pointerdown', outside);
  }, [expanded]);

  useEffect(() => {
    if (expanded)
      document.getElementById(`${menuId}-${active}`)?.scrollIntoView?.({ block: 'nearest' });
  }, [expanded, active, menuId]);

  const handleKey = (event: KeyboardEvent<HTMLButtonElement>) => {
    if (isDisabled) return;
    if (['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) {
      event.preventDefault();
      if (event.key === 'Home') show(0);
      else if (event.key === 'End') show(options.length - 1);
      else if (!expanded)
        show(
          selectedIndex < 0 && event.key === 'ArrowUp'
            ? options.length - 1
            : Math.max(selectedIndex, 0),
        );
      else
        setActiveIndex(
          (active + (event.key === 'ArrowDown' ? 1 : -1) + options.length) % options.length,
        );
      return;
    }
    if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault();
      if (expanded) choose(active);
      else show();
      return;
    }
    if (event.key === 'Escape' && expanded) {
      event.preventDefault();
      event.stopPropagation();
      close();
      return;
    }
    if (event.key === 'Tab') {
      if (expanded) close(false);
      return;
    }
    if (event.key.length !== 1 || event.ctrlKey || event.metaKey || event.altKey) return;
    event.preventDefault();
    const now = Date.now();
    const text = `${now - search.current.time < 700 ? search.current.text : ''}${event.key.toLocaleLowerCase('ru')}`;
    search.current = { text, time: now };
    const prefix = [...text].every((letter) => letter === text[0]) ? text[0] : text;
    const start = expanded ? active : selectedIndex;
    for (let offset = 1; offset <= options.length; offset++) {
      const index = (start + offset + options.length) % options.length;
      if (options[index].label.toLocaleLowerCase('ru').startsWith(prefix)) {
        if (expanded) setActiveIndex(index);
        else if (options[index].value !== value) onChange(options[index].value);
        break;
      }
    }
  };

  return (
    <>
      <button
        ref={trigger}
        id={triggerId}
        type="button"
        role="combobox"
        aria-label={label}
        aria-haspopup="listbox"
        aria-expanded={expanded}
        aria-controls={expanded ? menuId : undefined}
        aria-activedescendant={expanded ? `${menuId}-${active}` : undefined}
        disabled={isDisabled}
        onKeyDown={handleKey}
        onClick={() => (expanded ? close() : show())}
        onBlur={(event) => {
          if (!menu.current?.contains(event.relatedTarget)) close(false);
        }}
        className={`inline-flex min-h-11 min-w-0 items-center justify-between gap-2 rounded-xl border border-(--color-control-border) bg-(--color-surface) px-3 py-2 text-left text-sm font-medium text-(--color-ink) shadow-[0_1px_2px_#10264a04] transition-colors hover:bg-(--color-surface-muted) focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--color-focus) disabled:cursor-not-allowed disabled:bg-(--color-disabled) disabled:text-(--color-on-disabled) ${className}`}
      >
        {icon && (
          <span aria-hidden="true" className="shrink-0 text-(--color-icon)">
            {icon}
          </span>
        )}
        <span className="min-w-0 flex-1 truncate">
          {options[selectedIndex]?.label ?? 'Выберите'}
        </span>
        <ChevronDown
          size={16}
          aria-hidden="true"
          className={`shrink-0 text-(--color-icon) transition-transform ${expanded ? 'rotate-180' : ''}`}
        />
      </button>
      {expanded &&
        createPortal(
          <div
            ref={menu}
            id={menuId}
            role="listbox"
            aria-label={label}
            style={{ visibility: 'hidden' }}
            onPointerDown={(event) => event.preventDefault()}
            className="fixed z-[100] overflow-y-auto overscroll-contain rounded-xl border border-(--color-border) bg-(--color-surface) p-1.5 font-(family-name:--font-sans) text-sm text-(--color-ink) shadow-[0_12px_36px_#10264a24]"
          >
            {options.map((option, index) => (
              <div
                key={option.value}
                id={`${menuId}-${index}`}
                role="option"
                aria-selected={option.value === value}
                onPointerMove={() => setActiveIndex(index)}
                onClick={() => choose(index)}
                className={`flex min-h-[42px] cursor-pointer items-center justify-between gap-3 rounded-lg px-3 py-2 text-sm ${option.value === value ? 'bg-(--color-brand-primary-soft) font-semibold' : index === active ? 'bg-(--color-surface-muted) font-medium text-(--color-action)' : 'font-medium hover:bg-(--color-surface-muted)'}`}
              >
                <span className="min-w-0 break-words">{option.label}</span>
                {option.value === value && (
                  <Check size={17} aria-hidden="true" className="shrink-0 text-(--color-action)" />
                )}
              </div>
            ))}
          </div>,
          document.body,
        )}
    </>
  );
}

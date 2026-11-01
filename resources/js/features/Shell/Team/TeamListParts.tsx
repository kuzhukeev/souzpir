import { Link } from '@inertiajs/react';
import * as Dialog from '@radix-ui/react-dialog';
import { useRef, type ReactNode } from 'react';
import { X } from 'lucide-react';
import type { TeamMember } from '@/types/Shell/Team/members';

const focus =
  'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--color-focus)';

export function RevokeDialog({
  open,
  onClose,
  busy,
  children,
}: {
  open: boolean;
  onClose: () => void;
  busy: boolean;
  children: ReactNode;
}) {
  const opener = useRef<HTMLElement | null>(null);
  return (
    <Dialog.Root
      open={open}
      onOpenChange={(next) => {
        if (!next) onClose();
      }}
    >
      <Dialog.Portal>
        <Dialog.Overlay className="fixed inset-0 z-50 bg-[#10264a]/30" />
        <Dialog.Content
          aria-describedby={undefined}
          onOpenAutoFocus={() => {
            opener.current =
              document.activeElement instanceof HTMLElement ? document.activeElement : null;
          }}
          onCloseAutoFocus={(event) => {
            if (opener.current?.isConnected) {
              event.preventDefault();
              opener.current.focus();
            }
          }}
          className="fixed top-1/2 left-1/2 z-50 max-h-dvh w-[min(480px,calc(100vw-32px))] max-w-full -translate-x-1/2 -translate-y-1/2 overflow-y-auto rounded-(--radius-card) bg-(--color-surface) text-(--color-ink) shadow-xl"
        >
          <header className="flex items-center justify-between gap-4 border-b border-(--color-border) p-5">
            <Dialog.Title className="text-lg font-bold">Отозвать приглашение</Dialog.Title>
            <Dialog.Close
              aria-label="Закрыть"
              disabled={busy}
              className={`cursor-pointer rounded p-2 text-(--color-icon) disabled:cursor-not-allowed disabled:opacity-50 ${focus}`}
            >
              <X aria-hidden="true" size={20} />
            </Dialog.Close>
          </header>
          <div className="p-5 text-sm">{children}</div>
        </Dialog.Content>
      </Dialog.Portal>
    </Dialog.Root>
  );
}

export function Person({ member }: { member: TeamMember }) {
  const name = member.name?.trim() || 'Имя недоступно';
  const initials =
    member.name
      ?.trim()
      .split(/\s+/u)
      .slice(0, 2)
      .map((part) => part[0])
      .join('')
      .toLocaleUpperCase('ru') || '—';
  return (
    <Link
      href={`/team/${encodeURIComponent(member.id)}`}
      aria-label={`Открыть карточку: ${name}`}
      className={`flex min-w-0 cursor-pointer items-center gap-3 rounded-lg ${focus}`}
    >
      <span
        aria-hidden="true"
        className="grid size-10 shrink-0 place-items-center rounded-full bg-(--color-info-soft) text-sm font-bold text-(--color-info-ink)"
      >
        {initials}
      </span>
      <div className="min-w-0">
        <p className="break-words font-semibold">{name}</p>
        <p className="break-all text-sm text-(--color-ink-muted)">
          {member.email || 'Адрес недоступен'}
        </p>
      </div>
    </Link>
  );
}

export function Brands({ member }: { member: TeamMember }) {
  return member.brands.length ? (
    <ul className="flex flex-wrap gap-2">
      {member.brands.map((brand) => (
        <li
          key={brand.id}
          className="max-w-full break-words rounded-full bg-(--color-success-soft) px-3 py-1 text-sm text-(--color-success-ink)"
        >
          {brand.name}
        </li>
      ))}
    </ul>
  ) : (
    <span className="text-sm text-(--color-ink-muted)">Нет общих доступных брендов</span>
  );
}

export function Status({ value }: { value: string }) {
  const text =
    value === 'active' ? 'Активен' : value === 'disabled' ? 'Отключён' : 'Статус недоступен';
  const color =
    value === 'active'
      ? 'bg-(--color-success-soft) text-(--color-success-ink)'
      : 'bg-(--color-surface-muted) text-(--color-ink-muted)';
  return (
    <span className={`inline-flex rounded-full px-3 py-1 text-sm font-medium ${color}`}>
      {text}
    </span>
  );
}

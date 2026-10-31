import { useRef, type ReactNode } from 'react';
import * as DialogPrimitive from '@radix-ui/react-dialog';
import { X } from 'lucide-react';
type DialogProps = { open: boolean; title: string; onClose: () => void; children: ReactNode };
function Dialog({
  open,
  title,
  onClose,
  children,
  drawer = false,
  wide = false,
}: DialogProps & { drawer?: boolean; wide?: boolean }) {
  const opener = useRef<HTMLElement | null>(null);
  return (
    <DialogPrimitive.Root
      open={open}
      onOpenChange={(next) => {
        if (!next) onClose();
      }}
    >
      <DialogPrimitive.Portal>
        <DialogPrimitive.Overlay className="fixed inset-0 z-50 bg-[#10264a]/30" />
        <DialogPrimitive.Content
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
          className={`fixed z-50 max-h-dvh max-w-full overflow-y-auto bg-(--color-surface) text-(--color-ink) shadow-xl ${drawer ? `inset-y-0 right-0 ${wide ? 'w-[min(640px,100vw)]' : 'w-[min(480px,100vw)]'}` : 'top-1/2 left-1/2 w-[min(480px,calc(100vw-32px))] -translate-x-1/2 -translate-y-1/2 rounded-(--radius-card)'}`}
        >
          <header className="flex items-center justify-between gap-4 border-b border-(--color-border) p-5">
            <DialogPrimitive.Title className="text-lg font-bold">{title}</DialogPrimitive.Title>
            <DialogPrimitive.Close
              aria-label="Закрыть"
              className="rounded p-2 text-(--color-icon) focus-visible:outline-2 focus-visible:outline-(--color-focus)"
            >
              <X aria-hidden="true" size={20} />
            </DialogPrimitive.Close>
          </header>
          <div className="p-5 text-sm">{children}</div>
        </DialogPrimitive.Content>
      </DialogPrimitive.Portal>
    </DialogPrimitive.Root>
  );
}
export function Modal(props: DialogProps) {
  return <Dialog {...props} />;
}
export function Drawer({ size = 'md', ...props }: DialogProps & { size?: 'md' | 'lg' }) {
  return <Dialog {...props} drawer wide={size === 'lg'} />;
}
export function Toast({ message, onClose }: { message: string; onClose?: () => void }) {
  return (
    <div
      role="status"
      className="flex items-center gap-3 rounded-xl border border-(--color-border) bg-(--color-surface) p-4 text-sm shadow-(--shadow-card)"
    >
      <span>{message}</span>
      {onClose && (
        <button
          type="button"
          aria-label="Закрыть уведомление"
          onClick={onClose}
          className="ml-auto rounded p-1 text-(--color-link)"
        >
          <X size={18} aria-hidden="true" />
        </button>
      )}
    </div>
  );
}

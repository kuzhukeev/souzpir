import * as Popover from '@radix-ui/react-popover';
import { useState } from 'react';

export default function JournalRowMenu({ url, onDetails, detailsLabel = 'Детали действия' }: { url: string | null; onDetails: () => void; detailsLabel?: string }) {
  const [open, setOpen] = useState(false);
  const item = 'block w-full cursor-pointer rounded-lg px-3 py-2 text-left text-sm hover:bg-(--color-surface-muted) focus-visible:outline-2 focus-visible:outline-(--color-action)';
  return (
    <Popover.Root open={open} onOpenChange={setOpen}>
      <Popover.Trigger aria-label="Действия с записью" className="min-h-10 min-w-10 cursor-pointer rounded-lg text-xl hover:bg-(--color-surface-muted)">⋮</Popover.Trigger>
      <Popover.Portal>
        <Popover.Content align="end" sideOffset={4} aria-label="Действия с записью" className="z-40 min-w-52 rounded-xl border border-(--color-border) bg-(--color-surface) p-1 text-(--color-ink) shadow-lg">
          <button type="button" className={item} onClick={() => { setOpen(false); onDetails(); }}>{detailsLabel}</button>
          {url && <a className={item} href={url}>Открыть объект</a>}
        </Popover.Content>
      </Popover.Portal>
    </Popover.Root>
  );
}

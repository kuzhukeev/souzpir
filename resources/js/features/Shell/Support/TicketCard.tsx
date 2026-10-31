import { fileSize } from './format';
import { ChevronDown, Download, MessageCircle, ShieldCheck } from 'lucide-react';
import { Button } from '@/components/ui';
import type { Ticket } from '@/types/Shell/Support';
import { DateValue, focus } from './Shared';
import { useSupportCopy } from './copy';
export default function TicketCard({
  ticket,
  busy,
  onRevoke,
}: {
  ticket: Ticket;
  busy: boolean;
  onRevoke: (ticket: Ticket) => void;
}) {
  const c = useSupportCopy();
  const access = ticket.access;
  return (
    <details
      data-ticket-id={ticket.id}
      className="group border-t border-(--color-border) first:border-t-0"
    >
      <summary
        className={[
          'flex cursor-pointer list-none items-start gap-3 p-4 sm:p-5 [&::-webkit-details-marker]:hidden',
          focus,
        ].join(' ')}
      >
        <span className="grid size-10 shrink-0 place-items-center rounded-full bg-(--color-info-soft) text-(--color-link)">
          <MessageCircle size={21} />
        </span>
        <div className="min-w-0 flex-1">
          <h3 className="break-words text-base font-semibold">{ticket.subject_label}</h3>
          <p className="mt-1 text-sm text-(--color-ink-muted)">{ticket.module_label}</p>
          <div className="mt-3 flex flex-wrap items-center gap-2">
            <span
              className={[
                'inline-flex items-center gap-2 rounded-full px-3 py-1 text-sm',
                ticket.status === 'open'
                  ? 'bg-(--color-success-soft) text-(--color-success-ink)'
                  : 'bg-(--color-surface-muted) text-(--color-ink-muted)',
              ].join(' ')}
            >
              <span aria-hidden="true" className="size-2 rounded-full bg-current" />
              {c[ticket.status]}
            </span>
            <span className="text-sm text-(--color-ink-muted)">
              <DateValue value={ticket.created_at} />
            </span>
          </div>
        </div>
        <ChevronDown
          className="mt-1 shrink-0 text-(--color-link) group-open:rotate-180"
          size={20}
        />
      </summary>
      <div className="space-y-4 px-4 pb-5 sm:px-5">
        <p className="break-all text-sm text-(--color-ink-muted)">
          {c.ticketNumber}: {ticket.id}
        </p>
        <p className="whitespace-pre-wrap break-words text-sm leading-6">{ticket.body}</p>
        {ticket.attachments.length > 0 && (
          <section>
            <h4 className="mb-2 text-sm font-semibold">{c.attachments}</h4>
            <ul className="space-y-2">
              {ticket.attachments.map((attachment) => (
                <li key={attachment.id}>
                  <a
                    href={attachment.download_url}
                    download
                    aria-label={c.download + ': ' + attachment.name}
                    className={[
                      'flex min-h-11 items-center gap-3 rounded-lg border border-(--color-border) p-3 text-sm text-(--color-link)',
                      focus,
                    ].join(' ')}
                  >
                    <Download size={18} className="shrink-0" />
                    <span className="min-w-0 flex-1 break-all">{attachment.name}</span>
                    <span className="shrink-0 text-(--color-ink-muted)">
                      {fileSize(attachment.size_bytes)}
                    </span>
                  </a>
                </li>
              ))}
            </ul>
          </section>
        )}
        <section className="rounded-xl bg-(--color-surface-muted) p-4">
          <h4 className="flex items-center gap-2 text-sm font-semibold">
            <ShieldCheck size={18} />
            {c.consentTitle}
          </h4>
          {access ? (
            <>
              <p className="mt-2 text-sm font-medium">
                {access.status === 'active'
                  ? c.consentActive
                  : access.status === 'expired'
                    ? c.consentExpired
                    : c.consentRevoked}
              </p>
              <dl className="mt-3 space-y-2 text-sm text-(--color-ink-muted)">
                <div>
                  <dt>{c.grantFrom}</dt>
                  <dd>
                    <DateValue value={access.granted_at} />
                  </dd>
                </div>
                <div>
                  <dt>{c.grantUntil}</dt>
                  <dd>
                    <DateValue value={access.expires_at} />
                  </dd>
                </div>
                {access.revoked_at && (
                  <div>
                    <dt>{c.revokedAt}</dt>
                    <dd>
                      <DateValue value={access.revoked_at} />
                    </dd>
                  </div>
                )}
              </dl>
              {access.can_revoke && (
                <Button
                  variant="secondary"
                  className="mt-4"
                  disabled={busy}
                  onClick={() => onRevoke(ticket)}
                >
                  {c.revoke}
                </Button>
              )}
            </>
          ) : (
            <p className="mt-2 text-sm text-(--color-ink-muted)">{c.consentNone}</p>
          )}
        </section>
        <p className="text-sm leading-6 text-(--color-ink-muted)">
          {c.delivery}:{' '}
          {ticket.delivery_status === 'sent'
            ? c.deliverySent
            : ticket.delivery_status === 'uncertain'
              ? c.deliveryUncertain
              : ticket.delivery_status === 'disabled'
                ? c.deliveryDisabled
                : c.notConfigured}
        </p>
      </div>
    </details>
  );
}

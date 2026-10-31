import { fileSize } from '@/features/Shell/Support/format';
import { useCallback, useEffect, useRef, useState, type FormEvent, type ChangeEvent } from 'react';
import { flushSync } from 'react-dom';
import { Head } from '@inertiajs/react';
import { Paperclip, RefreshCw, Send, X, MessageCircle, ShieldCheck } from 'lucide-react';
import { Button, Textarea, Select, Modal, EmptyState, Skeleton } from '@/components/ui';
import {
  SupportPage,
  ErrorSummary,
  PaginationBar,
  panel,
  focus,
} from '@/features/Shell/Support/Shared';
import TicketCard from '@/features/Shell/Support/TicketCard';
import { useSupportCopy } from '@/features/Shell/Support/copy';
import { supportList, supportRequest, supportError } from '@/features/Shell/Support/request';
import {
  clearSubmission,
  pendingSubmission,
  submissionKey,
} from '@/features/Shell/Support/submission';
import type { Ticket, TicketsData, CreatedTicket } from '@/types/Shell/Support';
const emptyFailure = () => ({ message: '', fields: {} as Record<string, string> });
export default function Tickets({ support }: { support: TicketsData }) {
  const c = useSupportCopy();
  const [tickets, setTickets] = useState(() => ({ ...support.tickets, data: [] as Ticket[] }));
  const [hasPendingSubmission, setHasPendingSubmission] = useState(() => {
    try {
      return pendingSubmission(support.submission_scope) !== null;
    } catch {
      return false;
    }
  });
  const [subject, setSubject] = useState('');
  const [module, setModule] = useState('');
  const [body, setBody] = useState('');
  const [files, setFiles] = useState<File[]>([]);
  const [consent, setConsent] = useState(false);
  const [pending, setPending] = useState(false);
  const [loading, setLoading] = useState(false);
  const [failure, setFailure] = useState(emptyFailure);
  const [listFailure, setListFailure] = useState(emptyFailure);
  const [outcome, setOutcome] = useState<CreatedTicket | null>(null);
  const [confirm, setConfirm] = useState<Ticket | null>(null);
  const [revoking, setRevoking] = useState(false);
  const [revokeFailure, setRevokeFailure] = useState(emptyFailure);
  const [notice, setNotice] = useState('');
  const uploadInput = useRef<HTMLInputElement>(null);
  const nextSubmission = useRef(support.submission_key);
  const outcomeRef = useRef<HTMLDivElement>(null);
  const listRequest = useRef<AbortController | null>(null);
  const activePage = useRef(true);
  const pageGeneration = useRef(0);
  useEffect(() => {
    if (outcome) {
      outcomeRef.current?.focus({ preventScroll: true });
      outcomeRef.current?.scrollIntoView({ block: 'nearest' });
    }
  }, [outcome]);
  const refresh = useCallback(
    (page = 1) => {
      listRequest.current?.abort();
      const controller = new AbortController();
      listRequest.current = controller;
      setListFailure(emptyFailure());
      setLoading(true);
      void supportList(page, controller.signal)
        .then((result) => {
          if (!controller.signal.aborted && activePage.current) setTickets(result.tickets);
        })
        .catch(() => {
          if (!controller.signal.aborted && activePage.current)
            setListFailure({ message: c.loadFailed, fields: {} });
        })
        .finally(() => {
          if (!controller.signal.aborted && activePage.current) setLoading(false);
        });
    },
    [c.loadFailed],
  );
  useEffect(() => {
    activePage.current = true;
    nextSubmission.current = support.submission_key;
    // Start a private fetch when this Inertia page mounts.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    refresh(support.tickets.current_page);
    const hide = () => {
      activePage.current = false;
      pageGeneration.current += 1;
      listRequest.current?.abort();
      // Browsers may freeze the DOM in the back-forward cache. Remove private
      // content synchronously before that snapshot, and refetch on restoration.
      flushSync(() => {
        setBody('');
        setFiles([]);
        setSubject('');
        setModule('');
        setConsent(false);
        setPending(false);
        setLoading(false);
        setTickets((previous) => ({ ...previous, data: [] }));
        setConfirm(null);
        setOutcome(null);
        setFailure(emptyFailure());
        setListFailure(emptyFailure());
      });
    };
    const show = (event: PageTransitionEvent) => {
      if (event.persisted) {
        activePage.current = true;
        refresh(1);
      }
    };
    window.addEventListener('pagehide', hide);
    window.addEventListener('pageshow', show);
    return () => {
      activePage.current = false;
      pageGeneration.current += 1;
      listRequest.current?.abort();
      window.removeEventListener('pagehide', hide);
      window.removeEventListener('pageshow', show);
    };
  }, [refresh, support]);
  const addFiles = (event: ChangeEvent<HTMLInputElement>) => {
    const additions = Array.from(event.target.files ?? []);
    event.target.value = '';
    const next = [...files, ...additions];
    if (next.length > support.upload.max_files) {
      setFailure({ message: c.tooManyFiles, fields: {} });
      return;
    }
    if (next.some((file) => file.size > support.upload.max_file_kb * 1024)) {
      setFailure({ message: c.fileTooLarge, fields: {} });
      return;
    }
    setFailure(emptyFailure());
    setFiles(next);
  };
  const submit = async (event: FormEvent) => {
    event.preventDefault();
    if (!support.can_submit || pending) return;
    setFailure(emptyFailure());
    setOutcome(null);
    if (!subject || !module || !body.trim()) {
      setFailure({ message: c.chooseRequired, fields: {} });
      return;
    }
    const data = new FormData();
    try {
      data.set('submission_key', submissionKey(support.submission_scope, nextSubmission.current));
      setHasPendingSubmission(true);
    } catch {
      setFailure({ message: c.submissionUnavailable, fields: {} });
      return;
    }
    const generation = pageGeneration.current;
    data.set('subject', subject);
    data.set('module', module);
    data.set('body', body);
    data.set('support_access', consent ? '1' : '0');
    files.forEach((file) => data.append('attachments[]', file));
    setPending(true);
    try {
      const result = await supportRequest<CreatedTicket>('/help/support', data);
      // A response after leaving the page was never confirmed to the user.
      // Preserve the key so they can safely retry that attempt.
      if (!activePage.current || generation !== pageGeneration.current) return;
      try {
        clearSubmission(support.submission_scope);
        setHasPendingSubmission(false);
        nextSubmission.current = result.next_submission_key;
      } catch {
        /* The retained key prevents a duplicate on another attempt. */
      }
      setOutcome(result);
      setSubject('');
      setModule('');
      setBody('');
      setFiles([]);
      setConsent(false);
      refresh(1);
    } catch (error) {
      if (activePage.current && generation === pageGeneration.current)
        setFailure(supportError(error));
    } finally {
      if (activePage.current && generation === pageGeneration.current) setPending(false);
    }
  };
  const revoke = async () => {
    if (!confirm || revoking) return;
    setRevokeFailure(emptyFailure());
    setRevoking(true);
    try {
      await supportRequest<{ ticket: Ticket }>('/help/support/' + confirm.id + '/access/revoke');
      setConfirm(null);
      setNotice(c.revokeDone);
      refresh(tickets.current_page);
    } catch (error) {
      setRevokeFailure(supportError(error));
    } finally {
      setRevoking(false);
    }
  };
  const startNew = async () => {
    if (pending) return;
    const generation = pageGeneration.current;
    setPending(true);
    try {
      // Obtain a fresh server-issued key before releasing the uncertain one.
      const fresh = await supportList(1, new AbortController().signal);
      if (!activePage.current || generation !== pageGeneration.current) return;
      if (fresh.submission_scope !== support.submission_scope || !fresh.submission_key)
        throw new Error('submission scope changed');
      clearSubmission(support.submission_scope);
      nextSubmission.current = fresh.submission_key;
      pageGeneration.current += 1;
      setHasPendingSubmission(false);
      setFailure(emptyFailure());
      setSubject('');
      setModule('');
      setBody('');
      setFiles([]);
      setConsent(false);
      setOutcome(null);
      setPending(false);
    } catch {
      if (activePage.current && generation === pageGeneration.current) {
        setFailure({ message: c.submissionUnavailable, fields: {} });
        setPending(false);
      }
    }
  };
  const disabled = pending || !support.can_submit;
  return (
    <>
      <Head title={c.tickets} />
      <SupportPage active="tickets" title={c.tickets} subtitle={c.ticketsSubtitle}>
        <div className="grid items-start gap-5 xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
          <section className={[panel, 'p-5 sm:p-6'].join(' ')}>
            <h2 className="mb-5 text-xl font-bold">{c.newTicket}</h2>
            {!support.can_submit && (
              <p className="mb-5 rounded-xl bg-(--color-info-soft) p-4 text-sm leading-6 text-(--color-info-ink)">
                {c.readonlySupport}
              </p>
            )}
            {outcome && (
              <div
                ref={outcomeRef}
                tabIndex={-1}
                role="status"
                className="mb-5 space-y-2 rounded-xl bg-(--color-success-soft) p-4 text-sm leading-6 text-(--color-success-ink)"
              >
                <p className="font-semibold">{c.saved}</p>
                <p className="break-all">
                  {c.ticketNumber}: {outcome.ticket.id}
                </p>
                <p>{outcome.delivery.message}</p>
              </div>
            )}
            {hasPendingSubmission && (
              <div className="mb-5 space-y-2 rounded-xl bg-(--color-info-soft) p-4 text-sm leading-6 text-(--color-info-ink)">
                <p>{c.pendingSubmission}</p>
                <Button variant="secondary" disabled={pending} onClick={() => void startNew()}>
                  {c.newSubmission}
                </Button>
              </div>
            )}
            <form
              noValidate
              autoComplete="off"
              onSubmit={(event) => void submit(event)}
              className="space-y-5"
            >
              <ErrorSummary failure={failure} focusOnChange />
              <Select
                label={c.topic}
                value={subject}
                onValueChange={setSubject}
                options={support.topics}
                placeholder={c.chooseTopic}
                error={failure.fields.subject}
                disabled={disabled}
              />
              <Select
                label={c.module}
                value={module}
                onValueChange={setModule}
                options={support.modules}
                placeholder={c.chooseModule}
                error={failure.fields.module}
                disabled={disabled}
              />
              <Textarea
                label={c.description}
                placeholder={c.descriptionHint}
                value={body}
                onChange={(event) => setBody(event.target.value)}
                rows={7}
                required
                disabled={disabled}
                error={failure.fields.body}
              />
              <fieldset disabled={disabled} className="min-w-0">
                <legend className="mb-2 text-sm font-semibold">{c.attachments}</legend>
                <div className="relative">
                  <input
                    ref={uploadInput}
                    type="file"
                    multiple
                    accept={support.upload.accept.join(',')}
                    aria-label={c.addAttachment}
                    className="sr-only"
                    tabIndex={-1}
                    disabled={disabled}
                    onChange={addFiles}
                  />
                  <Button
                    variant="secondary"
                    className="w-full border-dashed"
                    disabled={disabled}
                    onClick={() => uploadInput.current?.click()}
                  >
                    <Paperclip size={19} />
                    {c.addAttachment}
                  </Button>
                </div>
                <p className="mt-2 text-sm leading-6 text-(--color-ink-muted)">
                  {c.maxFiles}: {support.upload.max_files}. {c.maxSize}{' '}
                  {fileSize(support.upload.max_file_kb * 1024)}.
                </p>
                <p className="mt-1 text-sm leading-6 text-(--color-ink-muted)">{c.privateFiles}</p>
                {files.length > 0 && (
                  <ul className="mt-3 space-y-2">
                    {files.map((file, index) => (
                      <li
                        key={file.name + '-' + index}
                        className="flex items-center gap-2 rounded-lg bg-(--color-surface-muted) p-3 text-sm"
                      >
                        <span className="min-w-0 flex-1 break-all">
                          {file.name}
                          <span className="mt-1 block text-(--color-ink-muted)">
                            {fileSize(file.size)}
                          </span>
                        </span>
                        <Button
                          variant="ghost"
                          className="min-h-11 min-w-11 px-2"
                          aria-label={c.removeAttachment + ': ' + file.name}
                          disabled={disabled}
                          onClick={() =>
                            setFiles((current) => current.filter((_, at) => at !== index))
                          }
                        >
                          <X size={18} />
                        </Button>
                      </li>
                    ))}
                  </ul>
                )}
              </fieldset>
              <div className="rounded-xl bg-(--color-surface-muted) p-4">
                <label
                  className={[
                    'flex items-start gap-3 text-sm font-medium',
                    disabled ? 'cursor-not-allowed' : 'cursor-pointer',
                  ].join(' ')}
                >
                  <input
                    type="checkbox"
                    checked={consent}
                    onChange={(event) => setConsent(event.target.checked)}
                    disabled={disabled}
                    className={['mt-1 size-4 shrink-0 accent-(--color-action)', focus].join(' ')}
                  />
                  <span>{c.consent}</span>
                </label>
                <p className="mt-3 flex items-start gap-2 text-sm leading-6 text-(--color-ink-muted)">
                  <ShieldCheck size={18} className="mt-1 shrink-0" />
                  <span>{support.access.entry_available ? c.consentAvailable : c.consentHint}</span>
                </p>
              </div>
              {support.can_submit && (
                <Button type="submit" loading={pending} className="w-full sm:w-auto">
                  <Send size={18} />
                  {pending ? c.sending : c.send}
                </Button>
              )}
            </form>
          </section>
          <section className={panel}>
            <header className="flex flex-wrap items-center justify-between gap-3 p-5">
              <h2 className="flex items-center gap-2 text-xl font-bold">
                <MessageCircle size={22} className="text-(--color-link)" />
                {c.myTickets}
                <span className="text-sm font-normal text-(--color-ink-muted)">
                  {tickets.total}
                </span>
              </h2>
              <Button
                variant="ghost"
                aria-label={c.refresh}
                loading={loading}
                onClick={() => refresh(tickets.current_page)}
              >
                <RefreshCw size={18} />
                {c.refresh}
              </Button>
            </header>
            <div className="space-y-3 px-5">
              <ErrorSummary failure={listFailure} />
              {notice && (
                <p
                  role="status"
                  className="rounded-xl bg-(--color-success-soft) p-3 text-sm text-(--color-success-ink)"
                >
                  {notice}
                </p>
              )}
            </div>
            <div aria-busy={loading}>
              {loading && !tickets.data.length ? (
                <div className="space-y-4 p-5">
                  <Skeleton label={c.loading} />
                  <Skeleton label={c.loading} />
                </div>
              ) : tickets.data.length ? (
                tickets.data.map((ticket) => (
                  <TicketCard
                    key={ticket.id}
                    ticket={ticket}
                    busy={revoking || loading}
                    onRevoke={(value) => {
                      setRevokeFailure(emptyFailure());
                      setConfirm(value);
                    }}
                  />
                ))
              ) : (
                <EmptyState title={c.noTickets} description={c.noTicketsHint} />
              )}
            </div>
            <PaginationBar page={tickets} busy={loading} onChange={refresh} />
          </section>
        </div>
      </SupportPage>
      <Modal
        open={confirm !== null}
        title={c.revokeTitle}
        onClose={() => {
          if (!revoking) setConfirm(null);
        }}
      >
        <div className="support-dialog space-y-4">
          <p className="text-sm leading-6 text-(--color-ink-muted)">{c.revokeHint}</p>
          <ErrorSummary failure={revokeFailure} focusOnChange />
          <div className="flex flex-wrap justify-end gap-2">
            <Button variant="secondary" disabled={revoking} onClick={() => setConfirm(null)}>
              {c.cancel}
            </Button>
            <Button variant="danger" loading={revoking} onClick={() => void revoke()}>
              {c.revoke}
            </Button>
          </div>
        </div>
      </Modal>
    </>
  );
}

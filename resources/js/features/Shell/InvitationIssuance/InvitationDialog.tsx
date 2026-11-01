import * as Dialog from '@radix-ui/react-dialog';
import * as Select from '@radix-ui/react-select';
import { router, usePage } from '@inertiajs/react';
import { Check, ChevronDown, Copy, X } from 'lucide-react';
import { useCallback, useEffect, useLayoutEffect, useRef, useState, type FormEvent } from 'react';
import {
  profileContextIsStable,
  subscribeProfileContext,
} from '@/features/Shell/Profile/contextFence';

export type InvitationBrand = { id: string; name: string };
export type InvitationDialogProps = {
  open: boolean;
  onClose: () => void;
  canIssue: boolean;
  brandId: string;
  revision: string;
  brands: InvitationBrand[];
};
type Phase = 'form' | 'confirm' | 'sending' | 'issued' | 'uncertain' | 'invalid';
type Role = 'admin' | 'editor' | 'manager' | 'viewer';
const roles = [
  { value: 'admin', label: 'Администратор' },
  { value: 'editor', label: 'Редактор' },
  { value: 'manager', label: 'Менеджер' },
  { value: 'viewer', label: 'Наблюдатель' },
];
const focus =
  'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--color-focus)';
const button = `inline-flex min-h-11 cursor-pointer items-center justify-center gap-2 rounded-lg border border-(--color-border) px-4 text-sm font-semibold text-(--color-link) disabled:cursor-not-allowed disabled:opacity-50 ${focus}`;
const primary = `inline-flex min-h-11 cursor-pointer items-center justify-center rounded-lg bg-(--color-action) px-4 text-sm font-semibold text-(--color-on-action) hover:bg-(--color-action-hover) disabled:cursor-not-allowed disabled:opacity-50 ${focus}`;

function csrfToken(): string | null {
  const item = document.cookie.split('; ').find((part) => part.startsWith('XSRF-TOKEN='));
  if (!item) return null;
  try {
    return decodeURIComponent(item.slice('XSRF-TOKEN='.length));
  } catch {
    return null;
  }
}

function attemptKey(): string | null {
  try {
    const bytes = new Uint8Array(32);
    crypto.getRandomValues(bytes);
    return Array.from(bytes, (value) => value.toString(16).padStart(2, '0')).join('');
  } catch {
    return null;
  }
}

function issuedLink(data: unknown): string | null {
  if (!data || typeof data !== 'object' || !('link' in data) || typeof data.link !== 'string')
    return null;
  try {
    const url = new URL(data.link);
    const local = ['souzpir.local', 'localhost', '127.0.0.1', '[::1]'].includes(
      url.hostname.toLowerCase(),
    );
    if (
      url.username ||
      url.password ||
      url.pathname !== '/invitations/open' ||
      url.search ||
      !/^[A-Za-z0-9]{64}$/.test(url.hash.slice(1)) ||
      !(url.protocol === 'https:' || (url.protocol === 'http:' && local))
    )
      return null;
    return url.href;
  } catch {
    return null;
  }
}

async function confirmContext(
  brandId: string,
  revision: string,
  version: string,
  selected: string[],
  signal: AbortSignal,
): Promise<boolean> {
  const response = await fetch('/team', {
    credentials: 'same-origin',
    cache: 'no-store',
    redirect: 'error',
    signal,
    headers: {
      'X-Inertia': 'true',
      'X-Inertia-Version': version,
      Accept: 'text/html, application/xhtml+xml',
      'X-Requested-With': 'XMLHttpRequest',
    },
  });
  if (!response.ok || response.redirected) return false;
  const page: unknown = await response.json();
  if (
    !page ||
    typeof page !== 'object' ||
    !('version' in page) ||
    (page.version ?? '') !== version ||
    !('props' in page) ||
    !page.props ||
    typeof page.props !== 'object'
  )
    return false;
  const props = page.props as {
    teamBrandRevision?: unknown;
    canViewRoles?: unknown;
    brand?: { id?: unknown };
    filterBrands?: unknown;
  };
  if (
    props.teamBrandRevision !== revision ||
    props.canViewRoles !== true ||
    props.brand?.id !== brandId ||
    !Array.isArray(props.filterBrands)
  )
    return false;
  return selected.every((id) =>
    (props.filterBrands as unknown[]).some(
      (item) => item && typeof item === 'object' && 'id' in item && item.id === id,
    ),
  );
}

/** The parent passes only currently authorized brands and the current session revision. */
export default function InvitationDialog({
  open,
  onClose,
  canIssue,
  brandId,
  revision,
  brands,
}: InvitationDialogProps) {
  const inertiaVersion = usePage().version ?? '';
  const context = JSON.stringify([
    brandId,
    revision,
    inertiaVersion,
    canIssue,
    brands.map((brand) => brand.id).sort(),
  ]);
  const latest = useRef(context);
  const sequence = useRef(0);
  const pending = useRef<AbortController | null>(null);
  const pendingLink = useRef<string | null>(null);
  const uncertainAttempt = useRef(false);
  const input = useRef<HTMLTextAreaElement | null>(null);
  const attachInput = useCallback((node: HTMLTextAreaElement | null) => {
    if (!node && input.current) input.current.value = '';
    input.current = node;
  }, []);
  const opener = useRef<HTMLElement | null>(null);
  const alive = useRef(false);
  const [phase, setPhase] = useState<Phase>('form');
  const [phaseContext, setPhaseContext] = useState(context);
  const [email, setEmail] = useState('');
  const [role, setRole] = useState<Role>('viewer');
  const [selected, setSelected] = useState<string[]>([]);
  const [link, setLink] = useState<string | null>(null);
  const [message, setMessage] = useState('');
  const contextCurrent = phaseContext === context && canIssue && Boolean(brandId && revision);

  const erase = () => {
    sequence.current += 1;
    pending.current?.abort();
    pending.current = null;
    pendingLink.current = null;
    if (input.current) input.current.value = '';
    setLink(null);
  };
  const invalidate = () => {
    const wasPending = pending.current !== null;
    if (wasPending) uncertainAttempt.current = true;
    erase();
    if (alive.current) {
      setPhase('invalid');
      setMessage(
        wasPending
          ? 'Результат неизвестен: приглашение могло быть создано. Проверьте список команды перед новым выпуском.'
          : 'Контекст изменился или доступ больше не подтверждён. Обновите страницу и проверьте список приглашений.',
      );
    }
  };
  const close = () => {
    if (pending.current || phase === 'uncertain') uncertainAttempt.current = true;
    erase();
    setEmail('');
    setSelected([]);
    setMessage('');
    setPhase('form');
    onClose();
  };

  /* Sensitive data must be erased synchronously before a changed context is painted. */
  /* eslint-disable react-hooks/set-state-in-effect */
  useLayoutEffect(() => {
    latest.current = context;
    const wasPending = pending.current !== null || (open && uncertainAttempt.current);
    if (wasPending) uncertainAttempt.current = true;
    erase();
    setPhaseContext(context);
    setEmail('');
    setRole('viewer');
    setSelected(brands.some((brand) => brand.id === brandId) ? [brandId] : []);
    setMessage(
      wasPending
        ? 'Результат неизвестен: приглашение могло быть создано. Проверьте список команды перед новым выпуском.'
        : '',
    );
    setPhase(wasPending ? 'uncertain' : 'form');
    // Brand and permission revision define the security context, not the array identity.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [context, open]);
  /* eslint-enable react-hooks/set-state-in-effect */

  useEffect(() => {
    alive.current = true;
    const contextChange = subscribeProfileContext(invalidate);
    const leave = () => invalidate();
    const onVisibility = () => {
      if (document.visibilityState !== 'visible') invalidate();
    };
    const navigation = router.on('start', leave);
    window.addEventListener('pagehide', leave);
    document.addEventListener('visibilitychange', onVisibility);
    return () => {
      alive.current = false;
      sequence.current += 1;
      pending.current?.abort();
      pending.current = null;
      pendingLink.current = null;
      contextChange();
      navigation();
      window.removeEventListener('pagehide', leave);
      document.removeEventListener('visibilitychange', onVisibility);
    };
    // Event listeners read refs; no secret is kept by the event handlers.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  useEffect(() => {
    if (!open || phase !== 'issued' || !link || !contextCurrent) return;
    let active = true;
    let controller: AbortController | null = null;
    let deadline: number | undefined;
    const verify = async () => {
      if (controller) return;
      controller = new AbortController();
      deadline = window.setTimeout(() => {
        controller?.abort();
        if (active) invalidate();
      }, 5_000);
      try {
        if (
          !profileContextIsStable() ||
          !(await confirmContext(brandId, revision, inertiaVersion, selected, controller.signal))
        )
          throw new Error();
      } catch {
        if (active) invalidate();
      } finally {
        window.clearTimeout(deadline);
        controller = null;
      }
    };
    const timer = window.setInterval(() => {
      void verify();
    }, 5_000);
    return () => {
      active = false;
      window.clearInterval(timer);
      window.clearTimeout(deadline);
      controller?.abort();
    };
    // The displayed link is discarded on every context change.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, phase, link, context]);

  const prepare = (event: FormEvent) => {
    event.preventDefault();
    if (!contextCurrent || !profileContextIsStable() || !selected.length || !email.trim()) {
      setMessage('Проверьте адрес, выбранные бренды и доступ к текущему бренду.');
      return;
    }
    if (selected.some((id) => !brands.some((brand) => brand.id === id))) {
      invalidate();
      return;
    }
    setMessage('');
    setPhase('confirm');
  };

  const submit = async () => {
    if (
      phase !== 'confirm' ||
      !contextCurrent ||
      !profileContextIsStable() ||
      document.visibilityState !== 'visible'
    ) {
      invalidate();
      return;
    }
    const key = attemptKey();
    const csrf = csrfToken();
    if (!key || !csrf) {
      setMessage('Не удалось безопасно начать выпуск. Обновите страницу и повторите действие.');
      setPhase('form');
      return;
    }
    const generation = ++sequence.current;
    const requestContext = context;
    const controller = new AbortController();
    pending.current = controller;
    const timeout = window.setTimeout(() => {
      if (!alive.current || generation !== sequence.current || latest.current !== requestContext)
        return;
      sequence.current += 1;
      controller.abort();
      pending.current = null;
      pendingLink.current = null;
      uncertainAttempt.current = true;
      setLink(null);
      setMessage(
        'Результат неизвестен: приглашение могло быть создано. Проверьте список команды перед новым выпуском.',
      );
      setPhase('uncertain');
    }, 15_000);
    const current = () =>
      alive.current && generation === sequence.current && latest.current === requestContext;
    const stable = () => profileContextIsStable() && document.visibilityState === 'visible';
    setMessage('');
    setPhase('sending');
    try {
      const response = await fetch('/team/invitations', {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        redirect: 'error',
        signal: controller.signal,
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          'X-XSRF-TOKEN': csrf,
          'X-Brand-Revision': revision,
        },
        body: JSON.stringify({
          attempt_key: key,
          email: email.trim(),
          role: role === 'editor' || role === 'manager' ? 'member' : role,
          sales_manager: role === 'manager',
          brand_ids: selected,
        }),
      });
      if (!current()) return;
      if (!stable()) {
        invalidate();
        return;
      }
      if (
        response.status === 401 ||
        response.status === 403 ||
        response.status === 404 ||
        response.status === 409 ||
        response.status === 419 ||
        response.status === 422
      ) {
        setMessage(
          response.status === 422
            ? 'Проверьте адрес, роль и доступные бренды.'
            : 'Доступ или контекст изменились. Обновите страницу и проверьте список приглашений.',
        );
        setPhase(response.status === 422 ? 'form' : 'invalid');
        return;
      }
      if (response.status !== 201 || response.redirected) throw new Error();
      {
        let candidate = issuedLink(await response.json());
        if (!current()) {
          candidate = null;
          return;
        }
        if (!stable()) {
          candidate = null;
          invalidate();
          return;
        }
        pendingLink.current = candidate;
        candidate = null;
      }
      if (!pendingLink.current) throw new Error();
      if (!(await confirmContext(brandId, revision, inertiaVersion, selected, controller.signal)))
        throw new Error();
      if (!current()) return;
      if (!stable()) {
        invalidate();
        return;
      }
      const value = pendingLink.current;
      pendingLink.current = null;
      if (!value) throw new Error();
      uncertainAttempt.current = false;
      setLink(value);
      setPhase('issued');
    } catch {
      if (alive.current && generation === sequence.current && latest.current === requestContext) {
        uncertainAttempt.current = true;
        pendingLink.current = null;
        setLink(null);
        setMessage(
          'Результат неизвестен: приглашение могло быть создано. Проверьте список команды перед новым выпуском.',
        );
        setPhase('uncertain');
      }
    } finally {
      window.clearTimeout(timeout);
      if (pending.current === controller) pending.current = null;
    }
  };

  const copy = async () => {
    if (!link || !contextCurrent) return;
    try {
      if (!navigator.clipboard?.writeText) throw new Error();
      await navigator.clipboard.writeText(link);
      if (contextCurrent) setMessage('Ссылка скопирована.');
    } catch {
      input.current?.focus();
      input.current?.select();
      setMessage('Выделите ссылку и скопируйте вручную.');
    }
  };
  const visiblePhase = contextCurrent ? phase : 'invalid';

  return (
    <Dialog.Root
      open={open}
      onOpenChange={(next) => {
        if (!next) close();
      }}
    >
      <Dialog.Portal>
        <Dialog.Overlay className="fixed inset-0 z-50 bg-[#10264a]/30" />
        <Dialog.Content
          aria-describedby="invitation-help"
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
          className="fixed top-1/2 left-1/2 z-50 max-h-[calc(100dvh-32px)] w-[min(520px,calc(100vw-32px))] -translate-x-1/2 -translate-y-1/2 overflow-y-auto rounded-(--radius-card) bg-(--color-surface) text-(--color-ink) shadow-xl"
        >
          <header className="flex items-center justify-between gap-4 border-b border-(--color-border) p-5">
            <Dialog.Title className="text-lg font-bold">Пригласить участника</Dialog.Title>
            <Dialog.Close aria-label="Закрыть" className={`cursor-pointer rounded p-2 ${focus}`}>
              <X size={20} aria-hidden="true" />
            </Dialog.Close>
          </header>
          <div className="space-y-4 p-5 text-sm">
            <p id="invitation-help">
              Ссылка действует 7 суток. Отправьте её получателю самостоятельно.
            </p>
            {visiblePhase === 'form' && (
              <form onSubmit={prepare} className="space-y-4">
                <label className="block font-semibold" htmlFor="invite-email">
                  Электронная почта
                </label>
                <input
                  id="invite-email"
                  type="email"
                  autoComplete="off"
                  required
                  maxLength={254}
                  value={email}
                  onChange={(event) => setEmail(event.target.value)}
                  className={`min-h-11 w-full rounded-lg border border-(--color-border) bg-(--color-surface) px-3 font-normal ${focus}`}
                />
                <div className="space-y-2">
                  <span id="invitation-role-label" className="block font-semibold">
                    Роль
                  </span>
                  <Select.Root value={role} onValueChange={(value) => setRole(value as Role)}>
                    <Select.Trigger
                      aria-labelledby="invitation-role-label"
                      className={`flex min-h-11 w-full cursor-pointer items-center justify-between gap-3 rounded-lg border border-(--color-border) bg-(--color-surface) px-3 text-sm ${focus}`}
                    >
                      <Select.Value />
                      <Select.Icon>
                        <ChevronDown size={16} />
                      </Select.Icon>
                    </Select.Trigger>
                    <Select.Portal>
                      <Select.Content
                        position="popper"
                        sideOffset={6}
                        className="z-[60] min-w-[var(--radix-select-trigger-width)] rounded-xl border border-(--color-border) bg-(--color-surface) p-1.5 shadow-xl"
                      >
                        <Select.Viewport>
                          {roles.map((option) => (
                            <Select.Item
                              key={option.value}
                              value={option.value}
                              className="relative flex min-h-11 cursor-pointer items-center justify-between gap-3 rounded-lg px-3 text-sm outline-none data-[highlighted]:bg-(--color-surface-muted)"
                            >
                              <Select.ItemText>{option.label}</Select.ItemText>
                              <Select.ItemIndicator>
                                <Check size={16} />
                              </Select.ItemIndicator>
                            </Select.Item>
                          ))}
                        </Select.Viewport>
                      </Select.Content>
                    </Select.Portal>
                  </Select.Root>
                </div>
                <fieldset className="space-y-2">
                  <legend className="mb-2 font-semibold">Доступные бренды</legend>
                  {brands.length === 0 && <p>Нет доступных брендов.</p>}
                  {brands.map((brand) => (
                    <label
                      key={brand.id}
                      className="flex cursor-pointer items-start gap-3 rounded-lg border border-(--color-border) p-3"
                    >
                      <input
                        type="checkbox"
                        className="mt-1 size-4 cursor-pointer"
                        checked={selected.includes(brand.id)}
                        onChange={(event) =>
                          setSelected((ids) =>
                            event.target.checked
                              ? [...ids, brand.id]
                              : ids.filter((id) => id !== brand.id),
                          )
                        }
                      />
                      <span className="break-words">{brand.name}</span>
                    </label>
                  ))}
                </fieldset>
                <button className={primary} type="submit" disabled={!canIssue || !brands.length}>
                  Продолжить
                </button>
              </form>
            )}
            {visiblePhase === 'confirm' && (
              <div className="space-y-4">
                <p>
                  Создать одноразовую ссылку для{' '}
                  <strong className="break-all">{email.trim()}</strong>?
                </p>
                <p>Роль: {roles.find((option) => option.value === role)?.label}</p>
                <p>
                  Бренды:{' '}
                  {brands
                    .filter((brand) => selected.includes(brand.id))
                    .map((brand) => brand.name)
                    .join(', ')}
                </p>
                <p>Письмо автоматически не отправляется.</p>
                <div className="flex flex-wrap gap-2">
                  <button className={button} type="button" onClick={() => setPhase('form')}>
                    Назад
                  </button>
                  <button className={primary} type="button" onClick={() => void submit()}>
                    Подтвердить выпуск
                  </button>
                </div>
              </div>
            )}
            {visiblePhase === 'sending' && <p role="status">Создаём приглашение…</p>}
            {visiblePhase === 'issued' && link && (
              <div data-private-profile className="space-y-3">
                <p>Сохраните ссылку сейчас: повторно показать её нельзя.</p>
                <label htmlFor="invite-link" className="block font-semibold">
                  Ссылка приглашения
                </label>
                <textarea
                  ref={attachInput}
                  id="invite-link"
                  readOnly
                  value={link}
                  onFocus={(event) => event.currentTarget.select()}
                  rows={3}
                  className={`w-full resize-none rounded-lg border border-(--color-border) bg-(--color-surface-muted) p-3 break-all ${focus}`}
                />
                <button type="button" className={button} onClick={() => void copy()}>
                  <Copy size={16} aria-hidden="true" />
                  Копировать ссылку
                </button>
              </div>
            )}
            {visiblePhase === 'uncertain' && (
              <button
                type="button"
                className={button}
                onClick={() => {
                  uncertainAttempt.current = false;
                  setMessage('');
                  setPhase('form');
                }}
              >
                Понятно, создать ещё
              </button>
            )}
            {(message || !contextCurrent) && (
              <p role="status" className="break-words text-(--color-ink-muted)">
                {message || 'Контекст изменился. Закройте окно и проверьте доступ.'}
              </p>
            )}
          </div>
        </Dialog.Content>
      </Dialog.Portal>
    </Dialog.Root>
  );
}

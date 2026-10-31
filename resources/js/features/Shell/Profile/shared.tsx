import type { ReactNode } from 'react';
import { Head, Link } from '@inertiajs/react';
import { Bell, ShieldCheck, UserRound } from 'lucide-react';
import CabinetLayout from '@/layouts/CabinetLayout';
import { useProfileText } from './hooks';

export const panel =
  'min-w-0 rounded-2xl border border-(--color-border) bg-(--color-surface) p-5 shadow-(--shadow-card) sm:p-6';
export const focus =
  'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--color-focus)';
export function Errors({
  errors = {},
  transport = '',
}: {
  errors?: Record<string, string | undefined>;
  transport?: string;
}) {
  const messages = [...new Set([transport, ...Object.values(errors)].filter(Boolean))];
  return messages.length ? (
    <div
      role="alert"
      className="mb-4 rounded-xl bg-(--color-danger-soft) p-3 text-sm text-(--color-danger-ink)"
    >
      {messages.map((message) => (
        <p key={message}>{message}</p>
      ))}
    </div>
  ) : null;
}
export function Notice({ children }: { children?: ReactNode }) {
  return (
    <div
      role="status"
      className={
        children
          ? 'mb-4 rounded-xl bg-(--color-success-soft) p-3 text-sm text-(--color-success-ink)'
          : 'sr-only'
      }
    >
      {children}
    </div>
  );
}
export function ProfilePage({
  active,
  children,
  action,
}: {
  active: 'profile' | 'notifications' | 'security';
  children: ReactNode;
  action?: ReactNode;
}) {
  const t = useProfileText();
  const tabs = [
    { key: 'profile', href: '/profile', icon: UserRound },
    { key: 'notifications', href: '/profile/notifications', icon: Bell },
    { key: 'security', href: '/profile/security', icon: ShieldCheck },
  ] as const;
  return (
    <>
      <Head title={t(active)} />
      <CabinetLayout
        breadcrumbs={[{ label: t('settings'), href: '/profile' }, { label: t(active) }]}
      >
        <div className="mx-auto max-w-[1480px]">
          <header className="mb-6 flex flex-wrap items-start justify-between gap-4">
            <div>
              <h1 className="text-3xl font-bold leading-tight">{t(active)}</h1>
              <p className="mt-2 text-base text-(--color-ink-muted)">{t(`${active}_subtitle`)}</p>
            </div>
            {action}
          </header>
          <nav
            aria-label={t('settings')}
            className="mb-6 flex flex-wrap gap-1 border-b border-(--color-border)"
          >
            {tabs.map(({ key, href, icon: Icon }) => (
              <Link
                key={key}
                href={href}
                aria-current={active === key ? 'page' : undefined}
                className={`inline-flex min-h-11 cursor-pointer items-center gap-2 border-b-2 px-3 py-3 text-sm font-semibold ${active === key ? 'border-(--color-action) text-(--color-action)' : 'border-transparent text-(--color-link)'} ${focus}`}
              >
                <Icon size={18} aria-hidden="true" />
                {t(key)}
              </Link>
            ))}
          </nav>
          {children}
        </div>
      </CabinetLayout>
    </>
  );
}

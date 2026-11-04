import { useEffect, useState, type ReactNode } from 'react';
import { Link, usePage } from '@inertiajs/react';
import * as Dialog from '@radix-ui/react-dialog';
import { Bell, ChevronLeft, ChevronRight, Menu, X } from 'lucide-react';
import { Sidebar } from '@/components/navigation/Sidebar';
import { BrandSwitch } from '@/components/navigation/BrandSwitch';
import type { SharedPropsV1 } from '@/types/generated/data';

type PageProps = SharedPropsV1 & Record<string, unknown>;
type Crumb = { label: string; href?: string };
const button =
  'inline-flex min-h-11 min-w-11 cursor-pointer items-center justify-center rounded-lg text-(--color-link) hover:bg-(--color-surface-muted) focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--color-focus)';
function readCollapsed() {
  try {
    return localStorage.getItem('souzpir.sidebar.collapsed') === 'true';
  } catch {
    return false;
  }
}
function useNarrowDesktop() {
  const [narrow, setNarrow] = useState(
    () =>
      typeof window !== 'undefined' &&
      window.matchMedia('(min-width: 768px) and (max-width: 1279px)').matches,
  );
  useEffect(() => {
    const query = window.matchMedia('(min-width: 768px) and (max-width: 1279px)');
    const change = () => setNarrow(query.matches);
    change();
    query.addEventListener('change', change);
    return () => query.removeEventListener('change', change);
  }, []);
  return narrow;
}

export default function CabinetLayout({
  children,
  breadcrumbs = [],
  brandSwitchReady = false,
}: {
  children: ReactNode;
  breadcrumbs?: Crumb[];
  brandSwitchReady?: boolean;
}) {
  const { props } = usePage<PageProps>();
  const [collapsed, setCollapsed] = useState(readCollapsed);
  const [mobileOpen, setMobileOpen] = useState(false);
  const compact = useNarrowDesktop() || collapsed;
  const toggleCollapsed = () => {
    const next = !collapsed;
    setCollapsed(next);
    try {
      localStorage.setItem('souzpir.sidebar.collapsed', String(next));
    } catch {
      /* Storage may be disabled. */
    }
  };
  useEffect(() => {
    const root = document.documentElement;
    const previous = root.dataset.theme;
    root.dataset.theme = props.theme;
    return () => {
      if (previous === undefined) delete root.dataset.theme;
      else root.dataset.theme = previous;
    };
  }, [props.theme]);
  const canSeeNotifications = props.can?.['profile.own'] === true;
  const notifications = Object.values(props.ziggy?.routes ?? {}).some(
    (r) => r.uri === 'dashboard' && r.methods.includes('GET'),
  );
  return (
    <div className="min-h-dvh bg-(--color-bg) text-(--color-ink)">
      <div className="flex min-h-dvh">
        <aside
          className={`hidden shrink-0 border-r border-(--color-border) md:block ${compact ? 'w-[72px]' : 'w-[280px]'}`}
        >
          <div className="sticky top-0 flex h-dvh flex-col">
            <div className="min-h-0 flex-1">
              <Sidebar compact={compact} />
            </div>
            <div className="hidden border-t border-(--color-border) px-2 py-2 xl:block">
              <button
                type="button"
                className={button}
                onClick={toggleCollapsed}
                aria-label={collapsed ? 'Развернуть меню' : 'Свернуть меню'}
                title={collapsed ? 'Развернуть меню' : 'Свернуть меню'}
              >
                {collapsed ? <ChevronRight size={20} /> : <ChevronLeft size={20} />}
              </button>
            </div>
          </div>
        </aside>
        <div className="min-w-0 flex-1">
          <header className={`flex min-h-(--spacing-header) items-center gap-3 border-b border-(--color-border) px-4 md:px-6 ${props.brandAppearance ? 'bg-(--color-brand-primary-soft)' : 'bg-(--color-surface)'}`}>
            <Dialog.Root open={mobileOpen} onOpenChange={setMobileOpen}>
              <Dialog.Trigger asChild>
                <button type="button" aria-label="Открыть меню" className={`${button} md:hidden`}>
                  <Menu size={22} />
                </button>
              </Dialog.Trigger>
              <Dialog.Portal>
                <Dialog.Overlay className="fixed inset-0 z-40 bg-black/40" />
                <Dialog.Content className="fixed inset-y-0 left-0 z-50 w-[min(320px,90vw)] bg-(--color-sidebar) shadow-xl outline-none">
                  <Dialog.Title className="sr-only">Навигация кабинета</Dialog.Title>
                  <Dialog.Description className="sr-only">
                    Разделы и модули кабинета
                  </Dialog.Description>
                  <Dialog.Close asChild>
                    <button
                      type="button"
                      aria-label="Закрыть меню"
                      className={`${button} absolute top-3 right-3 z-10`}
                    >
                      <X size={20} />
                    </button>
                  </Dialog.Close>
                  <Sidebar onNavigate={() => setMobileOpen(false)} />
                </Dialog.Content>
              </Dialog.Portal>
            </Dialog.Root>
            <div className="min-w-0 flex-1" />
            <BrandSwitch brand={props.brand} brands={props.brands} ready={brandSwitchReady} />
            {canSeeNotifications &&
              (notifications ? (
                <Link
                  href="/dashboard#notifications"
                  aria-label={
                    props.unread === null ? 'Уведомления' : `Уведомления: ${props.unread}`
                  }
                  className={button}
                >
                  <Bell size={20} />
                  {props.unread !== null && props.unread > 0 && (
                    <span className="ml-1 text-sm">{props.unread}</span>
                  )}
                </Link>
              ) : (
                <span
                  title="Уведомления · Скоро"
                  className="inline-flex min-h-11 min-w-11 items-center justify-center text-(--color-ink-muted)"
                >
                  <Bell size={20} aria-hidden="true" />
                  <span className="sr-only">Уведомления пока недоступны</span>
                </span>
              ))}
            {props.auth.user && props.can?.['profile.own'] === true && <nav aria-label="Меню профиля"><Link href="/context/organization" className="cursor-pointer text-sm font-semibold text-(--color-link)">Выбор компании</Link></nav>}
            {props.auth.user && (
              <div className="flex items-center gap-3">
                <span
                  aria-hidden="true"
                  className="grid size-10 shrink-0 place-items-center rounded-full bg-(--color-nav-active) text-sm font-bold text-(--color-on-nav-active)"
                >
                  {props.auth.user.name
                    .trim()
                    .split(/\s+/)
                    .slice(0, 2)
                    .map((part) => part[0]?.toLocaleUpperCase('ru'))
                    .join('')}
                </span>
                <span className="hidden max-w-48 break-words text-sm font-semibold sm:inline">
                  {props.auth.user.name}
                </span>
              </div>
            )}
          </header>
          <main id="main-content" className="px-4 py-6 md:px-6">
            {breadcrumbs.length > 0 && (
              <nav aria-label="Навигационная цепочка" className="mb-5">
                <ol className="flex flex-wrap items-center gap-2 text-sm text-(--color-ink-muted)">
                  {breadcrumbs.map((crumb, index) => (
                    <li key={`${crumb.label}-${index}`} className="flex items-center gap-2">
                      {index > 0 && <span aria-hidden="true">/</span>}
                      {crumb.href && index < breadcrumbs.length - 1 ? (
                        <Link
                          className={`cursor-pointer text-(--color-link) ${button}`}
                          href={crumb.href}
                        >
                          {crumb.label}
                        </Link>
                      ) : (
                        <span aria-current={index === breadcrumbs.length - 1 ? 'page' : undefined}>
                          {crumb.label}
                        </span>
                      )}
                    </li>
                  ))}
                </ol>
              </nav>
            )}
            {children}
          </main>
        </div>
      </div>
    </div>
  );
}

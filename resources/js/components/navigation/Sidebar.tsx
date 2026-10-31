import { useId, useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import {
  Home,
  Eye,
  MessageCircle,
  ChartNoAxesCombined,
  Gauge,
  LayoutDashboard,
  MessagesSquare,
  Users,
  BookOpen,
  ClipboardList,
  UserRound,
  Star,
  Share2,
  Link2,
  Settings,
  Building2,
  CreditCard,
  CircleHelp,
  Bell,
  ChevronDown,
  type LucideIcon,
} from 'lucide-react';
import type { SharedPropsV1 } from '@/types/generated/data';
import menu from './navigation.json';

type Item = { id: string; label: string; icon: string; path: string; permission?: string };
type Props = SharedPropsV1 & Record<string, unknown>;
const focus =
  'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--color-focus)';
const icons: Record<string, LucideIcon> = {
  Home,
  Eye,
  MessageCircle,
  ChartNoAxesCombined,
  Gauge,
  LayoutDashboard,
  MessagesSquare,
  Users,
  BookOpen,
  ClipboardList,
  UserRound,
  Star,
  Share2,
  Link2,
  Settings,
  Building2,
  CreditCard,
  CircleHelp,
  Bell,
};

export function Sidebar({
  compact = false,
  onNavigate,
}: {
  compact?: boolean;
  onNavigate?: () => void;
}) {
  const { props, url } = usePage<Props>();
  const pathname = url.split('?')[0];
  const instance = useId();
  const candidates = [
    menu.home,
    ...menu.modules.flatMap((group) => group.children),
    ...menu.account,
  ];
  const current = candidates
    .filter(
      (item) =>
        pathname === item.path ||
        (item.path !== '/dashboard' && pathname.startsWith(`${item.path}/`)),
    )
    .sort((a, b) => b.path.length - a.path.length)[0]?.id;
  const [expanded, setExpanded] = useState<Record<string, { path: string; open: boolean }>>({});
  const [failedLogo, setFailedLogo] = useState<string | null>(null);
  const appearance = props.brandAppearance;
  const logo = appearance?.logo_url;
  const title = (id: string, fallback: string) =>
    props.translations?.[`navigation.${id}`] ?? fallback;
  const renderItem = (item: Item, child = false) => {
    if (item.permission && props.can?.[item.permission] !== true) return null;
    if (item.id === 'seller_digests' && props.modules?.seller !== true) return null;
    const Icon = icons[item.icon];
    const exists = Object.values(props.ziggy?.routes ?? {}).some(
      (route) =>
        route.uri.replace(/^\//, '') === item.path.replace(/^\//, '') &&
        route.methods.includes('GET'),
    );
    const active = current === item.id;
    const label = title(item.id, item.label);
    const content = (
      <>
        <Icon size={child ? 18 : 20} strokeWidth={1.8} aria-hidden="true" />
        <span className={compact ? 'sr-only' : 'min-w-0 flex-1'}>{label}</span>
        {!exists && !compact && (
          <span className="ml-auto text-[13px] text-(--color-ink-muted)">
            {title('soon', 'Скоро')}
          </span>
        )}
      </>
    );
    const cls = `flex min-h-11 items-center gap-3 rounded-lg px-3 text-sm ${compact ? 'justify-center' : ''} ${active && exists ? 'bg-(--color-nav-active) font-semibold text-(--color-on-nav-active)' : exists ? 'text-(--color-link) hover:bg-(--color-surface-muted)' : 'text-(--color-ink-muted)'}`;
    return exists ? (
      <Link
        key={item.id}
        href={item.path}
        onClick={onNavigate}
        aria-current={active ? 'page' : undefined}
        title={label}
        className={`${cls} cursor-pointer ${focus}`}
      >
        {content}
      </Link>
    ) : (
      <span
        key={item.id}
        aria-disabled="true"
        title={`${label} — ${title('soon', 'Скоро')}`}
        className={cls}
      >
        {content}
      </span>
    );
  };
  return (
    <div className="flex h-full min-h-0 flex-col bg-(--color-sidebar)">
      <div
        data-brand-header
        className={`flex min-h-(--spacing-header) items-center gap-3 px-4 py-3 ${appearance ? 'border-b-4 border-(--color-brand-accent) bg-(--color-brand-primary)' : 'border-b border-(--color-border)'} ${compact ? 'justify-center px-2' : ''}`}
      >
        {logo && failedLogo !== logo ? (
          <img src={logo} alt="" onError={() => setFailedLogo(logo)} className="size-10 shrink-0 rounded-xl object-contain" />
        ) : (
          <span
            aria-hidden="true"
            className="grid size-10 shrink-0 place-items-center rounded-xl bg-(--color-brand-primary-soft) text-lg font-bold text-(--color-brand-primary)"
          >
            {props.brand?.name?.slice(0, 1).toLocaleUpperCase('ru') ?? 'С'}
          </span>
        )}
        <div className={compact ? 'sr-only' : 'min-w-0'}>
          <div className={`break-words text-base font-bold ${appearance ? 'text-white' : 'text-(--color-ink)'}`}>
            {props.brand?.name ?? 'Бренд не выбран'}
          </div>
          {(appearance?.subtitle || props.organization?.name) && (
            <div className={`break-words text-sm ${appearance ? 'text-white' : 'text-(--color-ink-muted)'}`}>
              {appearance?.subtitle || props.organization?.name}
            </div>
          )}
        </div>
      </div>
      <nav
        aria-label="Основная навигация"
        className={`relative min-h-0 flex-1 overflow-y-auto py-4 ${compact ? 'px-2' : 'px-4'}`}
      >
        {renderItem(menu.home)}
        <p
          className={`pt-5 pb-2 text-sm font-medium text-(--color-ink-muted) ${compact ? 'sr-only' : 'px-3'}`}
        >
          {title('modules', 'Модули')}
        </p>
        {menu.modules.map((group) => {
          const Icon = icons[group.icon];
          const preference = expanded[group.id];
          const isOpen =
            preference?.path === pathname
              ? preference.open
              : group.children.some((item) => item.id === current);
          const moduleEnabled = props.modules?.[group.id as 'seller' | 'vision'];
          return (
            <div key={group.id}>
              <button
                type="button"
                title={group.label}
                aria-expanded={isOpen}
                aria-controls={`nav-${instance}-${group.id}`}
                onClick={() =>
                  setExpanded((value) => ({
                    ...value,
                    [group.id]: { path: pathname, open: !isOpen },
                  }))
                }
                className={`flex min-h-11 w-full cursor-pointer items-center gap-3 rounded-lg px-3 text-left text-sm text-(--color-link) hover:bg-(--color-surface-muted) ${focus} ${compact ? 'justify-center' : ''}`}
              >
                <Icon size={20} aria-hidden="true" />
                <span className={compact ? 'sr-only' : 'flex-1'}>
                  {title(group.id, group.label)}
                </span>
                {!compact && (
                  <ChevronDown
                    size={16}
                    className={isOpen ? 'rotate-180' : ''}
                    aria-hidden="true"
                  />
                )}
              </button>
              {isOpen && (
                <div
                  id={`nav-${instance}-${group.id}`}
                  className={compact ? '' : 'ml-3 border-l border-(--color-border) pl-2'}
                >
                  {moduleEnabled === false ? (
                    <span className="block px-3 py-2 text-sm text-(--color-ink-muted)">
                      {compact ? '' : 'Не подключён'}
                    </span>
                  ) : (
                    group.children.map((item) => renderItem(item, true))
                  )}
                </div>
              )}
            </div>
          );
        })}
        <p
          className={`pt-5 pb-2 text-sm font-medium text-(--color-ink-muted) ${compact ? 'sr-only' : 'px-3'}`}
        >
          {title('account', 'Кабинет')}
        </p>
        {menu.account.map((item) => renderItem(item))}
      </nav>
      {appearance?.promo_card && !compact && (
        <div className="m-4 mt-0 rounded-lg bg-(--color-brand-primary-soft) p-3 text-sm text-(--color-brand-primary)">
          <p className="font-semibold">Новости и возможности</p>
          <p className="mt-1">Информация появится позже</p>
        </div>
      )}
    </div>
  );
}

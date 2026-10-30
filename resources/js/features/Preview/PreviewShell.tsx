import { useEffect, useRef, useState, type ReactNode } from 'react';
import {
  Bell,
  BookOpen,
  Building2,
  ChartNoAxesCombined,
  ChevronDown,
  ChevronRight,
  CircleHelp,
  ClipboardList,
  CreditCard,
  Eye,
  Gauge,
  Home,
  LayoutDashboard,
  Link2,
  Menu,
  MessageCircle,
  Search,
  Settings,
  Share2,
  Star,
  UserRound,
  Users,
} from 'lucide-react';
import { BrandMark, Button, DemoDialog } from './ui';
import type { PreviewTheme } from './preview-state';

const accountLinks = [
  { label: 'Компания и бренды', icon: Building2 },
  { label: 'Команда', icon: Users },
  { label: 'Тариф и оплата', icon: CreditCard },
  { label: 'Интеграции', icon: Share2 },
  { label: 'Журнал', icon: ClipboardList },
  { label: 'Настройки', icon: Settings },
  { label: 'Помощь', icon: CircleHelp },
];

function Navigation({
  theme,
  mobile = false,
  onHome,
  onNavigate,
}: {
  theme: PreviewTheme;
  mobile?: boolean;
  onHome: () => void;
  onNavigate: (label: string) => void;
}) {
  const [expanded, setExpanded] = useState('');
  const labelClass = mobile ? '' : 'md:hidden lg:inline';
  return (
    <div>
      <div
        className={`flex h-(--spacing-header) items-center border-b border-(--color-border) px-4 ${mobile ? '' : 'md:justify-center md:px-2 lg:justify-start lg:px-4'}`}
      >
        <span className={mobile ? '' : 'md:hidden lg:block'}>
          <BrandMark theme={theme} />
        </span>
        {!mobile && (
          <span className="hidden md:block lg:hidden">
            <BrandMark theme={theme} compact />
          </span>
        )}
      </div>
      <nav
        aria-label={mobile ? 'Мобильная навигация' : 'Основная навигация'}
        className="space-y-1 p-4 md:px-2 lg:px-4"
      >
        <button
          type="button"
          onClick={onHome}
          aria-current="page"
          title="Главная"
          className="flex min-h-11 w-full items-center gap-3 rounded-(--radius-control) bg-(--color-nav-active) px-3 text-left text-[15px] font-medium text-(--color-on-nav-active) shadow-sm"
        >
          <Home size={21} strokeWidth={1.75} className="shrink-0" />
          <span className={labelClass}>Главная</span>
          <span
            className={`ml-auto grid size-6 place-items-center rounded-full bg-(--color-brand-accent) text-[13px] font-bold text-(--color-on-brand-accent) ${mobile ? '' : 'md:hidden lg:grid'}`}
          >
            2
          </span>
        </button>
        <p
          className={`px-3 pt-6 pb-2 text-sm font-medium text-(--color-ink-muted) ${mobile ? '' : 'md:sr-only lg:not-sr-only'}`}
        >
          Модули
        </p>
        {[
          {
            label: 'Видение',
            icon: Eye,
            children: [
              { label: 'Обзор видимости', icon: ChartNoAxesCombined },
              { label: 'Замеры', icon: Gauge },
            ],
          },
          {
            label: 'Продавец',
            icon: MessageCircle,
            children: [
              { label: 'Сводка', icon: LayoutDashboard },
              { label: 'Диалоги', icon: Users },
              { label: 'Клиенты', icon: Users },
              { label: 'База знаний', icon: BookOpen },
              { label: 'Сценарий', icon: ClipboardList },
              { label: 'Персонаж', icon: UserRound },
              { label: 'Качество', icon: Star },
              { label: 'Аналитика', icon: ChartNoAxesCombined },
              { label: 'Каналы', icon: Share2 },
              { label: 'Интеграции', icon: Link2 },
              { label: 'Настройки', icon: Settings },
            ],
          },
        ].map(({ label, icon: Icon, children }) => (
          <div key={label}>
            <button
              type="button"
              title={label}
              onClick={() => setExpanded(expanded === label ? '' : label)}
              aria-expanded={expanded === label}
              className="flex min-h-11 w-full items-center gap-3 rounded-(--radius-control) px-3 text-left text-[15px] font-medium text-(--color-link) transition-colors hover:bg-(--color-surface-muted) focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--color-focus) motion-reduce:transition-none"
            >
              <Icon size={21} strokeWidth={1.75} className="shrink-0" />
              <span className={labelClass}>{label}</span>
              <ChevronRight
                size={16}
                aria-hidden="true"
                className={`ml-auto transition-transform motion-reduce:transition-none ${expanded === label ? 'rotate-90' : ''} ${mobile ? '' : 'md:hidden lg:block'}`}
              />
            </button>
            {expanded === label && (
              <div
                className={`mb-2 ml-[22px] border-l border-(--color-border) pl-2 ${mobile ? '' : 'md:ml-0 md:border-l-0 md:pl-0 lg:ml-[22px] lg:border-l lg:pl-2'}`}
              >
                {children.map(({ label: child, icon: ChildIcon }) => (
                  <button
                    type="button"
                    key={child}
                    title={child}
                    aria-label={child}
                    onClick={() => onNavigate(child)}
                    className={`flex min-h-9 w-full items-center gap-3 rounded-lg px-4 py-1.5 text-left text-sm leading-5 text-(--color-link) transition-colors hover:bg-(--color-surface-muted) focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--color-focus) motion-reduce:transition-none ${mobile ? '' : 'md:justify-center md:px-2 lg:justify-start lg:px-4'}`}
                  >
                    <ChildIcon
                      size={17}
                      strokeWidth={1.75}
                      aria-hidden="true"
                      className="shrink-0 text-(--color-icon)"
                    />
                    <span className={mobile ? '' : 'md:sr-only lg:not-sr-only'}>{child}</span>
                  </button>
                ))}
              </div>
            )}
          </div>
        ))}
        <p
          className={`px-3 pt-6 pb-2 text-sm font-medium text-(--color-ink-muted) ${mobile ? '' : 'md:sr-only lg:not-sr-only'}`}
        >
          Кабинет
        </p>
        {accountLinks.map(({ label, icon: Icon }) => (
          <button
            type="button"
            key={label}
            title={label}
            onClick={() => onNavigate(label)}
            className="flex min-h-11 w-full items-center gap-3 rounded-(--radius-control) px-3 text-left text-[15px] text-(--color-link) transition-colors hover:bg-(--color-surface-muted) focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--color-focus) motion-reduce:transition-none"
          >
            <Icon size={21} strokeWidth={1.75} className="shrink-0" />
            <span className={labelClass}>{label}</span>
          </button>
        ))}
      </nav>
    </div>
  );
}

export default function PreviewShell({
  children,
  theme,
  onSearch,
  searchValue,
  onHome,
  onNotice,
}: {
  children: ReactNode;
  theme: PreviewTheme;
  onSearch: (value: string) => void;
  searchValue: string;
  onHome: () => void;
  onNotice: (message: string) => void;
}) {
  const [navigationOpen, setNavigationOpen] = useState(false);
  const [dialog, setDialog] = useState('');
  const [mode, setMode] = useState('Автономно');
  const [modeOpen, setModeOpen] = useState(false);
  const [profileOpen, setProfileOpen] = useState(false);
  const modeTrigger = useRef<HTMLButtonElement>(null);
  const profileTrigger = useRef<HTMLButtonElement>(null);
  useEffect(() => {
    const escape = (event: KeyboardEvent) => {
      if (event.key !== 'Escape') return;
      if (modeOpen) {
        setModeOpen(false);
        modeTrigger.current?.focus();
      }
      if (profileOpen) {
        setProfileOpen(false);
        profileTrigger.current?.focus();
      }
    };
    const outside = (event: MouseEvent) => {
      if (event.target instanceof Element && !event.target.closest('[data-header-popup]')) {
        setModeOpen(false);
        setProfileOpen(false);
      }
    };
    document.addEventListener('keydown', escape);
    document.addEventListener('click', outside);
    return () => {
      document.removeEventListener('keydown', escape);
      document.removeEventListener('click', outside);
    };
  }, [modeOpen, profileOpen]);
  const navigate = (label: string) => {
    setNavigationOpen(false);
    setDialog(label);
  };
  const home = () => {
    setNavigationOpen(false);
    onHome();
  };
  const submitSearch = () => {
    onSearch(searchValue);
    setDialog('');
    onNotice('Поиск применён к демонстрационным событиям этого экрана.');
  };
  return (
    <div className="grid flex-1 grid-cols-1 md:grid-cols-[72px_minmax(0,1fr)] lg:grid-cols-[var(--spacing-sidebar)_minmax(0,1fr)]">
      <aside className="hidden min-h-full border-r border-(--color-border) bg-(--color-sidebar) md:block">
        <div className="sticky top-0">
          <Navigation theme={theme} onHome={home} onNavigate={navigate} />
        </div>
      </aside>
      <div className="min-w-0">
        <header className="sticky top-0 z-20 flex h-(--spacing-header) items-center gap-3 border-b border-(--color-border) bg-(--color-surface) px-4 shadow-[0_2px_8px_#10264a06] lg:px-6">
          <button
            type="button"
            aria-label="Открыть навигацию"
            onClick={() => setNavigationOpen(true)}
            className="rounded-lg p-2 text-(--color-icon) md:hidden"
          >
            <Menu size={23} />
          </button>
          <span className="min-w-0 flex-1 truncate text-sm font-bold md:hidden">
            {theme === 'pyro'
              ? 'Пиротехника'
              : theme === 'confectionery'
                ? 'Кондитерская'
                : 'eyon.'}
          </span>
          <form
            onSubmit={(event) => {
              event.preventDefault();
              submitSearch();
            }}
            role="search"
            className="hidden min-w-0 flex-1 items-center gap-3 rounded-(--radius-control) bg-(--color-bg) px-4 md:flex"
          >
            <Search size={20} className="shrink-0 text-(--color-icon)" />
            <input
              type="search"
              aria-label="Глобальный поиск по демоданным"
              value={searchValue}
              onChange={(event) => onSearch(event.target.value)}
              placeholder="Поиск по клиентам, диалогам, знаниям..."
              className="h-10 w-full min-w-0 border-0 bg-transparent text-sm outline-none placeholder:text-(--color-ink-muted)"
            />
          </form>
          <button
            type="button"
            aria-label="Открыть поиск"
            onClick={() => setDialog('Поиск')}
            className="rounded-lg p-2 text-(--color-icon) md:hidden"
          >
            <Search size={22} />
          </button>
          <div data-header-popup className="relative hidden shrink-0 lg:block">
            <button
              type="button"
              aria-expanded={modeOpen}
              ref={modeTrigger}
              onClick={() => {
                setModeOpen(!modeOpen);
                setProfileOpen(false);
              }}
              className="flex h-10 items-center gap-2 rounded-(--radius-control) bg-(--color-bg) px-3 text-sm"
            >
              <Building2 size={19} className="text-(--color-icon)" />
              <span className="h-2 w-2 rounded-full bg-(--color-action)" />
              <span className="text-(--color-action)">Сайт · {mode}</span>
              <ChevronDown size={16} className="text-(--color-icon)" />
            </button>
            {modeOpen && (
              <div className="absolute right-0 top-12 z-30 w-64 rounded-xl border border-(--color-border) bg-(--color-surface) p-2 shadow-(--shadow-card)">
                <p className="px-2 py-1 text-sm leading-5 text-(--color-ink-muted)">
                  Только демонстрация режима
                </p>
                {['Автономно', 'С подсказками'].map((value) => (
                  <button
                    type="button"
                    key={value}
                    onClick={() => {
                      setMode(value);
                      setModeOpen(false);
                      modeTrigger.current?.focus();
                      onNotice('Режим изменён только в макете. ИИ-продавец не запускается.');
                    }}
                    className="block w-full rounded-lg px-2 py-2 text-left text-sm hover:bg-(--color-surface-muted)"
                  >
                    {value}
                  </button>
                ))}
                <button
                  type="button"
                  className="min-h-9 rounded-lg px-2 py-1 text-sm text-(--color-link) hover:bg-(--color-surface-muted)"
                  onClick={() => {
                    setModeOpen(false);
                    modeTrigger.current?.focus();
                  }}
                >
                  Закрыть
                </button>
              </div>
            )}
          </div>
          <button
            type="button"
            aria-label="Демонстрационные уведомления"
            onClick={() => setDialog('Уведомления')}
            className="relative rounded-lg p-2 text-(--color-ink)"
          >
            <Bell size={23} strokeWidth={1.75} />
            <span className="absolute right-2 top-1 h-2 w-2 rounded-full border border-white bg-(--color-danger)" />
          </button>
          <div data-header-popup className="relative shrink-0">
            <button
              type="button"
              aria-label="Меню пользователя Иван Петров"
              aria-expanded={profileOpen}
              ref={profileTrigger}
              onClick={() => {
                setProfileOpen(!profileOpen);
                setModeOpen(false);
              }}
              className="flex items-center gap-3 rounded-lg"
            >
              <span className="grid h-10 w-10 place-items-center rounded-full bg-(--color-action) text-sm font-semibold text-white">
                ИП
              </span>
              <span className="hidden text-left text-sm leading-5 xl:block">
                <span className="block font-semibold">Иван Петров</span>
                <span className="mt-1 block text-(--color-ink-muted)">Владелец</span>
              </span>
              <ChevronDown size={16} className="hidden text-(--color-icon) xl:block" />
            </button>
            {profileOpen && (
              <div className="absolute right-0 top-12 z-30 w-60 rounded-xl border border-(--color-border) bg-(--color-surface) p-3 shadow-(--shadow-card)">
                <p className="text-sm font-semibold">Иван Петров · демопрофиль</p>
                <p className="mt-1 text-sm leading-5 text-(--color-ink-muted)">
                  Вход в аккаунт не выполнен.
                </p>
                <a
                  href={`/preview/login?theme=${theme}`}
                  className="mt-3 block text-sm text-(--color-link)"
                >
                  Открыть макет входа
                </a>
                <button
                  type="button"
                  onClick={() => {
                    setProfileOpen(false);
                    profileTrigger.current?.focus();
                  }}
                  className="mt-3 min-h-9 rounded-lg text-sm text-(--color-link) hover:underline"
                >
                  Закрыть меню
                </button>
              </div>
            )}
          </div>
        </header>
        {children}
      </div>
      <DemoDialog
        open={navigationOpen}
        title="Навигация"
        onClose={() => setNavigationOpen(false)}
        drawer
        side="left"
      >
        <Navigation theme={theme} mobile onHome={home} onNavigate={navigate} />
      </DemoDialog>
      <DemoDialog
        open={Boolean(dialog)}
        title={dialog === 'Поиск' ? 'Поиск по демоданным' : dialog}
        onClose={() => setDialog('')}
      >
        {dialog === 'Поиск' ? (
          <form
            onSubmit={(event) => {
              event.preventDefault();
              submitSearch();
            }}
          >
            <label htmlFor="mobile-global-search" className="mb-2 block text-sm font-semibold">
              Найти событие
            </label>
            <input
              id="mobile-global-search"
              type="search"
              value={searchValue}
              onChange={(event) => onSearch(event.target.value)}
              className="h-11 w-full rounded-lg border border-(--color-control-border) px-3"
            />
            <Button type="submit" className="mt-4">
              Найти
            </Button>
          </form>
        ) : (
          <>
            <p className="text-sm leading-6 text-(--color-ink-muted)">
              {dialog === 'Уведомления'
                ? 'Здесь появится центр уведомлений. События на текущем экране — демонстрационные; отправки и уведомления не выполняются.'
                : `Раздел «${dialog}» будет реализован на следующем этапе. Сейчас доступна визуальная демонстрация входа и списка событий.`}
            </p>
            <Button variant="secondary" className="mt-5" onClick={() => setDialog('')}>
              Понятно
            </Button>
          </>
        )}
      </DemoDialog>
    </div>
  );
}

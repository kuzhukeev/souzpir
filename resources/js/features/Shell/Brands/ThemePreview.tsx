import { Bell, Building2, Eye, Home, MessageCircle, Search } from 'lucide-react';
import type { BrandTheme } from '@/types/Shell/Brands';

export function ThemeSwatch({ theme }: { theme: BrandTheme }) {
  return (
    <div
      data-theme={theme}
      aria-hidden="true"
      className="mt-3 grid h-24 grid-cols-[35%_1fr] overflow-hidden rounded-lg border border-(--color-border) bg-(--color-bg)"
    >
      <div className="space-y-2 bg-(--color-brand-primary-soft) p-2">
        <span className="block h-5 rounded bg-(--color-brand-primary)" />
        <span className="block h-3 rounded bg-(--color-brand-accent)" />
        <span className="block h-2 w-4/5 rounded bg-(--color-control-border)" />
        <span className="block h-2 w-3/5 rounded bg-(--color-control-border)" />
      </div>
      <div className="space-y-2 p-2">
        <span className="block h-4 rounded bg-(--color-surface)" />
        <span className="block h-8 rounded border border-(--color-border) bg-(--color-surface)" />
        <span className="block h-3 w-1/2 rounded bg-(--color-brand-accent)" />
      </div>
    </div>
  );
}

export function ThemePreview({
  theme,
  name,
  subtitle,
  promo,
  logo,
}: {
  theme: BrandTheme;
  name: string;
  subtitle: string;
  promo: boolean;
  logo: string | null;
}) {
  return (
    <section
      data-theme={theme}
      aria-label="Предпросмотр сайдбара и шапки"
      className="grid min-w-0 grid-cols-[minmax(0,1fr)_minmax(0,1.3fr)] overflow-hidden rounded-xl border border-(--color-border) bg-(--color-bg) text-sm text-(--color-ink)"
    >
      <aside className="flex min-w-0 flex-col border-r border-(--color-border) bg-(--color-sidebar)">
        <div
          data-brand-preview-header
          className="border-b-4 border-(--color-brand-accent) bg-(--color-brand-primary) p-3 text-white"
        >
          {logo ? (
            <img src={logo} alt="" className="mb-2 size-9 rounded-lg object-contain" />
          ) : (
            <span
              aria-hidden="true"
              className="mb-2 grid size-9 place-items-center rounded-lg bg-(--color-brand-primary-soft) font-bold text-(--color-brand-primary)"
            >
              {name.slice(0, 1).toLocaleUpperCase('ru')}
            </span>
          )}
          <p className="break-words font-semibold">{name}</p>
          {subtitle && <p className="mt-1 break-words text-white">{subtitle}</p>}
        </div>
        <div className="space-y-2 p-2">
          <p className="flex items-center gap-2 rounded-lg bg-(--color-nav-active) px-2 py-2 font-semibold text-(--color-on-nav-active)">
            <Home size={17} className="shrink-0" aria-hidden="true" /> Главная
          </p>
          <p className="flex items-center gap-2 px-2 py-2 text-(--color-link)">
            <Eye size={17} className="shrink-0" aria-hidden="true" /> Видение
          </p>
          <p className="flex items-center gap-2 px-2 py-2 text-(--color-link)">
            <MessageCircle size={17} className="shrink-0" aria-hidden="true" /> Продавец
          </p>
        </div>
        {promo && (
          <div className="m-2 mt-auto rounded-lg bg-(--color-brand-primary-soft) p-3 text-(--color-brand-primary)">
            <p className="font-semibold">Промо-карта</p>
            <p className="mt-1">Новости и возможности</p>
          </div>
        )}
      </aside>
      <div className="min-w-0">
        <div className="flex min-h-14 items-center justify-between gap-2 border-b border-(--color-border) bg-(--color-brand-primary-soft) px-3 text-(--color-brand-primary)">
          <Search size={18} aria-hidden="true" />
          <span className="flex items-center gap-2">
            <Building2 size={18} aria-hidden="true" />
            <Bell size={18} aria-hidden="true" />
          </span>
        </div>
        <div className="space-y-3 p-3">
          <p className="break-words font-semibold">Рабочий кабинет</p>
          <div className="rounded-lg border border-(--color-border) bg-(--color-surface) p-3">
            <p className="font-semibold">Обзор бренда</p>
            <div aria-hidden="true" className="mt-3 space-y-2">
              <span className="block h-2 rounded bg-(--color-control-border)" />
              <span className="block h-2 w-2/3 rounded bg-(--color-control-border)" />
            </div>
          </div>
          <span className="inline-block rounded-lg bg-(--color-action) px-3 py-2 font-semibold text-(--color-on-action)">
            Оформление
          </span>
        </div>
      </div>
    </section>
  );
}

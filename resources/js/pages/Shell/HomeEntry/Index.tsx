import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowRight, Building2, ClipboardList, UserRound, Users } from 'lucide-react';
import CabinetLayout from '@/layouts/CabinetLayout';
import type { SharedPropsV1 } from '@/types/generated/data';

type Destination = {
  id: 'company' | 'team' | 'journal' | 'profile';
  label: string;
  description: string;
  href: string;
};
const icons = { company: Building2, team: Users, journal: ClipboardList, profile: UserRound };

export default function Index({ homeLinks }: { homeLinks: Destination[] }) {
  const { brand } = usePage<SharedPropsV1 & Record<string, unknown>>().props;
  return (
    <CabinetLayout brandSwitchReady>
      <Head title="Главная" />
      <div className="mx-auto max-w-[1500px]">
        <header className="mb-6">
          <h1 className="text-3xl font-bold leading-tight">Главная</h1>
          <p className="mt-2 break-words text-base text-(--color-ink-muted)">
            {brand?.name ?? 'Бренд не выбран'}
          </p>
        </header>
        <div className="grid gap-4 lg:grid-cols-[minmax(0,2fr)_minmax(280px,1fr)]">
          <section
            aria-labelledby="home-sections"
            className="rounded-2xl border border-(--color-border) bg-(--color-surface) p-5 shadow-(--shadow-card) sm:p-6"
          >
            <h2 id="home-sections" className="text-xl font-bold">
              Разделы кабинета
            </h2>
            <p className="mt-2 text-sm text-(--color-ink-muted)">
              Переходы доступны в соответствии с вашей ролью и выбранным брендом.
            </p>
            {homeLinks.length ? (
              <ul className="mt-5 grid gap-3 sm:grid-cols-2">
                {homeLinks.map((item) => {
                  const Icon = icons[item.id];
                  return (
                    <li key={item.id}>
                      <Link
                        href={item.href}
                        className="flex h-full min-h-24 cursor-pointer items-start gap-3 rounded-xl border border-(--color-border) p-4 hover:bg-(--color-surface-muted) focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--color-focus)"
                      >
                        <span className="grid size-10 shrink-0 place-items-center rounded-full bg-(--color-success-soft) text-(--color-success-ink)">
                          <Icon size={20} aria-hidden="true" />
                        </span>
                        <span className="min-w-0 flex-1">
                          <span className="block break-words font-semibold">{item.label}</span>
                          <span className="mt-1 block break-words text-sm text-(--color-ink-muted)">
                            {item.description}
                          </span>
                        </span>
                        <ArrowRight
                          size={18}
                          className="mt-1 shrink-0 text-(--color-link)"
                          aria-hidden="true"
                        />
                      </Link>
                    </li>
                  );
                })}
              </ul>
            ) : (
              <p className="mt-5 rounded-xl bg-(--color-surface-muted) p-4 text-sm">
                Доступных разделов пока нет.
              </p>
            )}
          </section>
          <section
            aria-labelledby="home-summary"
            className="rounded-2xl border border-(--color-border) bg-(--color-surface) p-5 shadow-(--shadow-card) sm:p-6"
          >
            <h2 id="home-summary" className="text-xl font-bold">
              Сводка
            </h2>
            <p className="mt-3 text-sm leading-6 text-(--color-ink-muted)">
              Показатели для главной страницы пока недоступны.
            </p>
          </section>
        </div>
      </div>
    </CabinetLayout>
  );
}

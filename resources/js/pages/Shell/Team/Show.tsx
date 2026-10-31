import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, UserRound } from 'lucide-react';
import CabinetLayout from '@/layouts/CabinetLayout';
import type { TeamMemberDetail } from '@/types/Shell/Team/members';

const panel =
  'rounded-2xl border border-(--color-border) bg-(--color-surface) p-4 shadow-(--shadow-card) sm:p-6';

export default function Show({ member }: { member: TeamMemberDetail }) {
  const name = member.name?.trim() || 'Имя недоступно';
  const status =
    member.status === 'active'
      ? 'Активен'
      : member.status === 'disabled'
        ? 'Отключён'
        : 'Статус недоступен';
  return (
    <CabinetLayout
      breadcrumbs={[
        { label: 'Главная', href: '/dashboard' },
        { label: 'Команда', href: '/team' },
        { label: name },
      ]}
    >
      <Head title={`${name} — Команда`} />
      <div className="mx-auto max-w-[1500px]">
        <Link
          href="/team"
          className="mb-5 inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-lg text-sm font-semibold text-(--color-link) focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--color-focus)"
        >
          <ArrowLeft size={18} aria-hidden="true" /> К участникам
        </Link>
        <header className="mb-6 flex flex-wrap items-center gap-4">
          <span
            aria-hidden="true"
            className="grid size-16 shrink-0 place-items-center rounded-full bg-(--color-info-soft) text-(--color-info-ink)"
          >
            <UserRound size={30} />
          </span>
          <div className="min-w-0">
            <h1 className="break-words text-3xl font-bold leading-tight">{name}</h1>
            <p className="mt-2 break-all text-sm text-(--color-ink-muted)">
              {member.email || 'Адрес недоступен'}
            </p>
          </div>
          <span
            className={`rounded-full px-3 py-1 text-sm font-medium ${member.status === 'active' ? 'bg-(--color-success-soft) text-(--color-success-ink)' : 'bg-(--color-surface-muted) text-(--color-ink-muted)'}`}
          >
            {status}
          </span>
        </header>
        <div className="grid gap-4 lg:grid-cols-2">
          <section className={panel} aria-labelledby="access-title">
            <h2 id="access-title" className="text-xl font-bold">
              Роль и доступ
            </h2>
            <dl className="mt-5 space-y-5 text-sm">
              <div>
                <dt className="text-(--color-ink-muted)">Роль</dt>
                <dd className="mt-1 font-semibold">{member.role_label || 'Роль не определена'}</dd>
              </div>
              <div>
                <dt className="text-(--color-ink-muted)">Доступные бренды</dt>
                <dd className="mt-2">
                  {member.brands.length ? (
                    <ul className="flex flex-wrap gap-2">
                      {member.brands.map((brand) => (
                        <li
                          key={brand.id}
                          className="max-w-full break-words rounded-full bg-(--color-success-soft) px-3 py-1 text-(--color-success-ink)"
                        >
                          {brand.name}
                        </li>
                      ))}
                    </ul>
                  ) : (
                    <span>Нет общих доступных брендов</span>
                  )}
                </dd>
              </div>
            </dl>
            <p className="mt-5 text-sm text-(--color-ink-muted)">
              Показаны только бренды, доступные вам. Изменение роли и доступа пока недоступно.
            </p>
          </section>
          <section className={panel} aria-labelledby="contacts-title">
            <h2 id="contacts-title" className="text-xl font-bold">
              Контакты
            </h2>
            <dl className="mt-5 space-y-5 text-sm">
              <div>
                <dt className="text-(--color-ink-muted)">Почта</dt>
                <dd className="mt-1 break-all">{member.email || 'Адрес недоступен'}</dd>
              </div>
              <div>
                <dt className="text-(--color-ink-muted)">Телефон</dt>
                <dd className="mt-1 break-words">{member.phone?.trim() || 'Не указан'}</dd>
              </div>
            </dl>
          </section>
        </div>
      </div>
    </CabinetLayout>
  );
}

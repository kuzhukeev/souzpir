import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { Users } from 'lucide-react';
import CabinetLayout from '@/layouts/CabinetLayout';
import type { TeamInvitations, TeamMember, TeamPage } from '@/types/Shell/Team/members';

const panel =
  'rounded-2xl border border-(--color-border) bg-(--color-surface) p-4 shadow-(--shadow-card) sm:p-6';
const focus =
  'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--color-focus)';
const button = `inline-flex min-h-11 cursor-pointer items-center justify-center rounded-lg border border-(--color-border) px-4 text-sm font-semibold text-(--color-link) disabled:cursor-not-allowed disabled:opacity-50 ${focus}`;

function Person({ member }: { member: TeamMember }) {
  const name = member.name?.trim() || 'Имя недоступно';
  const initials =
    member.name
      ?.trim()
      .split(/\s+/u)
      .slice(0, 2)
      .map((part) => part[0])
      .join('')
      .toLocaleUpperCase('ru') || '—';
  return (
    <Link
      href={`/team/${encodeURIComponent(member.id)}`}
      aria-label={`Открыть карточку: ${name}`}
      className={`flex min-w-0 cursor-pointer items-center gap-3 rounded-lg ${focus}`}
    >
      <span
        aria-hidden="true"
        className="grid size-10 shrink-0 place-items-center rounded-full bg-(--color-info-soft) text-sm font-bold text-(--color-info-ink)"
      >
        {initials}
      </span>
      <div className="min-w-0">
        <p className="break-words font-semibold">{name}</p>
        <p className="break-all text-sm text-(--color-ink-muted)">
          {member.email || 'Адрес недоступен'}
        </p>
      </div>
    </Link>
  );
}

function Brands({ member }: { member: TeamMember }) {
  return member.brands.length ? (
    <ul className="flex flex-wrap gap-2">
      {member.brands.map((brand) => (
        <li
          key={brand.id}
          className="max-w-full break-words rounded-full bg-(--color-success-soft) px-3 py-1 text-sm text-(--color-success-ink)"
        >
          {brand.name}
        </li>
      ))}
    </ul>
  ) : (
    <span className="text-sm text-(--color-ink-muted)">Нет общих доступных брендов</span>
  );
}

function Status({ value }: { value: string }) {
  const text =
    value === 'active' ? 'Активен' : value === 'disabled' ? 'Отключён' : 'Статус недоступен';
  const color =
    value === 'active'
      ? 'bg-(--color-success-soft) text-(--color-success-ink)'
      : 'bg-(--color-surface-muted) text-(--color-ink-muted)';
  return (
    <span className={`inline-flex rounded-full px-3 py-1 text-sm font-medium ${color}`}>
      {text}
    </span>
  );
}

export default function Index({
  members,
  canViewRoles,
  invitations,
}: {
  members: TeamPage;
  canViewRoles: boolean;
  invitations: TeamInvitations;
}) {
  const [loading, setLoading] = useState(false);
  const [failedPage, setFailedPage] = useState<number | null>(null);
  const [invitationLoading, setInvitationLoading] = useState(false);
  const [failedInvitationPage, setFailedInvitationPage] = useState<number | null>(null);
  const go = (page: number) => {
    setLoading(true);
    setFailedPage(null);
    const failed = () => {
      setFailedPage(page);
      return false;
    };
    router.get(
      '/team',
      { page, invitation_page: invitations.current_page },
      {
        preserveState: true,
        preserveScroll: true,
        onError: failed,
        onNetworkError: failed,
        onHttpException: failed,
        onFinish: () => setLoading(false),
      },
    );
  };
  const goInvitations = (page: number) => {
    setInvitationLoading(true);
    setFailedInvitationPage(null);
    const failed = () => {
      setFailedInvitationPage(page);
      return false;
    };
    router.get(
      '/team',
      { page: members.current_page, invitation_page: page },
      {
        preserveState: true,
        preserveScroll: true,
        onError: failed,
        onNetworkError: failed,
        onHttpException: failed,
        onFinish: () => setInvitationLoading(false),
      },
    );
  };
  return (
    <CabinetLayout breadcrumbs={[{ label: 'Главная', href: '/dashboard' }, { label: 'Команда' }]}>
      <Head title="Команда" />
      <div className="mx-auto max-w-[1500px]">
        <header className="mb-6 flex flex-wrap items-end justify-between gap-4">
          <div>
            <h1 className="text-3xl font-bold leading-tight">Команда</h1>
            <p className="mt-2 text-base text-(--color-ink-muted)">
              Участники компании и доступные вам бренды
            </p>
          </div>
          {canViewRoles && (
            <Link href="/team/roles" className={`${button} cursor-pointer`}>
              Роли и доступ
            </Link>
          )}
        </header>
        <section className={panel} aria-labelledby="team-title" aria-busy={loading}>
          <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
            <div>
              <h2 id="team-title" className="text-xl font-bold">
                Участники
              </h2>
              <p className="mt-1 text-sm text-(--color-ink-muted)">
                Показаны только бренды, доступные вам.
              </p>
            </div>
            <span className="text-sm text-(--color-ink-muted)">
              Всего: {new Intl.NumberFormat('ru-RU').format(members.total)}
            </span>
          </div>
          {failedPage !== null && (
            <div
              role="alert"
              className="mb-4 rounded-xl bg-(--color-danger-soft) p-4 text-sm text-(--color-danger-ink)"
            >
              <p>Не удалось загрузить страницу участников.</p>
              <button
                type="button"
                onClick={() => go(failedPage)}
                className={`mt-2 cursor-pointer font-semibold underline ${focus}`}
              >
                Повторить
              </button>
            </div>
          )}
          {loading && (
            <p role="status" className="mb-4 text-sm text-(--color-ink-muted)">
              Загружаем участников…
            </p>
          )}
          {members.data.length === 0 ? (
            <div
              role="status"
              className="rounded-xl bg-(--color-surface-muted) px-4 py-8 text-center"
            >
              <Users
                size={25}
                aria-hidden="true"
                className="mx-auto mb-3 text-(--color-ink-muted)"
              />
              <p className="font-semibold">
                {members.total === 0 ? 'Участников пока нет' : 'На этой странице нет участников'}
              </p>
              <p className="mt-2 text-sm text-(--color-ink-muted)">
                {members.total === 0
                  ? 'Данные появятся после добавления участников компании.'
                  : 'Вернитесь на предыдущую страницу.'}
              </p>
            </div>
          ) : (
            <>
              <div
                className="hidden overflow-x-auto md:block"
                role="region"
                aria-label="Таблица участников"
                tabIndex={0}
              >
                <table className="w-full min-w-[680px] border-collapse text-left text-sm">
                  <thead className="bg-(--color-surface-muted)">
                    <tr>
                      <th scope="col" className="rounded-l-lg px-4 py-3">
                        Участник
                      </th>
                      <th scope="col" className="px-4 py-3">
                        Роль
                      </th>
                      <th scope="col" className="px-4 py-3">
                        Доступные бренды
                      </th>
                      <th scope="col" className="px-4 py-3">
                        Статус
                      </th>
                    </tr>
                  </thead>
                  <tbody>
                    {members.data.map((member) => (
                      <tr key={member.id} className="border-b border-(--color-border)">
                        <th scope="row" className="px-4 py-4 text-left">
                          <Person member={member} />
                        </th>
                        <td className="px-4 py-4">{member.role_label || 'Роль не определена'}</td>
                        <td className="px-4 py-4">
                          <Brands member={member} />
                        </td>
                        <td className="px-4 py-4">
                          <Status value={member.status} />
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              <div className="grid gap-3 md:hidden">
                {members.data.map((member) => (
                  <article
                    key={member.id}
                    className="rounded-xl border border-(--color-border) p-4"
                  >
                    <Person member={member} />
                    <dl className="mt-4 space-y-3 text-sm">
                      <div>
                        <dt className="text-(--color-ink-muted)">Роль</dt>
                        <dd className="mt-1">{member.role_label || 'Роль не определена'}</dd>
                      </div>
                      <div>
                        <dt className="text-(--color-ink-muted)">Доступные бренды</dt>
                        <dd className="mt-1">
                          <Brands member={member} />
                        </dd>
                      </div>
                      <div>
                        <dt className="text-(--color-ink-muted)">Статус</dt>
                        <dd className="mt-1">
                          <Status value={member.status} />
                        </dd>
                      </div>
                    </dl>
                  </article>
                ))}
              </div>
            </>
          )}
          {members.last_page > 1 && (
            <nav
              aria-label="Страницы участников"
              className="mt-5 flex flex-wrap items-center justify-between gap-3"
            >
              <p className="text-sm text-(--color-ink-muted)">
                Страница {members.current_page} из {members.last_page}
              </p>
              <div className="flex gap-2">
                <button
                  type="button"
                  className={button}
                  disabled={loading || invitationLoading || members.current_page <= 1}
                  onClick={() => go(members.current_page - 1)}
                >
                  Назад
                </button>
                <button
                  type="button"
                  className={button}
                  disabled={
                    loading || invitationLoading || members.current_page >= members.last_page
                  }
                  onClick={() => go(members.current_page + 1)}
                >
                  Вперёд
                </button>
              </div>
            </nav>
          )}
        </section>
        <section
          className={`${panel} mt-4`}
          aria-labelledby="invitations-title"
          aria-busy={invitationLoading}
        >
          <div className="mb-5 flex flex-wrap items-start justify-between gap-3">
            <div>
              <h2 id="invitations-title" className="text-xl font-bold">
                Приглашения в ожидании
              </h2>
              <p className="mt-1 text-sm text-(--color-ink-muted)">
                Показаны не принятые, не отозванные и не истёкшие по сроку приглашения. Открытую
                ссылку нельзя открыть повторно; статус открытия не подтверждает доставку письма.
              </p>
            </div>
            <span className="text-sm text-(--color-ink-muted)">
              Всего: {new Intl.NumberFormat('ru-RU').format(invitations.total)}
            </span>
          </div>
          {failedInvitationPage !== null && (
            <div
              role="alert"
              className="mb-4 rounded-xl bg-(--color-danger-soft) p-4 text-sm text-(--color-danger-ink)"
            >
              <p>Не удалось загрузить приглашения.</p>
              <button
                type="button"
                onClick={() => goInvitations(failedInvitationPage)}
                className={`mt-2 cursor-pointer font-semibold underline ${focus}`}
              >
                Повторить
              </button>
            </div>
          )}
          {invitationLoading && (
            <p role="status" className="mb-4 text-sm text-(--color-ink-muted)">
              Загружаем приглашения…
            </p>
          )}
          {invitations.data.length === 0 ? (
            <div
              role="status"
              className="rounded-xl bg-(--color-surface-muted) px-4 py-8 text-center text-sm"
            >
              {invitations.total === 0
                ? 'Приглашений в ожидании нет'
                : 'На этой странице нет приглашений'}
            </div>
          ) : (
            <ul className="space-y-3">
              {invitations.data.map((invitation) => (
                <li
                  key={invitation.id}
                  className="rounded-xl border border-(--color-border) p-4 text-sm"
                >
                  <div className="flex flex-wrap items-start justify-between gap-3">
                    <p className="min-w-0 break-all font-semibold">{invitation.email}</p>
                    <span className="rounded-full bg-(--color-surface-muted) px-3 py-1 text-(--color-ink-muted)">
                      {invitation.opened ? 'Ссылка открыта' : 'Ссылка не открыта'}
                    </span>
                  </div>
                  <dl className="mt-3 grid gap-3 sm:grid-cols-3">
                    <div>
                      <dt className="text-(--color-ink-muted)">Роль</dt>
                      <dd className="mt-1">{invitation.role_label || 'Роль не определена'}</dd>
                    </div>
                    <div>
                      <dt className="text-(--color-ink-muted)">Доступные бренды</dt>
                      <dd className="mt-1">
                        {invitation.brands.length ? (
                          <ul className="flex flex-wrap gap-2">
                            {invitation.brands.map((brand) => (
                              <li
                                key={brand.id}
                                className="max-w-full break-words rounded-full bg-(--color-success-soft) px-3 py-1 text-(--color-success-ink)"
                              >
                                {brand.name}
                              </li>
                            ))}
                          </ul>
                        ) : (
                          'Нет общих доступных брендов'
                        )}
                      </dd>
                    </div>
                    <div>
                      <dt className="text-(--color-ink-muted)">Срок истечения</dt>
                      <dd className="mt-1">
                        <time dateTime={invitation.expires_at}>
                          {new Intl.DateTimeFormat('ru-RU', {
                            dateStyle: 'medium',
                            timeStyle: 'short',
                          }).format(new Date(invitation.expires_at))}
                        </time>
                      </dd>
                    </div>
                  </dl>
                </li>
              ))}
            </ul>
          )}
          {invitations.last_page > 1 && (
            <nav
              aria-label="Страницы приглашений"
              className="mt-5 flex flex-wrap items-center justify-between gap-3"
            >
              <p className="text-sm text-(--color-ink-muted)">
                Страница {invitations.current_page} из {invitations.last_page}
              </p>
              <div className="flex gap-2">
                <button
                  type="button"
                  className={button}
                  disabled={loading || invitationLoading || invitations.current_page <= 1}
                  onClick={() => goInvitations(invitations.current_page - 1)}
                >
                  Назад
                </button>
                <button
                  type="button"
                  className={button}
                  disabled={
                    loading ||
                    invitationLoading ||
                    invitations.current_page >= invitations.last_page
                  }
                  onClick={() => goInvitations(invitations.current_page + 1)}
                >
                  Вперёд
                </button>
              </div>
            </nav>
          )}
        </section>
      </div>
    </CabinetLayout>
  );
}

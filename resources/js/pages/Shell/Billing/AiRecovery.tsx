import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import CabinetLayout from '@/layouts/CabinetLayout';
import { panel } from '@/features/Shell/Billing/Shared';
import { focus } from '@/features/Shell/Organizations/Shared';
import { useRecovery } from '@/features/Shell/Billing/AiRecovery/useRecovery';

function date(value: string | null) {
  const parsed = value ? new Date(value) : null;
  return parsed && Number.isFinite(parsed.getTime())
    ? new Intl.DateTimeFormat('ru-RU', { dateStyle: 'medium', timeStyle: 'short' }).format(parsed)
    : 'Дата недоступна';
}

export default function AiRecovery({ aiRecoveryRevision }: { aiRecoveryRevision: string }) {
  const { brand } = usePage<{ brand: { id: string } | null }>().props;
  const { view, page, previous, next, retry } = useRecovery(brand?.id ?? '', aiRecoveryRevision);
  const button = `inline-flex min-h-11 cursor-pointer items-center justify-center rounded-lg border border-(--color-border) px-4 text-sm font-semibold text-(--color-link) disabled:cursor-not-allowed disabled:opacity-50 ${focus}`;
  return (
    <CabinetLayout
      breadcrumbs={[
        { label: 'Главная', href: '/dashboard' },
        { label: 'Тариф и оплата', href: '/billing' },
        { label: 'Вызовы ИИ' },
      ]}
    >
      <Head title="Вызовы ИИ с неподтверждённым результатом" />
      <div className="mx-auto max-w-[1500px]">
        <Link
          href="/billing"
          className={`mb-5 inline-flex min-h-11 cursor-pointer items-center gap-2 text-sm font-semibold text-(--color-link) ${focus}`}
        >
          <ArrowLeft size={18} aria-hidden="true" /> К тарифу
        </Link>
        <header className="mb-6">
          <h1 className="text-3xl font-bold leading-tight">
            Вызовы ИИ с неподтверждённым результатом
          </h1>
          <p className="mt-2 text-base text-(--color-ink-muted)">Вызовы для текущего бренда</p>
        </header>
        <section className={panel} aria-labelledby="calls-title" aria-busy={view.loading}>
          <h2 id="calls-title" className="text-xl font-bold">
            Список вызовов
          </h2>
          <p className="mt-2 text-sm leading-6 text-(--color-ink-muted)">
            Состояние вызова и резерв не подтверждают итоговую стоимость. Данные показываются только
            владельцу.
          </p>
          {view.loading && (
            <p role="status" className="mt-5 text-sm">
              Проверяем доступ и загружаем вызовы…
            </p>
          )}
          {view.error && (
            <div
              role="alert"
              className="mt-5 rounded-xl bg-(--color-danger-soft) p-4 text-sm text-(--color-danger-ink)"
            >
              <p>{view.error}</p>
              <button type="button" className={`${button} mt-3`} onClick={retry}>
                Повторить
              </button>
            </div>
          )}
          {!view.loading && !view.error && view.data && (
            <div data-private-profile className="mt-5">
              {view.data.calls.length === 0 ? (
                <p role="status" className="rounded-xl bg-(--color-surface-muted) p-5 text-sm">
                  Вызовов с неподтверждённым результатом нет.
                </p>
              ) : (
                <ul className="space-y-3">
                  {view.data.calls.map((call) => (
                    <li
                      key={call.id}
                      className="rounded-xl border border-(--color-border) p-4 text-sm"
                    >
                      <div className="flex flex-wrap justify-between gap-3">
                        <span className="font-semibold">
                          {call.family === 'seller'
                            ? 'ИИ-продавец'
                            : call.family === 'vision'
                              ? 'Видение'
                              : 'Другой модуль'}
                        </span>
                        <span>
                          {call.status === 'reserved'
                            ? 'Зарезервирован'
                            : 'Результат не подтверждён'}
                        </span>
                      </div>
                      <dl className="mt-3 grid gap-3 sm:grid-cols-2">
                        <div>
                          <dt className="text-(--color-ink-muted)">Номер вызова</dt>
                          <dd className="mt-1 break-all">{call.id}</dd>
                        </div>
                        <div>
                          <dt className="text-(--color-ink-muted)">Дата</dt>
                          <dd className="mt-1">{date(call.createdAt)}</dd>
                        </div>
                      </dl>
                    </li>
                  ))}
                </ul>
              )}
              <nav
                aria-label="Страницы вызовов"
                className="mt-5 flex flex-wrap items-center justify-between gap-3"
              >
                <span className="text-sm">Страница {page}</span>
                <div className="flex gap-2">
                  <button type="button" className={button} disabled={page === 1} onClick={previous}>
                    Назад
                  </button>
                  <button
                    type="button"
                    className={button}
                    disabled={!view.data.nextCursor}
                    onClick={next}
                  >
                    Вперёд
                  </button>
                </div>
              </nav>
            </div>
          )}
        </section>
      </div>
    </CabinetLayout>
  );
}

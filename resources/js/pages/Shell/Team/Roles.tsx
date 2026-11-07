import { Head } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { Crown, Eye, Info, LockKeyhole, Pencil, Settings2, Shield, UserRound } from 'lucide-react';
import CabinetLayout from '@/layouts/CabinetLayout';
import {
  abilityLabels,
  parseMatrix,
  roleDescriptions,
  sectionAccess,
  sections,
  type Access,
  type Matrix,
  type RoleKey,
} from '@/features/Shell/Team/roleMatrix';

type State = { kind: 'loading' } | { kind: 'error' } | { kind: 'ready'; matrix: Matrix };
const icons = { owner: Crown, admin: Shield, editor: Pencil, manager: UserRound, viewer: Eye };
const panel =
  'rounded-2xl border border-(--color-border) bg-(--color-surface) p-4 shadow-(--shadow-card) sm:p-6';
const focus =
  'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--color-focus)';

function Badge({ value }: { value: Access }) {
  const colors: Record<Access, string> = {
    Полный: 'bg-(--color-success-soft) text-(--color-success-ink)',
    Чтение: 'bg-(--color-info-soft) text-(--color-info-ink)',
    Нет: 'bg-(--color-danger-soft) text-(--color-danger-ink)',
    Частично: 'bg-(--color-warning-soft) text-(--color-warning-ink)',
    'Нет данных': 'bg-(--color-surface-muted) text-(--color-ink-muted)',
  };
  return (
    <span
      className={`inline-flex min-h-8 items-center rounded-full px-3 py-1 text-sm font-medium ${colors[value]}`}
    >
      {value}
    </span>
  );
}

function RoleName({ role, label }: { role: RoleKey; label: string }) {
  const Icon = icons[role];
  return (
    <span className="flex items-center gap-3">
      <span className="grid size-10 shrink-0 place-items-center rounded-full bg-(--color-info-soft) text-(--color-info-ink)">
        <Icon size={19} aria-hidden="true" />
      </span>
      <span className="font-semibold">{label}</span>
    </span>
  );
}

function ReadyMatrix({ matrix }: { matrix: Matrix }) {
  if (!matrix.roles.length || !matrix.abilities.length) {
    return (
      <div role="status" className={`${panel} text-sm text-(--color-ink-muted)`}>
        Матрица ролей пока недоступна. Попробуйте обновить страницу позже.
      </div>
    );
  }
  const abilities = matrix.abilities.filter((item) => Object.hasOwn(abilityLabels, item.ability));
  const hasUnknown = abilities.length !== matrix.abilities.length;
  return (
    <>
      <section className={`${panel} mb-4`} aria-labelledby="roles-description-title"><h2 id="roles-description-title" className="text-xl font-bold">Описание ролей</h2><ul className="mt-4 grid gap-4 sm:grid-cols-2">{matrix.roles.map((role) => <li key={role.key}><RoleName role={role.key} label={role.label} /><p className="mt-2 text-sm text-(--color-ink-muted)">{roleDescriptions[role.key]}</p></li>)}</ul></section>
      <section className={panel} aria-labelledby="matrix-title">
        <div className="mb-4 flex flex-wrap items-center gap-x-3 gap-y-2">
          <h2 id="matrix-title" className="text-xl font-bold">
            Матрица ролей
          </h2>
          <span className="inline-flex items-center gap-2 text-sm text-(--color-ink-muted)">
            <Info size={17} aria-hidden="true" />
            Роли фиксированы · только чтение
          </span>
        </div>
        <p className="mb-5 text-sm leading-6 text-(--color-ink-muted)">
          Сводка относится к перечисленным действиям. «Частично» означает, что доступна лишь часть
          из них.
        </p>
        {hasUnknown && <p role="status" className="mb-4 text-sm text-(--color-warning-ink)">Часть действий пока не отображается в таблице.</p>}
        <div
          className="hidden overflow-x-auto md:block"
          role="region"
          aria-label="Таблица матрицы ролей"
          tabIndex={0}
        >
          <table className="w-full min-w-[680px] border-collapse text-left text-sm">
            <thead className="bg-(--color-surface-muted)">
              <tr>
                <th scope="col" className="rounded-l-lg px-4 py-3 font-semibold">
                  Роль
                </th>
                {sections.map((section) => (
                  <th scope="col" key={section.key} className="px-3 py-3 text-center font-semibold">
                    {section.label}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {matrix.roles.map((role) => (
                <tr key={role.key} className="border-b border-(--color-border)">
                  <th scope="row" className="px-4 py-3">
                    <RoleName role={role.key} label={role.label} />
                  </th>
                  {sections.map((section) => (
                    <td key={section.key} className="px-3 py-3 text-center">
                      <Badge value={sectionAccess(matrix, role.key, section)} />
                    </td>
                  ))}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <div className="grid gap-3 md:hidden">
          {matrix.roles.map((role) => (
            <section
              key={role.key}
              aria-label={`Роль ${role.label}`}
              className="rounded-xl border border-(--color-border) p-4"
            >
              <RoleName role={role.key} label={role.label} />
              <dl className="mt-3 divide-y divide-(--color-border)">
                {sections.map((section) => (
                  <div key={section.key} className="flex items-center justify-between gap-3 py-2">
                    <dt className="text-sm">{section.label}</dt>
                    <dd>
                      <Badge value={sectionAccess(matrix, role.key, section)} />
                    </dd>
                  </div>
                ))}
              </dl>
            </section>
          ))}
        </div>
        <div className="mt-5 flex gap-3 rounded-xl bg-(--color-info-soft) p-4 text-sm leading-6 text-(--color-info-ink)">
          <Info className="mt-0.5 shrink-0" size={18} aria-hidden="true" />
          <p>
            Доступ зависит от активного участника, выбранного бренда и правил конкретного действия.
            Редактор и менеджер — варианты роли участника.
          </p>
        </div>
        <details className="mt-5">
          <summary
            className={`cursor-pointer rounded-md text-sm font-semibold text-(--color-link) ${focus}`}
          >
            Показать права по действиям
          </summary>
          <p className="mt-3 text-sm leading-6 text-(--color-ink-muted)">
            Отдельные разрешения могут дополнительно ограничиваться правилами объекта и доступом к
            брендам.
          </p>
          <div
            className="mt-3 overflow-x-auto"
            role="region"
            aria-label="Права по действиям"
            tabIndex={0}
          >
            <table className="w-full min-w-[760px] border-collapse text-left text-sm">
              <thead className="bg-(--color-surface-muted)">
                <tr>
                  <th scope="col" className="px-3 py-3">
                    Действие
                  </th>
                  {matrix.roles.map((role) => (
                    <th key={role.key} scope="col" className="px-3 py-3 text-center">
                      {role.label}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {abilities.map((ability) => (
                  <tr key={ability.ability} className="border-b border-(--color-border)">
                    <th scope="row" className="px-3 py-2 font-medium">
                      {abilityLabels[ability.ability]}
                    </th>
                    {matrix.roles.map((role) => (
                      <td key={role.key} className="px-3 py-2 text-center">
                        {ability.roles[role.key] ? 'Да' : 'Нет'}
                      </td>
                    ))}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </details>
      </section>
      {(!matrix.limitations.perModuleGrantsAvailable ||
        !matrix.limitations.customRolesAvailable) && (
        <div className="mt-5 grid gap-4 md:grid-cols-2">
          {!matrix.limitations.perModuleGrantsAvailable && (
            <section className={`${panel} flex items-start gap-3`}>
              <LockKeyhole
                size={21}
                className="mt-0.5 shrink-0 text-(--color-ink-muted)"
                aria-hidden="true"
              />
              <div>
                <h2 className="font-semibold">
                  Доступ к модулям внутри бренда{' '}
                  <span className="ml-1 rounded-full bg-(--color-warning-soft) px-2 py-1 text-sm text-(--color-warning-ink)">
                    Позже
                  </span>
                </h2>
                <p className="mt-2 text-sm leading-6 text-(--color-ink-muted)">
                  Настройка доступа к отдельным модулям пока недоступна.
                </p>
              </div>
            </section>
          )}
          {!matrix.limitations.customRolesAvailable && (
            <section className={`${panel} flex items-start gap-3`}>
              <Settings2
                size={21}
                className="mt-0.5 shrink-0 text-(--color-ink-muted)"
                aria-hidden="true"
              />
              <div>
                <h2 className="font-semibold">
                  Собственные роли{' '}
                  <span className="ml-1 rounded-full bg-(--color-warning-soft) px-2 py-1 text-sm text-(--color-warning-ink)">
                    Позже
                  </span>
                </h2>
                <p className="mt-2 text-sm leading-6 text-(--color-ink-muted)">
                  Создание собственных ролей пока недоступно.
                </p>
              </div>
            </section>
          )}
        </div>
      )}
    </>
  );
}

export default function Roles() {
  const [attempt, setAttempt] = useState(0);
  const [state, setState] = useState<State>({ kind: 'loading' });
  useEffect(() => {
    const controller = new AbortController();
    void fetch('/team/roles/matrix', {
      headers: { Accept: 'application/json' },
      credentials: 'same-origin',
      signal: controller.signal,
    })
      .then((response) => {
        if (!response.ok) throw new Error('Request failed');
        return response.json() as Promise<unknown>;
      })
      .then((data) => {
        if (!controller.signal.aborted) setState({ kind: 'ready', matrix: parseMatrix(data) });
      })
      .catch(() => {
        if (!controller.signal.aborted) setState({ kind: 'error' });
      });
    return () => controller.abort();
  }, [attempt]);
  return (
    <CabinetLayout
      breadcrumbs={[{ label: 'Главная', href: '/dashboard' }, { label: 'Роли и доступ' }]}
    >
      <Head title="Роли и доступ" />
      <div className="mx-auto max-w-[1500px]">
        <header className="mb-6">
          <h1 className="text-3xl font-bold leading-tight">Роли и доступ</h1>
          <p className="mt-2 text-base text-(--color-ink-muted)">
            Права участников компании и доступ к брендам
          </p>
        </header>
        {state.kind === 'loading' && (
          <div role="status" aria-live="polite" className={panel}>
            <p className="text-sm text-(--color-ink-muted)">Загружаем матрицу ролей…</p>
            <div
              aria-hidden="true"
              className="mt-4 h-32 animate-pulse rounded-xl bg-(--color-surface-muted) motion-reduce:animate-none"
            />
          </div>
        )}
        {state.kind === 'error' && (
          <div role="alert" className={panel}>
            <h2 className="font-semibold">Не удалось загрузить матрицу ролей</h2>
            <p className="mt-2 text-sm text-(--color-ink-muted)">
              Проверьте подключение или войдите в кабинет снова.
            </p>
            <button
              type="button"
              className={`mt-4 min-h-11 cursor-pointer rounded-lg bg-(--color-action) px-4 text-sm font-semibold text-white ${focus}`}
              onClick={() => {
                setState({ kind: 'loading' });
                setAttempt((value) => value + 1);
              }}
            >
              Повторить
            </button>
          </div>
        )}
        {state.kind === 'ready' && <ReadyMatrix matrix={state.matrix} />}
      </div>
    </CabinetLayout>
  );
}

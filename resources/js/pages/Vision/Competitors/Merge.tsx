import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import CabinetLayout from '@/layouts/CabinetLayout';
import { Modal } from '@/components/ui/overlay';

type Entity = { name: string; is_brand: boolean; aliases?: string[]; sources?: string[] };
const button =
  'inline-flex min-h-11 cursor-pointer items-center rounded-lg border border-(--color-border) bg-(--color-surface) px-4 py-2 text-sm font-semibold text-(--color-link) focus-visible:outline-2 focus-visible:outline-(--color-focus) disabled:cursor-not-allowed disabled:opacity-50';
const field =
  'min-h-11 rounded-lg border border-(--color-border) bg-(--color-surface) px-3 text-sm';
export default function Merge({
  entities,
  available,
  canEdit,
  status,
  distinctPairs = [],
}: {
  entities: Entity[];
  available: boolean;
  canEdit: boolean;
  status?: string | null;
  distinctPairs?: [string, string][];
}) {
  const [search, setSearch] = useState('');
  const [selected, setSelected] = useState<string[]>([]);
  const [target, setTarget] = useState('');
  const [open, setOpen] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const candidates = entities.filter((entity) => !entity.is_brand);
  const normalise = (value: string) =>
    value
      .toLocaleLowerCase('ru')
      .replace(/(?:^|\s)(?:ооо|ао|ип)(?=\s|$)/g, ' ')
      .replace(/[^\p{L}\p{N}]/gu, '');
  const pairs = candidates
    .flatMap((entity, index) =>
      candidates
        .slice(index + 1)
        .filter(
          (other) =>
            normalise(entity.name).length >= 3 && normalise(entity.name) === normalise(other.name),
        )
        .map((other) => [entity.name, other.name] as const),
    )
    .filter(
      ([a, b]) => !distinctPairs.some(([x, y]) => (x === a && y === b) || (x === b && y === a)),
    );
  const choose = (names: string[]) => {
    setSelected(names);
    setTarget(names[0] ?? '');
    setError('');
    setOpen(true);
  };
  function save(distinct = false) {
    const sources = selected.filter((name) => name !== target);
    router.post(
      '/vision/competitors/merge',
      distinct
        ? { source: sources[0], target, distinct: true }
        : { sources, target },
      {
        preserveScroll: true,
        onStart: () => {
          setBusy(true);
          setError('');
        },
        onFinish: () => setBusy(false),
        onSuccess: () => {
          setOpen(false);
          setSelected([]);
        },
        onError: (errors) =>
          setError(Object.values(errors)[0] || 'Не удалось сохранить объединение.'),
        onNetworkError: () => {
          setError('Нет связи. Повторите попытку.');
          return false;
        },
        onHttpException: () => {
          setError('Не удалось сохранить объединение.');
          return false;
        },
      },
    );
  }
  return (
    <CabinetLayout
      breadcrumbs={[
        { label: 'Видение', href: '/vision' },
        { label: 'Конкуренты', href: '/vision/competitors' },
        { label: 'Объединить дубли' },
      ]}
    >
      <Head title="Объединить дубли названий" />
      <main className="mx-auto max-w-[1200px] space-y-5" data-private-profile>
        <h1 className="text-3xl font-bold">Объединить дубли названий</h1>
        <Link
          href="/vision/competitors"
          className="inline-flex min-h-11 cursor-pointer items-center text-sm font-semibold text-(--color-link)"
        >
          ← К конкурентам
        </Link>
        {status && (
          <p role="status" className="rounded-lg bg-(--color-success-soft) p-3 text-sm">
            {status}
          </p>
        )}
        {busy && (
          <p
            role="status"
            aria-live="polite"
            className="rounded-lg bg-(--color-info-soft) p-3 text-sm"
          >
            Пересчитываем отчёт по сохранённым ответам…
          </p>
        )}
        {error && !open && (
          <p role="alert" className="rounded-lg bg-(--color-danger-soft) p-3 text-sm">
            {error}
          </p>
        )}
        {!available ? (
          <p className="text-sm">Подтверждённого отчёта пока нет.</p>
        ) : !canEdit ? (
          <p className="text-sm">Нет прав на объединение названий.</p>
        ) : (
          <>
            <section className="rounded-xl border border-(--color-border) bg-(--color-surface) p-5">
              <h2 className="text-xl font-bold">Предложенные группы</h2>
              {pairs.length ? (
                <ul className="mt-4 space-y-3">
                  {pairs.map(([a, b]) => (
                    <li key={`${a}|${b}`} className="flex flex-wrap items-center gap-2">
                      <span className="rounded-full bg-(--color-surface-muted) px-3 py-2 text-sm">
                        {a}
                      </span>
                      <span className="rounded-full bg-(--color-surface-muted) px-3 py-2 text-sm">
                        {b}
                      </span>
                      <button
                        type="button"
                        className={button}
                        disabled={busy}
                        onClick={() => choose([a, b])}
                      >
                        Объединить
                      </button>
                      <button
                        type="button"
                        className={button}
                        disabled={busy}
                        onClick={() => {
                          setSelected([a, b]);
                          setTarget(b);
                          router.post(
                            '/vision/competitors/merge',
                            { source: a, target: b, distinct: true },
                            {
                              preserveScroll: true,
                              onStart: () => setBusy(true),
                              onFinish: () => setBusy(false),
                              onError: () => setError('Не удалось сохранить решение.'),
                              onNetworkError: () => {
                                setError('Нет связи. Повторите попытку.');
                                return false;
                              },
                            },
                          );
                        }}
                      >
                        Это разные
                      </button>
                    </li>
                  ))}
                </ul>
              ) : (
                <p className="mt-3 text-sm">Предложенных дублей нет.</p>
              )}
            </section>
            <section className="rounded-xl border border-(--color-border) bg-(--color-surface) p-5">
              <h2 className="text-xl font-bold">Объединить вручную</h2>
              <label className="mt-4 block text-sm">
                Поиск названия
                <input
                  className={`${field} mt-2 block w-full max-w-md`}
                  value={search}
                  onChange={(event) => setSearch(event.target.value)}
                />
              </label>
              <div className="mt-4 overflow-x-auto">
                <table className="w-full min-w-[400px] text-left text-sm">
                  <thead>
                    <tr>
                      <th className="p-2">Выбрать</th>
                      <th className="p-2">Название</th>
                      <th className="p-2">Источник</th>
                    </tr>
                  </thead>
                  <tbody>
                    {candidates
                      .filter((entity) =>
                        entity.name
                          .toLocaleLowerCase('ru')
                          .includes(search.toLocaleLowerCase('ru')),
                      )
                      .map((entity) => (
                        <tr key={entity.name} className="border-t border-(--color-border)">
                          <td className="p-2">
                            <input
                              type="checkbox"
                              aria-label={`Выбрать ${entity.name}`}
                              checked={selected.includes(entity.name)}
                              onChange={(event) =>
                                setSelected(
                                  event.target.checked
                                    ? [...selected, entity.name]
                                    : selected.filter((name) => name !== entity.name),
                                )
                              }
                            />
                          </td>
                          <td className="p-2">
                            {entity.name}
                            {!!entity.aliases?.length && (
                              <p className="text-(--color-ink-muted)">
                                Объединено: {entity.aliases.join(', ')}
                              </p>
                            )}
                          </td>
                          <td className="p-2">{entity.sources?.join(', ') || 'Нет данных'}</td>
                        </tr>
                      ))}
                  </tbody>
                </table>
              </div>
              {!candidates.length && <p className="mt-3 text-sm">Компаний для объединения нет.</p>}
              <button
                type="button"
                className={`${button} mt-4`}
                disabled={busy || selected.length < 2}
                onClick={() => choose(selected)}
              >
                Объединить в…
              </button>
            </section>
          </>
        )}
        <Modal open={open} title="Объединить названия?" onClose={() => !busy && setOpen(false)}>
          <p>Выбрано: {selected.length}</p>
          <div className="mt-3 flex flex-wrap gap-2">
            {selected.map((name) => (
              <span
                key={name}
                className="rounded-full bg-(--color-surface-muted) px-3 py-2 text-sm"
              >
                {name}
              </span>
            ))}
          </div>
          <label className="mt-4 block text-sm">
            Основное название
            <select
              className={`${field} mt-2 block w-full cursor-pointer`}
              value={target}
              onChange={(event) => setTarget(event.target.value)}
            >
              {selected.map((name) => (
                <option key={name} value={name}>
                  {name}
                </option>
              ))}
            </select>
          </label>
          <p className="mt-4 text-sm">
            После объединения будет создана новая версия отчёта; исходный отчёт сохранится.
          </p>
          {error && (
            <p role="alert" className="mt-3 text-sm text-(--color-danger-ink)">
              {error}
            </p>
          )}
          <div className="mt-4 flex gap-3">
            <button type="button" className={button} disabled={busy} onClick={() => setOpen(false)}>
              Отмена
            </button>
            <button
              type="button"
              className={button}
              disabled={busy || !target}
              onClick={() => save()}
            >
              {busy ? 'Пересчитываем…' : 'Подтвердить'}
            </button>
          </div>
        </Modal>
      </main>
    </CabinetLayout>
  );
}

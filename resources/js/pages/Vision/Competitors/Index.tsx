import ComparisonLeaders from './ComparisonLeaders';
import IntentRadar from './IntentRadar';
import CompanyDetails from './CompanyDetails';
import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { LoaderCircle } from 'lucide-react';
import { Modal } from '@/components/ui';
import CabinetLayout from '@/layouts/CabinetLayout';
type MergeProgress = { stage: 'queued' | 'recalculating' | 'failed' } | null;
type Entity = {
  source_ranking?: { domain: string; answers: number }[];
  quotes?: { text: string; provider: string }[];
  delta?: number | null;
  name: string;
  answers: number;
  share: number | null;
  is_brand: boolean;
  tracked: boolean;
  aliases?: string[];
  models?: string[];
  model_names?: string[];
  model_names_unknown?: boolean;
  intents?: string[];
  questions?: string[];
  sources?: string[];
  intent_rates?: Record<string, number | null>;
  intent_denominators?: Record<string, number | null>;
  intent_bases?: Record<string, string | null>;
};
const intents: Record<string, string> = {
  choose: 'Выбор и сравнение',
  price: 'Цена',
  buy: 'Покупка',
  information: 'Информация',
};
const field =
  'min-h-11 rounded-lg border border-(--color-border) bg-(--color-surface) px-3 text-sm';
export default function Index({
  entities,
  available,
  canEdit,
  status,
  history = [],
  declared = [],
  distinctPairs = [],
  mergeProgress = null,
}: {
  distinctPairs?: [string, string][];
  declared?: { name: string; site: string | null }[];
  entities: Entity[];
  available: boolean;
  canEdit: boolean;
  status?: string | null;
  history?: { id: string; measurement_id: string; created_at: string }[];
  mergeProgress?: MergeProgress;
}) {
  const [tab, setTab] = useState<'all' | 'tracked' | 'declared'>('all');
  const [mergeSearch, setMergeSearch] = useState('');
  const [extraSources, setExtraSources] = useState<string[]>([]);
  const [openedCompany, setOpenedCompany] = useState<string | null>(null);
  const [pendingPair, setPendingPair] = useState<string[]>([]);
  const [left, setLeft] = useState('');
  const [right, setRight] = useState('');
  const [third, setThird] = useState('');
  const [fourth, setFourth] = useState('');
  const [search, setSearch] = useState('');
  const [source, setSource] = useState('');
  const [target, setTarget] = useState('');
  const [busy, setBusy] = useState(false);
  const [mergeOpen, setMergeOpen] = useState(false);
  const [mergeError, setMergeError] = useState('');
  useEffect(() => {
    if (mergeProgress?.stage !== 'queued' && mergeProgress?.stage !== 'recalculating') return;
    const timer = window.setInterval(
      () => router.reload({ only: ['entities', 'history', 'distinctPairs', 'mergeProgress'] }),
      2000,
    );
    return () => window.clearInterval(timer);
  }, [mergeProgress?.stage]);
  const own = entities.find((entity) => entity.is_brand);
  const selected = Array.from(new Set([own?.name, left, right, third, fourth].filter((name): name is string => Boolean(name))));
  const intentKeys = Array.from(
    new Set(
      entities
        .filter((e) => selected.includes(e.name))
        .flatMap((e) => Object.keys(e.intent_rates ?? {})),
    ),
  );
  const questions = Array.from(
    new Set(entities.filter((e) => selected.includes(e.name)).flatMap((e) => e.questions ?? [])),
  );
  const normalise = (name: string) =>
    name
      .toLocaleLowerCase('ru')
      .replace(/(?:^|\s)(?:ооо|ао|ип)(?=\s|$)/g, ' ')
      .replace(/[^\p{L}\p{N}]/gu, '');
  const candidates = entities.filter((e) => !e.is_brand);
  const suggestions = candidates
    .flatMap((e, i) =>
      candidates
        .slice(i + 1)
        .filter(
          (other) => normalise(e.name).length >= 3 && normalise(e.name) === normalise(other.name),
        )
        .map((other) => [e.name, other.name] as const),
    )
    .filter(
      ([a, b]) =>
        !pendingPair.includes(`${a}|${b}`) &&
        !distinctPairs.some(([x, y]) => (x === a && y === b) || (x === b && y === a)),
    );
  const filtered = entities.filter(
    (e) =>
      (tab !== 'tracked' || e.tracked) &&
      e.name.toLocaleLowerCase('ru').includes(search.toLocaleLowerCase('ru')),
  );
  return (
    <CabinetLayout breadcrumbs={[{ label: 'Видение', href: '/vision' }, { label: 'Конкуренты' }]}>
      <Head title="Конкуренты" />
      <main className="mx-auto max-w-[1200px]" data-private-profile>
        <div className="flex flex-wrap justify-between gap-3">
          <div>
            <h1 className="text-3xl font-bold">Кого называют нейросети</h1>
            <p className="mt-2 text-sm text-(--color-ink-muted)">Последний подтверждённый снимок</p>
          </div>
          <Link href="/vision/sources" className="cursor-pointer text-sm text-(--color-link)">
            Источники →
          </Link>
        </div>
        {canEdit && <Link href="/vision/competitors/merge" className="mt-4 inline-flex min-h-11 cursor-pointer items-center text-sm font-semibold text-(--color-link)">Объединить дубли →</Link>}
        {status && (
          <p role="status" className="mt-4 rounded-lg bg-(--color-success-soft) p-3 text-sm">
            {status}
          </p>
        )}
        <nav aria-label="Компании" className="mt-6 flex flex-wrap gap-2">
          {(
            [
              ['all', 'Все названные'],
              ['tracked', 'Отслеживаемые'],
              ['declared', 'Из анкеты, но не названы'],
            ] as const
          ).map(([value, label]) => (
            <button
              key={value}
              type="button"
              aria-pressed={tab === value}
              onClick={() => setTab(value)}
              className={`${field} cursor-pointer ${tab === value ? 'bg-(--color-brand-primary-soft) font-semibold' : ''}`}
            >
              {label}
            </button>
          ))}
        </nav>
        {tab !== 'tracked' && (declared.length > 0 || tab === 'declared') && (
          <section className="mt-4 rounded-xl border border-(--color-border) bg-(--color-surface) p-5">
            <h2 className="text-xl font-bold">Компании из анкеты</h2>
            <p className="mt-2 text-sm text-(--color-ink-muted)">
              Сравнение по точному названию и сохранённым синонимам; другие варианты проверьте
              вручную.
            </p>
            {!available ? (
              <p className="mt-3 text-sm">Сначала завершите замер и подготовьте отчёт.</p>
            ) : (
              (() => {
                const missing = declared.filter(
                  (item) =>
                    tab === 'all' ||
                    !entities.some((entity) =>
                      [entity.name, ...(entity.aliases ?? [])].some(
                        (name) =>
                          name.trim().toLocaleLowerCase('ru') ===
                          item.name.trim().toLocaleLowerCase('ru'),
                      ),
                    ),
                );
                return missing.length ? (
                  <ul className="mt-3 space-y-2">
                    {missing.map((item, index) => (
                      <li key={`${item.name}-${index}`}>
                        {item.name}{' '}
                        {item.site && (
                          <a
                            className="cursor-pointer text-(--color-link)"
                            href={item.site}
                            target="_blank"
                            rel="noopener noreferrer"
                          >
                            Сайт ↗
                          </a>
                        )}
                      </li>
                    ))}
                  </ul>
                ) : (
                  <p className="mt-3 text-sm">Компаний из анкеты, не названных в замере, нет.</p>
                );
              })()
            )}
          </section>
        )}
        {!available ? (
          <p className="mt-6 rounded-xl bg-(--color-surface) p-6 text-sm">
            Пока нет подтверждённого отчёта. Завершите замер и подготовьте отчёт.
          </p>
        ) : (
          <>
            {tab !== 'declared' && (
              <>
                <div className="mt-6 flex flex-wrap gap-3">
                  <label className="text-sm">
                    Поиск
                    <input
                      className={`${field} mt-1 block`}
                      value={search}
                      onChange={(e) => setSearch(e.target.value)}
                      placeholder="Название компании"
                    />
                  </label>
                </div>
                <section className="mt-4 overflow-x-auto rounded-xl border border-(--color-border) bg-(--color-surface)">
                  {filtered.length === 0 ? (
                    <p className="p-5 text-sm">
                      {tab === 'tracked'
                        ? 'Пока нет отслеживаемых компаний.'
                        : 'Компании не найдены.'}{' '}
                      {tab === 'tracked' && (
                        <button
                          type="button"
                          className="cursor-pointer text-(--color-link)"
                          onClick={() => setTab('all')}
                        >
                          Все названные →
                        </button>
                      )}
                    </p>
                  ) : (
                    <table className="w-full min-w-[600px] text-left text-sm">
                      <thead>
                        <tr className="border-b border-(--color-border)">
                          <th className="p-4">Сущность</th>
                          <th className="p-4">Всего упоминаний</th>
                          <th className="p-4">Доля голоса</th>
                          <th className="p-4">Дата отчёта</th>
                          <th className="p-4">Динамика</th>
                          <th className="p-4">Моделей</th>
                          <th className="p-4">Намерения</th>
                          <th className="p-4">
                            Источники (до 3)
                            <span className="block text-sm font-normal text-(--color-ink-muted)">
                              ответов с компанией
                            </span>
                          </th>
                          <th className="p-4">Отслеживать</th>
                        </tr>
                      </thead>
                      <tbody>
                        {filtered.map((e) => (
                          <tr
                            key={e.name}
                            className="border-b border-(--color-border) last:border-0"
                          >
                            <td className="p-4">
                              <details open={openedCompany === e.name}>
                                <summary
                                  className="cursor-pointer font-semibold"
                                  onClick={(event) => {
                                    event.preventDefault();
                                    setOpenedCompany(openedCompany === e.name ? null : e.name);
                                  }}
                                >
                                  {e.name}
                                  {e.is_brand ? ' · Ваш бренд' : ''}
                                </summary>
                                {openedCompany === e.name && (
                                  <CompanyDetails
                                    onClose={() => setOpenedCompany(null)}
                                    company={e}
                                    own={
                                      e.is_brand
                                        ? undefined
                                        : entities.find((item) => item.is_brand)
                                    }
                                    site={declared.find((item) => item.name === e.name)?.site}
                                  />
                                )}
                              </details>
                            </td>
                            <td className="p-4">{e.answers}</td>
                            <td className="p-4">
                              {e.share === null
                                ? 'Недостаточно данных'
                                : `${e.share.toLocaleString('ru-RU')} %`}
                            </td>
                            <td className="p-4">
                              {history[0]
                                ? new Date(history[0].created_at).toLocaleDateString('ru-RU')
                                : 'Нет данных'}
                            </td>
                            <td className="p-4">
                              {e.delta == null
                                ? 'Нет сопоставимых данных'
                                : `${e.delta > 0 ? '+' : ''}${e.delta}`}
                            </td>
                            <td className="p-4">
                              {e.model_names?.length ? (
                                <div className="flex flex-wrap gap-1">
                                  {e.model_names_unknown && <span className="rounded-full bg-(--color-surface-muted) px-2 py-1">Часть моделей не определена</span>}
                                  {e.model_names.map((model) => (
                                    <span
                                      key={model}
                                      className="rounded-full bg-(--color-surface-muted) px-2 py-1"
                                    >
                                      {model}
                                    </span>
                                  ))}
                                </div>
                              ) : (
                                'Нет данных'
                              )}
                            </td>
                            <td className="p-4">
                              {e.intents?.length ? (
                                <div className="flex flex-wrap gap-1">
                                  {e.intents.map((value) => (
                                    <span
                                      key={value}
                                      className="rounded-full bg-(--color-surface-muted) px-2 py-1"
                                    >
                                      {intents[value] ?? value}
                                    </span>
                                  ))}
                                </div>
                              ) : (
                                'Нет данных'
                              )}
                            </td>
                            <td className="p-4">
                              {e.source_ranking?.length ? (
                                <div className="flex flex-wrap gap-1">
                                  {e.source_ranking.map((source) => (
                                    <span
                                      key={source.domain}
                                      className="rounded-full bg-(--color-surface-muted) px-2 py-1"
                                    >
                                      {source.domain} · {source.answers}
                                    </span>
                                  ))}
                                </div>
                              ) : (
                                'Нет данных'
                              )}
                            </td>
                            <td className="p-4">
                              {canEdit && !e.is_brand ? (
                                <button
                                  type="button"
                                  disabled={busy}
                                  aria-pressed={e.tracked}
                                  aria-label={`${e.tracked ? 'Убрать из отслеживаемых' : 'Отслеживать'}: ${e.name}`}
                                  title={e.tracked ? 'Убрать из отслеживаемых' : 'Отслеживать'}
                                  onClick={() =>
                                    router.post(
                                      '/vision/competitors/track',
                                      { name: e.name, tracked: !e.tracked },
                                      {
                                        preserveScroll: true,
                                        onStart: () => setBusy(true),
                                        onFinish: () => setBusy(false),
                                      },
                                    )
                                  }
                                  className="cursor-pointer text-(--color-link) disabled:opacity-50"
                                >
                                  {e.tracked ? '★' : '☆'}
                                </button>
                              ) : (
                                '—'
                              )}
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  )}
                </section>
              </>
            )}
            {available && entities.length >= 2 && (
              <section className="mt-5 rounded-xl border border-(--color-border) bg-(--color-surface) p-5">
                <h2 className="text-xl font-bold">Сравнить компании</h2>
                {own && <p className="mt-3 text-sm font-semibold">Вы: {own.name}</p>}
                <div className="mt-4 grid gap-3 sm:grid-cols-2">
                  {(
                    [
                      [left, setLeft, 'Первая компания'],
                      [right, setRight, 'Вторая компания'],
                      [third, setThird, 'Третья компания'],
                      [fourth, setFourth, 'Четвёртая компания'],
                    ] as const
                  ).map(([value, set, label], index) => (
                    <label key={label} className="text-sm">
                      {label}
                      <select
                        id={`comparison-${index}`}
                        className={`${field} mt-1 block w-full cursor-pointer`}
                        value={value}
                        onChange={(e) => set(e.target.value)}
                      >
                        <option value="">Выберите компанию</option>
                        {entities.map((e) => (
                          <option
                            key={e.name}
                            value={e.name}
                            disabled={[left, right, third, fourth].includes(e.name) && e.name !== value}
                          >
                            {e.name}
                          </option>
                        ))}
                      </select>
                    </label>
                  ))}
                </div>
                <button type="button" className="mt-3 min-h-11 cursor-pointer text-sm font-semibold text-(--color-link)" disabled={[left, right, third, fourth].every(Boolean)} onClick={() => document.getElementById(`comparison-${[left, right, third, fourth].findIndex(value => !value)}`)?.focus()}>Добавить конкурента</button>
                {selected.length >= 2 && (
                  <div className="mt-4 overflow-x-auto">
                    <table className="w-full min-w-[500px] text-left text-sm">
                      <thead>
                        <tr>
                          <th className="p-2">Показатель</th>
                          {selected.map((name) => (
                            <th className="p-2" key={name}>
                              {name}
                            </th>
                          ))}
                        </tr>
                      </thead>
                      <tbody>
                        {(
                          ['answers', 'share', 'models', 'intents', 'questions', 'sources'] as const
                        ).map((key) => (
                          <tr className="border-t border-(--color-border)" key={key}>
                            <th className="p-2">
                              {
                                {
                                  answers: 'Упоминания',
                                  share: 'Доля голоса',
                                  models: 'Модели',
                                  intents: 'Намерения',
                                  questions: 'Вопросы',
                                  sources: 'Источники',
                                }[key]
                              }
                            </th>
                            {selected.map((name) => {
                              const value = entities.find((e) => e.name === name)?.[key];
                              return (
                                <td className="p-2" key={name}>
                                  {Array.isArray(value)
                                    ? value.join(', ') || 'Нет данных'
                                    : value == null
                                      ? 'Нет данных'
                                      : key === 'share'
                                        ? `${value} %`
                                        : value}
                                </td>
                              );
                            })}
                          </tr>
                        ))}
                      </tbody>
                    </table>
                    {intentKeys.length > 0 && (
                      <>
                        <h3 className="mt-5 font-semibold">Доля упоминаний по намерениям</h3>
                        <p className="mt-2 text-sm text-(--color-ink-muted)">
                          Показаны рассчитанные доли; прочерк означает недостаточно данных.
                          Основания расчёта могут различаться.
                        </p>
                        <table className="mt-2 w-full min-w-[500px] text-left text-sm">
                          <thead>
                            <tr>
                              <th className="p-2">Намерение</th>
                              {selected.map((name) => (
                                <th key={name} className="p-2">
                                  {name}
                                </th>
                              ))}
                            </tr>
                          </thead>
                          <tbody>
                            {intentKeys.map((intent) => (
                              <tr key={intent} className="border-t border-(--color-border)">
                                <th className="p-2 font-normal">{intents[intent] ?? intent}</th>
                                {selected.map((name) => {
                                  const rate = entities.find((e) => e.name === name)
                                    ?.intent_rates?.[intent];
                                  return (
                                    <td
                                      key={name}
                                      className="p-2"
                                      style={
                                        rate != null
                                          ? {
                                              backgroundColor: `color-mix(in srgb, var(--color-brand-primary) ${Math.max(5, Math.min(45, rate * 0.45))}%, transparent)`,
                                            }
                                          : undefined
                                      }
                                    >
                                      {rate == null ? '—' : `${rate.toLocaleString('ru-RU')} %`}
                                    </td>
                                  );
                                })}
                              </tr>
                            ))}
                          </tbody>
                        </table>
                        <ComparisonLeaders
                          items={entities.filter((e) => selected.includes(e.name))}
                          names={intents}
                        />
                        <IntentRadar
                          items={entities.filter((e) => selected.includes(e.name))}
                          intents={intentKeys}
                          names={intents}
                        />
                      </>
                    )}
                    {questions.length > 0 && (
                      <>
                        <h3 className="mt-5 font-semibold">Упоминания по вопросам</h3>
                        <p className="mt-2 text-sm text-(--color-ink-muted)">
                          Цветом отмечены сохранённые упоминания. Прочерк не означает, что компания
                          отсутствовала.
                        </p>
                        <table className="mt-2 w-full min-w-[500px] text-left text-sm">
                          <thead>
                            <tr>
                              <th className="p-2">Вопрос</th>
                              {selected.map((name) => (
                                <th key={name} className="p-2">
                                  {name}
                                </th>
                              ))}
                            </tr>
                          </thead>
                          <tbody>
                            {questions.map((question) => (
                              <tr key={question} className="border-t border-(--color-border)">
                                <th className="p-2 font-normal">{question}</th>
                                {selected.map((name) => (
                                  <td
                                    key={name}
                                    className={`p-2 ${entities.find((e) => e.name === name)?.questions?.includes(question) ? 'bg-(--color-brand-primary-soft) font-semibold' : ''}`}
                                  >
                                    {entities
                                      .find((e) => e.name === name)
                                      ?.questions?.includes(question)
                                      ? 'Названа'
                                      : '—'}
                                  </td>
                                ))}
                              </tr>
                            ))}
                          </tbody>
                        </table>
                      </>
                    )}
                  </div>
                )}
              </section>
            )}
            {canEdit && (
              <section className="mt-5 rounded-xl border border-(--color-border) bg-(--color-surface) p-5">
                <h2 className="text-xl font-bold">Предложенные группы</h2>
                {(mergeProgress?.stage === 'queued' || mergeProgress?.stage === 'recalculating') && (
                  <p
                    role="status"
                    aria-live="polite"
                    className="mt-2 flex items-center gap-2 rounded-lg bg-(--color-info-soft) p-3 text-sm"
                  >
                    <LoaderCircle aria-hidden="true" size={16} className="animate-spin motion-reduce:animate-none" />
                    Пересчитываем, 1–2 минуты
                  </p>
                )}
                {mergeProgress?.stage === 'failed' && (
                  <p role="alert" className="mt-2 rounded-lg bg-(--color-danger-soft) p-3 text-sm">
                    Не удалось пересчитать отчёт. Попробуйте объединить названия ещё раз.
                  </p>
                )}
                {suggestions.length > 0 && <h3 className="mt-2 font-semibold">Возможные дубли</h3>}
                {suggestions.length === 0 && (
                  <p className="mt-2 text-sm">Предложенных дублей нет.</p>
                )}
                <p className="mt-2 text-sm">Проверьте названия перед объединением.</p>
                {mergeError && (
                  <p role="alert" className="mt-2 text-sm">
                    {mergeError}
                  </p>
                )}
                {suggestions.map(([a, b]) => (
                  <div key={`${a}|${b}`} className="mt-3 flex flex-wrap items-center gap-3">
                    <span>
                      {a} · {b}
                    </span>
                    <button
                      className="cursor-pointer text-(--color-link)"
                      onClick={() => {
                        setSource(a);
                        setTarget(b);
                        setExtraSources([]);
                        setMergeOpen(true);
                      }}
                    >
                      Объединить
                    </button>
                    <button
                      className="cursor-pointer text-(--color-link)"
                      disabled={busy}
                      onClick={() =>
                        router.post(
                          '/vision/competitors/merge',
                          { source: a, target: b, distinct: true },
                          {
                            preserveScroll: true,
                            onStart: () => {
                              setBusy(true);
                              setMergeError('');
                              setPendingPair([`${a}|${b}`]);
                            },
                            onFinish: () => setBusy(false),
                            onSuccess: () => setPendingPair([]),
                            onError: () => {
                              setPendingPair([]);
                              setMergeError('Не удалось сохранить решение.');
                            },
                            onNetworkError: () => {
                              setPendingPair([]);
                              setMergeError('Не удалось связаться с сервером.');
                              return false;
                            },
                            onHttpException: () => {
                              setPendingPair([]);
                              setMergeError('Не удалось сохранить решение.');
                              return false;
                            },
                          },
                        )
                      }
                    >
                      Это разные
                    </button>
                  </div>
                ))}
              </section>
            )}
            {canEdit && (
              <form
                className="mt-5 rounded-xl border border-(--color-border) bg-(--color-surface) p-5"
                onSubmit={(e) => {
                  e.preventDefault();
                  setMergeError('');
                  setMergeOpen(true);
                }}
              >
                <h2 className="text-xl font-bold">Объединить названия</h2>
                <p className="mt-2 text-sm">
                  Выберите названия одной компании. Отчёт будет пересчитан по ответам
                  снимка.
                </p>
                <label className="mt-4 block text-sm">
                  Поиск названий
                  <input
                    className={`${field} mt-1 block w-full`}
                    value={mergeSearch}
                    onChange={(e) => setMergeSearch(e.target.value)}
                    placeholder="Название компании"
                  />
                </label>
                <fieldset className="mt-4">
                  <legend className="text-sm font-semibold">Объединить вручную</legend>
                  <div className="mt-2 overflow-x-auto"><table className="w-full text-left text-sm"><thead><tr><th className="p-2">Выбрать</th><th className="p-2">Название</th><th className="p-2">Источник</th></tr></thead><tbody>
                    {candidates.filter(e => e.name.toLocaleLowerCase('ru').includes(mergeSearch.toLocaleLowerCase('ru')) || e.name === source || e.name === target || extraSources.includes(e.name)).map(e => (
                      <tr key={e.name} className="border-t border-(--color-border)"><td className="p-2"><input type="checkbox" className="cursor-pointer" aria-label={`Дубль: ${e.name}`} checked={source === e.name || target === e.name || extraSources.includes(e.name)} onChange={event => {
                        if (event.target.checked) { if (!source) setSource(e.name); else if (!target) setTarget(e.name); else setExtraSources([...extraSources, e.name]); }
                        else if (source === e.name) setSource(''); else if (target === e.name) setTarget(''); else setExtraSources(extraSources.filter(name => name !== e.name));
                      }}/></td><td className="p-2">{e.name}{!!e.aliases?.length && <p className="text-(--color-ink-muted)">Объединено: {e.aliases.join(', ')}</p>}</td><td className="p-2">{e.sources?.join(', ') || 'Нет данных'}</td></tr>
                    ))}
                  </tbody></table></div>
                </fieldset>
                <div className="mt-4 grid gap-3 sm:grid-cols-2">
                  <label className="text-sm">
                    Объединить
                    <select
                      required
                      className={`${field} mt-1 block w-full cursor-pointer`}
                      aria-label="Объединить"
                      value={source}
                      onChange={(e) => setSource(e.target.value)}
                    >
                      <option value="">Выберите название</option>
                      {entities
                        .filter(
                          (e) =>
                            !e.is_brand &&
                            (e.name
                              .toLocaleLowerCase('ru')
                              .includes(mergeSearch.toLocaleLowerCase('ru')) ||
                              e.name === source),
                        )
                        .map((e) => (
                          <option key={e.name} value={e.name}>
                            {e.name}
                          </option>
                        ))}
                    </select>
                  </label>
                  <label className="text-sm">
                    Основное название
                    <select
                      required
                      className={`${field} mt-1 block w-full cursor-pointer`}
                      aria-label="Основное название"
                      value={target}
                      onChange={(e) => setTarget(e.target.value)}
                    >
                      <option value="">Выберите название</option>
                      {entities
                        .filter(
                          (e) =>
                            !e.is_brand &&
                            e.name !== source &&
                            (e.name
                              .toLocaleLowerCase('ru')
                              .includes(mergeSearch.toLocaleLowerCase('ru')) ||
                              e.name === target),
                        )
                        .map((e) => (
                          <option key={e.name} value={e.name}>
                            {e.name}
                          </option>
                        ))}
                    </select>
                  </label>
                </div>
                <fieldset className="mt-4">
                  <legend className="text-sm font-semibold">Дополнительные названия</legend>
                  <div className="mt-2 flex flex-wrap gap-2">{entities.filter(e => !e.is_brand && e.name !== source && e.name !== target).map(e => <label key={e.name} className="flex cursor-pointer items-center gap-2 rounded-lg border border-(--color-border) p-2 text-sm"><input type="checkbox" checked={extraSources.includes(e.name)} onChange={event=>setExtraSources(event.target.checked ? [...extraSources,e.name] : extraSources.filter(name=>name!==e.name))}/>{e.name}</label>)}</div>
                </fieldset>
                <button
                  disabled={busy || !source || !target || source === target}
                  className="mt-4 min-h-11 cursor-pointer rounded-lg bg-(--color-link) px-4 text-sm font-semibold text-white disabled:opacity-50"
                >
                  Объединить
                </button>
                <p className="mt-3 text-sm text-(--color-ink-muted)">
                  Сохранённый отчёт останется неизменным; после объединения будет подготовлена новая версия.
                </p>
              </form>
            )}
            <Modal
              open={mergeOpen}
              title="Объединить названия?"
              onClose={() => setMergeOpen(false)}
            >
              <p className="mb-3 text-sm text-(--color-ink-muted)">Выбрано: {new Set([source, target, ...extraSources].filter(Boolean)).size}</p>
              <ul className="mb-3 rounded-lg bg-(--color-surface-muted) p-3 text-sm">{Array.from(new Set([source, target, ...extraSources].filter(Boolean))).map(name => <li key={name} className="py-1">{name}</li>)}</ul>
              <label className="mb-3 block text-sm">Основное название в подтверждении<select className={`${field} mt-1 block w-full cursor-pointer`} value={target} onChange={event => {const next=event.target.value;const others=Array.from(new Set([source,target,...extraSources].filter(name => name && name !== next)));setSource(others[0] ?? '');setExtraSources(others.slice(1));setTarget(next);}}>{Array.from(new Set([source,target,...extraSources].filter(Boolean))).map(name => <option key={name} value={name}>{name}</option>)}</select></label>
              <p className="text-sm">
                «{[source, ...extraSources.filter(name=>name !== source && name !== target)].join('», «')}» {extraSources.filter(name=>name !== source && name !== target).length ? 'будут объединены' : 'будет объединено'} с «{target}». Отчёт будет пересчитан по
                сохранённым ответам.
              </p>
              {mergeError && (
                <p role="alert" className="mt-3 text-sm text-(--color-danger-ink)">
                  {mergeError}
                </p>
              )}
              <div className="mt-4 flex gap-3">
                <button
                  type="button"
                  className={`${field} cursor-pointer`}
                  onClick={() => setMergeOpen(false)}
                >
                  Отмена
                </button>
                <button
                  type="button"
                  disabled={busy}
                  className="min-h-11 cursor-pointer rounded-lg bg-(--color-link) px-4 text-sm font-semibold text-white disabled:opacity-50"
                  onClick={() =>
                    router.post(
                      '/vision/competitors/merge',
                      { sources: [source, ...extraSources.filter(name=>name !== source && name !== target)], target },
                      {
                        preserveScroll: true,
                        onStart: () => setBusy(true),
                        onFinish: () => setBusy(false),
                        onSuccess: () => { setMergeOpen(false); setExtraSources([]); },
                        onError: () =>
                          setMergeError(
                            'Не удалось объединить названия. Проверьте данные и доступ.',
                          ),
                        onNetworkError: () => { setMergeError('Нет связи. Повторите попытку.'); return false; },
                        onHttpException: () => { setMergeError('Не удалось сохранить объединение.'); return false; },
                      },
                    )
                  }
                >
                  Подтвердить
                </button>
              </div>
            </Modal>
          </>
        )}
        <section className="mt-5 rounded-xl border border-(--color-border) bg-(--color-surface) p-5">
          <h2 className="text-xl font-bold">История замеров</h2>
          {history.length ? (
            <ul className="mt-3 space-y-2 text-sm">
              {history.map((item) => (
                <li key={item.id}>
                  <Link
                    className="cursor-pointer text-(--color-link)"
                    href={`/vision/report?report=${item.id}`}
                  >
                    Отчёт от {new Date(item.created_at).toLocaleDateString('ru-RU')}
                  </Link>
                </li>
              ))}
            </ul>
          ) : (
            <p className="mt-3 text-sm">Подтверждённых отчётов пока нет.</p>
          )}
        </section>
      </main>
    </CabinetLayout>
  );
}

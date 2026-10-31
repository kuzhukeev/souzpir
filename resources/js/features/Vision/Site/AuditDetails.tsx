import { useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { Drawer, Modal } from '@/components/ui';
import type { Audit, AuditPage, Criterion } from '@/types/Vision/Site';

const card =
  'rounded-2xl border border-(--color-border) bg-(--color-surface) p-5 shadow-(--shadow-card)';
const input =
  'min-h-11 w-full rounded-lg border border-(--color-border) bg-(--color-surface) px-3 py-2 text-base text-(--color-ink) focus-visible:outline-2 focus-visible:outline-(--color-focus)';
const primary =
  'inline-flex min-h-11 cursor-pointer items-center justify-center rounded-lg bg-(--color-action) px-4 py-2 text-sm font-semibold text-white hover:bg-(--color-action-hover) disabled:cursor-not-allowed disabled:opacity-60';
const secondary =
  'inline-flex min-h-11 cursor-pointer items-center justify-center rounded-lg border border-(--color-border) bg-(--color-surface) px-4 py-2 text-sm font-semibold text-(--color-link) hover:bg-(--color-surface-muted) disabled:cursor-not-allowed disabled:opacity-60';
const labels: Record<string, string> = {
  pass: 'Пройден',
  fail: 'Не пройден',
  partial: 'Частично',
  unknown: 'Нет данных',
};
function date(value?: string | null) {
  if (!value) return '—';
  const d = new Date(value);
  return Number.isNaN(d.getTime()) ? value : d.toLocaleDateString('ru-RU');
}
function Errors({ errors }: { errors: Record<string, string> }) {
  return Object.values(errors).length ? (
    <div role="alert" className="rounded-lg bg-(--color-surface-muted) p-3 text-sm">
      {Object.values(errors).map((error, i) => (
        <p key={i}>{error}</p>
      ))}
    </div>
  ) : null;
}
function CriterionRow({ item, canManage }: { item: Criterion; canManage: boolean }) {
  const title = item.title || item.name || item.label || 'Критерий';
  const form = useForm({ title, description: item.explanation || '' });
  const [saved, setSaved] = useState(false);
  return (
    <details className="py-4">
      <summary className="flex cursor-pointer flex-wrap items-center justify-between gap-2">
        <span className="font-semibold">{title}</span>
        <span className="rounded-full bg-(--color-surface-muted) px-3 py-1 text-sm">
          {item.pending ? 'Скоро' : labels[item.status || 'unknown'] || 'Нет данных'}
        </span>
      </summary>
      <div className="mt-4 space-y-3">
        <p className="text-sm text-(--color-ink-muted)">Важность: не определена</p>
        <h3 className="font-semibold">Что сделать</h3>
        <p className="whitespace-pre-wrap break-words text-(--color-ink-muted)">
          {item.explanation || 'Рекомендаций пока нет.'}
        </p>
        {item.evidence && item.evidence.length > 0 && (
          <div>
            <h3 className="font-semibold">Свидетельства проверки</h3>
            <ul className="mt-2 space-y-2">
              {item.evidence.map((evidence, index) => (
                <li
                  key={evidence.id || index}
                  className="rounded-lg bg-(--color-surface-muted) p-3 text-sm"
                >
                  <p className="break-all">{evidence.url}</p>
                  <p className="mt-1 whitespace-pre-wrap break-words">{evidence.excerpt}</p>
                  {evidence.observed_at && (
                    <p className="mt-1 text-(--color-ink-muted)">
                      Проверено: {date(evidence.observed_at)}
                    </p>
                  )}
                </li>
              ))}
            </ul>
          </div>
        )}
        <details className="rounded-lg bg-(--color-surface-muted) p-3">
          <summary className="cursor-pointer font-semibold">Для разработчика</summary>
          <p className="mt-2 text-sm text-(--color-ink-muted)">
            Пример кода для этого критерия не предоставлен.
          </p>
        </details>
        {canManage && !item.pending && (item.status === 'fail' || item.status === 'partial') && (
          <div>
            <button
              type="button"
              className={secondary}
              disabled={form.processing}
              onClick={() =>
                form.post('/vision/actions', {
                  preserveScroll: true,
                  onSuccess: () => setSaved(true),
                })
              }
            >
              {form.processing ? 'Добавляем…' : 'В действия'}
            </button>
            {saved && (
              <p role="status" className="mt-2 text-sm">
                Действие добавлено.
              </p>
            )}
            <Errors errors={form.errors} />
          </div>
        )}
      </div>
    </details>
  );
}
export function Criteria({ criteria, canManage }: { criteria: Criterion[]; canManage: boolean }) {
  if (!criteria.length)
    return <div className={`${card} text-(--color-ink-muted)`}>Критерии пока не проверены.</div>;
  const groups = new Map<string, Criterion[]>();
  criteria.forEach((item) =>
    groups.set(item.group || 'Другие критерии', [
      ...(groups.get(item.group || 'Другие критерии') || []),
      item,
    ]),
  );
  return (
    <div className="space-y-4">
      {Array.from(groups, ([group, items]) => (
        <details key={group} className={card} open>
          <summary className="cursor-pointer text-lg font-semibold">{group}</summary>
          <div className="mt-4 divide-y divide-(--color-border)">
            {items.map((item, index) => (
              <CriterionRow
                key={item.id || item.key || item.code || `${group}-${index}`}
                item={item}
                canManage={canManage}
              />
            ))}
          </div>
        </details>
      ))}
    </div>
  );
}
export function AuditForm() {
  const form = useForm({ url: '' });
  const [open, setOpen] = useState(false);
  function prepare(event: FormEvent) {
    event.preventDefault();
    setOpen(true);
  }
  return (
    <>
      <form onSubmit={prepare} className={`${card} space-y-3`}>
        <h2 className="text-lg font-semibold">Проверить сайт</h2>
        <p className="text-sm text-(--color-ink-muted)">
          Укажите адрес сайта для проверки. Доступность проверки зависит от настроек сервера.
        </p>
        <label className="block text-sm font-semibold" htmlFor="site-url">
          Адрес сайта
        </label>
        <div className="flex flex-wrap gap-3">
          <input
            id="site-url"
            type="url"
            required
            placeholder="https://example.com"
            value={form.data.url}
            onChange={(event) => form.setData('url', event.target.value)}
            className={`${input} min-w-0 flex-1`}
          />
          <button disabled={form.processing} className={primary}>
            {form.processing ? 'Проверяем…' : 'Перепроверить'}
          </button>
        </div>
        <Errors errors={form.errors} />
      </form>
      <Modal open={open} title="Перепроверить сайт?" onClose={() => setOpen(false)}>
        <p className="break-all">{form.data.url}</p>
        <p className="mt-3 text-(--color-ink-muted)">
          Будет проверен доступный публичный HTML. Время проверки зависит от сайта и настроек
          сервера.
        </p>
        <div className="mt-5 flex flex-wrap gap-3">
          <button type="button" className={secondary} onClick={() => setOpen(false)}>
            Отмена
          </button>
          <button
            type="button"
            className={primary}
            disabled={form.processing}
            onClick={() => {
              setOpen(false);
              form.post('/vision/site/audit', { preserveScroll: true });
            }}
          >
            Начать проверку
          </button>
        </div>
      </Modal>
    </>
  );
}
export function BotAccess({ audit }: { audit: Audit }) {
  const access = audit.ai_access;
  const [copyStatus, setCopyStatus] = useState('');
  if (!access)
    return (
      <div className={`${card} text-(--color-ink-muted)`}>Данных о доступе ботов пока нет.</div>
    );
  const snippet = 'User-agent: GPTBot\nAllow: /';
  async function copy() {
    try {
      await navigator.clipboard.writeText(snippet);
      setCopyStatus('Скопировано');
    } catch {
      setCopyStatus('Не удалось скопировать');
    }
  }
  return (
    <div className="grid gap-4 lg:grid-cols-[1.3fr_1fr]">
      <section className={card}>
        <h2 className="text-xl font-semibold">Правила robots.txt</h2>
        {access.bots?.length ? (
          <ul className="mt-4 divide-y divide-(--color-border)">
            {access.bots.map((bot) => (
              <li key={bot.user_agent} className="py-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                  <strong>{bot.user_agent}</strong>
                  <span className="rounded-full bg-(--color-surface-muted) px-3 py-1 text-sm">
                    Правило robots.txt:{' '}
                    {bot.allowed === true
                      ? 'разрешено'
                      : bot.allowed === false
                        ? 'запрещено'
                        : 'нет достоверных данных'}
                  </span>
                </div>
                {bot.role && <p className="mt-2 text-sm text-(--color-ink-muted)">{bot.role}</p>}
                {bot.note && <p className="mt-1 text-sm text-(--color-ink-muted)">{bot.note}</p>}
              </li>
            ))}
          </ul>
        ) : (
          <p className="mt-4 text-(--color-ink-muted)">Нет данных о правилах для ботов.</p>
        )}
      </section>
      <div className="space-y-4">
        {(
          [
            ['robots.txt', access.robots],
            ['llms.txt', access.llms],
            ['Карта сайта', access.sitemap],
          ] as const
        ).map(([label, file]) => (
          <section className={card} key={label}>
            <h2 className="text-lg font-semibold">{label}</h2>
            <p className="mt-2 text-(--color-ink-muted)">
              {file?.status == null ? 'Нет данных' : `Статус: ${file.status}`}
            </p>
          </section>
        ))}
        <section className={card}>
          <h2 className="text-lg font-semibold">Капча и антибот</h2>
          <p className="mt-2 text-(--color-ink-muted)">Нет данных</p>
        </section>
        <section className={card}>
          <h2 className="text-lg font-semibold">Пример правила для robots.txt</h2>
          <pre className="mt-3 overflow-x-auto rounded-lg bg-(--color-surface-muted) p-3 text-sm">
            {snippet}
          </pre>
          <button type="button" className={`${secondary} mt-3`} onClick={() => void copy()}>
            Копировать
          </button>
          <span role="status" className="ml-3 text-sm">
            {copyStatus}
          </span>
        </section>
        {access.note && <p className="text-sm text-(--color-ink-muted)">{access.note}</p>}
      </div>
    </div>
  );
}
export function Pages({ audit }: { audit: Audit }) {
  const [query, setQuery] = useState('');
  const [selected, setSelected] = useState<AuditPage | null>(null);
  const pages = (audit.pages || []).filter((page) =>
    `${page.url} ${page.primary_heading || ''}`
      .toLocaleLowerCase('ru')
      .includes(query.toLocaleLowerCase('ru')),
  );
  return (
    <section className={card}>
      <label htmlFor="page-search" className="block text-sm font-semibold">
        Поиск по страницам
      </label>
      <input
        id="page-search"
        className={`${input} mt-2 max-w-xl`}
        value={query}
        onChange={(event) => setQuery(event.target.value)}
        placeholder="Адрес или заголовок"
      />
      {pages.length ? (
        <div className="mt-4 divide-y divide-(--color-border)">
          {pages.map((page) => (
            <div key={page.url} className="flex flex-wrap items-center justify-between gap-3 py-4">
              <div className="min-w-0">
                <strong className="block break-words">
                  {page.primary_heading || 'Без заголовка'}
                </strong>
                <span className="break-all text-sm text-(--color-ink-muted)">{page.url}</span>
                <p className="mt-1 text-sm text-(--color-ink-muted)">
                  Проверено: {date(page.observed_at)} · Статус: {page.status ?? '—'}
                </p>
              </div>
              <button
                type="button"
                className={secondary}
                onClick={() => setSelected(page)}
                aria-label={`Подробнее: ${page.primary_heading || page.url}`}
              >
                Подробнее
              </button>
            </div>
          ))}
        </div>
      ) : (
        <p className="mt-4 text-(--color-ink-muted)">
          {query ? 'Страницы не найдены.' : 'Проверенных страниц пока нет.'}
        </p>
      )}
      <Drawer
        open={selected !== null}
        title={selected?.primary_heading || 'Страница'}
        onClose={() => setSelected(null)}
      >
        {selected && (
          <div className="space-y-4">
            <p className="break-all">{selected.url}</p>
            <p>
              Проверено: {date(selected.observed_at)} · Статус: {selected.status ?? '—'}
            </p>
            <div>
              <h3 className="font-semibold">Заголовки страницы</h3>
              {selected.headings?.length ? (
                <ul className="mt-2 list-disc pl-5">
                  {selected.headings.map((heading, index) => (
                    <li key={index}>{heading}</li>
                  ))}
                </ul>
              ) : (
                <p className="mt-2 text-(--color-ink-muted)">Нет данных</p>
              )}
            </div>
            <div>
              <h3 className="font-semibold">Извлечённые ссылки</h3>
              {selected.references?.length ? (
                <ul className="mt-2 space-y-2">
                  {selected.references.map((ref, index) => (
                    <li key={index} className="break-all">
                      {ref.label || ref.url} · {ref.url}
                    </li>
                  ))}
                </ul>
              ) : (
                <p className="mt-2 text-(--color-ink-muted)">Нет данных</p>
              )}
            </div>
            <div>
              <h3 className="font-semibold">Проблемы страницы</h3>
              <p className="mt-2 text-(--color-ink-muted)">
                Отдельные данные о проблемах страницы недоступны.
              </p>
            </div>
            <div>
              <h3 className="font-semibold">Превью текста</h3>
              <p className="mt-2 whitespace-pre-wrap break-words text-(--color-ink-muted)">
                {selected.text?.slice(0, 1500) || 'Нет данных'}
              </p>
            </div>
          </div>
        )}
      </Drawer>
    </section>
  );
}

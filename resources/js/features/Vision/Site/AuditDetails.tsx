import { criterionGroup } from '@/features/Vision/Site/groups';
import { Link, useForm, usePage } from '@inertiajs/react';
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
// Примеры иллюстрируют форму данных и не утверждают, что они найдены на сайте.
const codeExamples: Record<number, string> = {
  1: '<main>Доступное содержимое страницы</main>',
  2: '<a href="/catalog/">Каталог</a>',
  3: 'User-agent: GPTBot\nAllow: /',
  4: '<script type="application/ld+json">{"@context":"https://schema.org","@type":"Product","name":"Товар"}</script>',
  5: '<h1>Название товара</h1><p>Для чего он предназначен</p>',
  6: '<dl><dt>Объём</dt><dd>1 л</dd></dl>',
  7: '<nav><a href="/catalog/category/">Категория</a></nav>',
  8: '<span>Артикул: EXAMPLE-1</span>',
  9: '<span>Цена: по запросу</span>',
  10: '<p>Наличие и минимальный заказ уточняйте у менеджера.</p>',
  11: '<p>Способы получения и сроки уточняйте при заказе.</p>',
  12: '<a href="/contacts/">Связаться с нами</a>',
  13: '<section><h2>О компании</h2><p>Сведения о компании</p></section>',
  14: '<a href="/documents/">Подтверждающие документы</a>',
  15: '<p>Указывайте согласованные сведения о товаре на всех страницах.</p>',
  16: '<time datetime="2026-01-01">Дата обновления сведений</time>',
};
function CriterionRow({ item, canManage, auditId }: { item: Criterion; canManage: boolean; auditId?: string | null }) {
  const title = item.title || item.name || item.label || 'Критерий';
  const form = useForm({ title, description: item.explanation || '', audit_id: auditId || '', criterion_id: item.id == null ? '' : String(item.id) });
  const [saved, setSaved] = useState(false);
  const [failure, setFailure] = useState(false);
  const example = codeExamples[Number(item.id)];
  const observedJson = item.evidence?.find((evidence) => {
    if (!evidence.excerpt?.trim().startsWith('{')) return false;
    try { return typeof JSON.parse(evidence.excerpt) === 'object'; } catch { return false; }
  });
  return (
    <details className="py-4">
      <summary className="flex cursor-pointer flex-wrap items-center justify-between gap-2">
        <span className="font-semibold">{title}</span>
        <span className="rounded-full bg-(--color-surface-muted) px-3 py-1 text-sm">
          {item.pending ? 'Скоро' : labels[item.status || 'unknown'] || 'Нет данных'}
        </span>
      </summary>
      <div className="mt-4 space-y-3">
        <p className="text-sm text-(--color-ink-muted)">{typeof (item as Criterion & {weight?: number}).weight === 'number' ? `Важность по весу: ${(item as Criterion & {weight:number}).weight} из 100 баллов. ` : 'Вес и важность критерия недоступны. '}Все критерии инспектора имеют одинаковый вес.</p>
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
                  <p className="break-all">Источник: {evidence.url || 'не указан'}</p>
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
        {!item.evidence?.length && <p className="text-sm text-(--color-ink-muted)">Источник свидетельства недоступен.</p>}
        <details className="rounded-lg bg-(--color-surface-muted) p-3">
          <summary className="cursor-pointer font-semibold">Для разработчика</summary>
          {example && <><p className="mt-2 text-sm text-(--color-ink-muted)">Пример структуры; подставьте реальные сведения вашей компании.</p><pre className="mt-2 overflow-x-auto whitespace-pre-wrap break-all rounded-lg bg-(--color-surface) p-3 text-sm">{example}</pre></>}
          {observedJson ? <><p className="mt-2 text-sm text-(--color-ink-muted)">Найденный фрагмент структурированных данных с проверенной страницы. Используйте как пример исходных данных, не как готовую рекомендацию.</p><pre className="mt-2 overflow-x-auto whitespace-pre-wrap break-all rounded-lg bg-(--color-surface) p-3 text-sm">{observedJson.excerpt}</pre></> : !example ? <p className="mt-2 text-sm text-(--color-ink-muted)">Пример кода для этого критерия не предоставлен.</p> : null}
        </details>
        {canManage && auditId && item.id != null && !item.pending && (item.status === 'fail' || item.status === 'partial') && (
          <div>
            <button
              type="button"
              className={secondary}
              disabled={form.processing}
              onClick={() =>
                form.post('/vision/actions', {
                  preserveScroll: true,
                  onStart: () => { setFailure(false); setSaved(false); },
                  onSuccess: () => setSaved(true),
                  onNetworkError: () => { setFailure(true); return false; },
                  onHttpException: () => { setFailure(true); return false; },
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
            {failure && <p role="alert" className="mt-2 text-sm text-(--color-danger-ink)">Не удалось добавить действие. Попробуйте ещё раз.</p>}
            <Errors errors={form.errors} />
          </div>
        )}
      </div>
    </details>
  );
}
export function Criteria({ criteria, canManage, auditId }: { criteria: Criterion[]; canManage: boolean; auditId?: string | null }) {
  if (!criteria.length)
    return <div className={`${card} text-(--color-ink-muted)`}>Критерии пока не проверены.</div>;
  const groups = new Map<string, Criterion[]>();
  criteria.forEach((item) =>
    groups.set(criterionGroup(item), [
      ...(groups.get(criterionGroup(item)) || []),
      item,
    ]),
  );
  return (
    <div className="space-y-4">
      <p className="text-sm text-(--color-ink-muted)">Важность определяется весом критерия в расчёте готовности; проверки инспектора имеют одинаковый вес.</p>
      {Array.from(groups, ([group, items]) => (
        <details key={group} className={card} open>
          <summary className="cursor-pointer text-lg font-semibold">{group}</summary>
          <p className="mt-2 text-sm text-(--color-ink-muted)">Состав группы: {items.map((item) => item.title || item.name || item.label || 'критерий без названия').join(' · ')}</p>
          <div className="mt-4 divide-y divide-(--color-border)">
            {items.map((item, index) => (
              <CriterionRow
                auditId={auditId}
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
export function AuditForm({initialUrl = '', fixedUrl = false, pageLimit}: {initialUrl?: string; fixedUrl?: boolean; pageLimit?: number}) {
  const form = useForm({ url: initialUrl });
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
            readOnly={fixedUrl}
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
        <p className="text-(--color-ink-muted)">
          {pageLimit != null
            ? `Проверка займёт 2–5 минут, проверим до ${pageLimit} страниц.`
            : 'Будет проверен доступный публичный HTML. Время проверки зависит от сайта и настроек сервера.'}
        </p>
        <p className="mt-3 break-all">Сайт: {form.data.url}</p>
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
export function BotAccess({ audit, domainStatus }: { audit: Audit; domainStatus?: {host: string; verified: boolean} | null }) {
  const { brand } = usePage<{brand: {id: string} | null}>().props;
  const access = audit.ai_access;
  const captcha = (access as typeof access & {captcha?: {indicators: string[]; pages_observed: number}})?.captcha;
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
      {domainStatus && <div className={`${card} lg:col-span-2`}><p className="text-sm">Домен {domainStatus.host}: {domainStatus.verified ? 'подтверждён' : 'не подтверждён'}</p>{!domainStatus.verified && brand && <Link className="mt-2 inline-flex min-h-11 cursor-pointer items-center text-sm text-(--color-link)" href={`/brands/${brand.id}/settings`}>Подтвердить домен →</Link>}</div>}
      <section className={card}>
        <h2 className="text-xl font-semibold">Правила robots.txt</h2>
        {access.bots?.length ? (
          <div className="mt-4 overflow-x-auto"><table className="w-full text-left text-sm"><thead><tr className="border-b border-(--color-border)"><th className="p-3">Бот</th><th className="p-3">Правило для проверенного адреса</th></tr></thead><tbody>{access.bots.map((bot) => <tr key={bot.user_agent} className="border-b border-(--color-border)"><td className="p-3"><strong>{bot.user_agent}</strong>{bot.role && <p className="mt-1 text-(--color-ink-muted)">{bot.role}</p>}</td><td className="p-3">{bot.allowed === true ? 'Разрешено' : bot.allowed === false ? 'Запрещено' : 'Нет достоверных данных'}{bot.note && <p className="mt-1 text-(--color-ink-muted)">{bot.note}</p>}</td></tr>)}</tbody></table></div>
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
              {file?.status == null ? 'Нет данных' : Number(file.status) >= 200 && Number(file.status) < 300 ? 'Найден' : [404, 410].includes(Number(file.status)) ? 'Не найден' : `Нет достоверных данных (HTTP ${file.status})`}
            </p>
          </section>
        ))}
        <section className={card}>
          <h2 className="text-lg font-semibold">Капча и антибот</h2>
          <p className="mt-2 text-(--color-ink-muted)">{captcha?.indicators?.length ? `Обнаружены признаки: ${captcha.indicators.join(', ')}` : captcha?.pages_observed ? 'На проверенных страницах явных признаков не найдено; защита может присутствовать.' : 'Нет данных'}</p>
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
  const evidenceFor = (page: AuditPage) => (audit.criteria || []).flatMap((criterion) => (criterion.evidence || []).filter((evidence) => evidence.url === page.url).map((evidence) => ({ criterion, evidence })));
  const problemsFor = (page: AuditPage) => evidenceFor(page).filter(({criterion}) => criterion.status === 'fail' || criterion.status === 'partial');
  const structuredFacts = (page: AuditPage) => (page as AuditPage & {structured_facts?: {label:string; value:string}[]}).structured_facts ?? [];
  const pageType = (page: AuditPage) => {
    if (page.url === (audit.final_url || audit.url)) return 'Главная';
    const types = (page as AuditPage & {structured_types?: string[]}).structured_types ?? [];
    if (types.includes('ContactPage')) return 'Контакты · разметка';
    if (types.includes('AboutPage')) return 'О компании · разметка';
    if (types.includes('CollectionPage') || types.includes('ItemList')) return 'Каталог · разметка';
    if (types.includes('Product')) return 'Товар · разметка';
    if (types.includes('Service')) return 'Услуга · разметка';
    for (const { evidence } of evidenceFor(page)) {
      try {
        const data = JSON.parse(evidence.excerpt || '');
        const types = Array.isArray(data?.['@type']) ? data['@type'] : [data?.['@type']];
        if (types.includes('ContactPage')) return 'Контакты · разметка';
        if (types.includes('AboutPage')) return 'О компании · разметка';
        if (types.includes('CollectionPage') || types.includes('ItemList')) return 'Каталог · разметка';
      } catch { /* This evidence is plain text, not structured markup. */ }
    }
    return 'Не определён';
  };
  const pages = (audit.pages || []).filter((page) =>
    `${page.url} ${page.primary_heading || ''}`
      .toLocaleLowerCase('ru')
      .includes(query.toLocaleLowerCase('ru')),
  );
  return (
    <section className={card}>
      <p className="mb-4 text-sm text-(--color-ink-muted)">Показаны только загруженные публичные страницы на момент проверки. Тип и факты основаны на доступной разметке JSON-LD; отсутствие данных не означает их отсутствия на сайте.</p>
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
        <div className="mt-4 overflow-x-auto">
          <table className="w-full min-w-[750px] text-left text-sm">
            <thead><tr className="border-b border-(--color-border)"><th className="p-3">URL</th><th className="p-3">Заголовок</th><th className="p-3">Тип</th><th className="p-3">Свидетельства</th><th className="p-3">HTTP</th><th className="p-3">Проблемы</th><th className="p-3">Проверено</th><th className="p-3">Подробнее</th></tr></thead>
            <tbody>{pages.map((page) => <tr key={page.url} className="border-b border-(--color-border)"><td className="break-all p-3">{page.url}</td><td className="p-3">{page.primary_heading || 'Без заголовка'}</td><td className="p-3">{pageType(page)}</td><td className="p-3">{evidenceFor(page).length + structuredFacts(page).length}</td><td className="p-3">{page.status ?? '—'}</td><td className="p-3">{problemsFor(page).length || '—'}</td><td className="p-3">{date(page.observed_at)}</td><td className="p-3"><button type="button" className={secondary} onClick={() => setSelected(page)} aria-label={`Подробнее: ${page.primary_heading || page.url}`}>Подробнее</button></td></tr>)}</tbody>
          </table>
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
              Тип: {pageType(selected)} · Проверено: {date(selected.observed_at)} · Статус: {selected.status ?? '—'}
            </p>
            {structuredFacts(selected).length > 0 && <div><h3 className="font-semibold">Заявлено в разметке страницы</h3><ul className="mt-2 space-y-1">{structuredFacts(selected).map((fact,index)=><li key={index}>{fact.label}: {fact.value}</li>)}</ul></div>}
            <div><h3 className="font-semibold">Извлечённые факты и свидетельства</h3>{evidenceFor(selected).length ? <ul className="mt-2 space-y-2">{evidenceFor(selected).map(({criterion, evidence}, index) => <li key={index} className="rounded-lg bg-(--color-surface-muted) p-3"><strong>{criterion.title || 'Критерий'}</strong><p>{evidence.excerpt || 'Нет выдержки'}</p><p className="text-sm text-(--color-ink-muted)">{labels[criterion.status || 'unknown'] || 'Нет данных'}</p></li>)}</ul> : <p className="mt-2 text-(--color-ink-muted)">Для этой страницы нет подтверждённых фактов или свидетельств.</p>}</div>
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
                {problemsFor(selected).length ? problemsFor(selected).map(({criterion}) => criterion.title || 'Критерий').join(' · ') : typeof selected.status === 'number' && selected.status >= 400 ? `Страница вернула HTTP ${selected.status}.` : 'Данные о проблемах страницы недоступны.'}
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

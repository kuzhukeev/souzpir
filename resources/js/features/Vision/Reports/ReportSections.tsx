type Sections = {
  competitors: { name: string; answers: number; is_brand: boolean }[];
  gaps: { text: string; intent: string }[];
  facts: { text: string; reason: string; provider: string }[];
  sources: { domain: string; answers: number }[];
  actions: { title: string; why: string }[];
  changes: { rows: { label: string; before: number; after: number; delta: number }[]; new_competitors: string[]; appeared: string[]; disappeared: string[] } | null;
};

const names: Record<string, string> = { choose: 'Выбор и сравнение', price: 'Цена', buy: 'Покупка', information: 'Информация' };
const box = 'mt-6 rounded-2xl border border-(--color-border) bg-(--color-surface) p-5 shadow-(--shadow-card)';
const empty = 'mt-3 text-sm text-(--color-ink-muted)';

export function ReportSections({ data }: { data: Sections }) {
  return <>
    <section id="competitors" className={box}><h2 className="text-xl font-bold">Кого называют вместо вас</h2>
      {data.competitors.length ? <ol className="mt-4 space-y-3">{data.competitors.map((item, index) => <li key={`${item.name}-${index}`} className="rounded-lg bg-(--color-surface-muted) p-3 text-sm"><div className="flex flex-wrap justify-between gap-2"><span>{item.name}{item.is_brand ? ' · Ваш бренд' : ''}</span><strong>{item.answers} упоминаний</strong></div><div aria-hidden="true" className="mt-2 h-2 rounded-full bg-(--color-border)"><div className="h-2 rounded-full bg-(--color-link)" style={{ width: `${100 * item.answers / Math.max(1, data.competitors[0].answers)}%` }} /></div></li>)}</ol> : <p className={empty}>Проверенных упоминаний пока нет.</p>}
    </section>
    <section id="gaps" className={box}><h2 className="text-xl font-bold">По каким вопросам вас нет</h2><p className={empty}>Показаны вопросы, по которым проверены все ответы и бренд не упомянут.</p>
      {data.gaps.length ? <ul className="mt-4 space-y-3">{data.gaps.map((item, index) => <li key={index} className="rounded-lg bg-(--color-surface-muted) p-3 text-sm"><span className="text-(--color-ink-muted)">{names[item.intent] ?? item.intent}</span><p className="mt-1 font-semibold">{item.text}</p></li>)}</ul> : <p className={empty}>Подтверждённых пробелов пока нет. Непроверенные ответы не считаются отсутствием бренда.</p>}
    </section>
    <section id="facts" className={box}><h2 className="text-xl font-bold">Что о вас говорят</h2>{data.facts.length ? <ul className="mt-4 space-y-3">{data.facts.map((item, index) => <li key={index} className="rounded-lg bg-(--color-surface-muted) p-3 text-sm"><p className="font-semibold">{item.text}</p>{item.reason && <p className="mt-1">{item.reason}</p>}{item.provider && <p className="mt-1 text-(--color-ink-muted)">Модель: {item.provider}</p>}</li>)}</ul> : <p className={empty}>Проверенных утверждений для этого отчёта пока нет.</p>}</section>
    <section id="sources" className={box}><h2 className="text-xl font-bold">Откуда нейросети берут информацию</h2>{data.sources.length ? <ul className="mt-4 divide-y divide-(--color-border)">{data.sources.map((item, index) => <li key={`${item.domain}-${index}`} className="flex flex-wrap justify-between gap-2 py-3 text-sm"><span>{item.domain}</span><span>{item.answers} цитирований · Присутствие не проверено</span></li>)}</ul> : <p className={empty}>Проверенных источников пока нет.</p>}</section>
    <section id="site" className={box}><h2 className="text-xl font-bold">Ваш сайт</h2><p className={empty}>Оценка сайта пока недоступна в снимке отчёта.</p></section>
    <section id="actions" className={box}><h2 className="text-xl font-bold">Три действия</h2>{data.actions.length ? <ol className="mt-4 grid gap-3 md:grid-cols-3">{data.actions.map((item, index) => <li key={index} className="rounded-xl bg-(--color-surface-muted) p-4 text-sm"><h3 className="font-semibold">{item.title}</h3><p className="mt-2">{item.why}</p></li>)}</ol> : <p className={empty}>Подтверждённых действий пока нет.</p>}</section>
    <section id="changes" className={box}><h2 className="text-xl font-bold">Что изменилось</h2>{data.changes ? <><p className={empty}>Сопоставлены снимки с одинаковыми вопросами, моделями и числом прогонов. Изменения профиля также могут влиять на результаты.</p>{data.changes.rows.length > 0 && <div className="mt-4 overflow-x-auto"><table className="w-full text-left text-sm"><thead><tr><th>Метрика</th><th>Было</th><th>Стало</th><th>Изменение</th></tr></thead><tbody>{data.changes.rows.map((row) => <tr key={row.label} className="border-t border-(--color-border)"><th className="py-3">{row.label}</th><td>{row.before}</td><td>{row.after}</td><td>{row.delta > 0 ? "+" : ""}{row.delta}</td></tr>)}</tbody></table></div>}<p className={empty}>Появились в ответах: {data.changes.appeared.length ? data.changes.appeared.join(", ") : "нет подтверждённых изменений"}.</p><p className={empty}>Пропали из ответов: {data.changes.disappeared.length ? data.changes.disappeared.join(", ") : "нет подтверждённых изменений"}.</p><p className={empty}>{data.changes.new_competitors.length ? `Новые компании: ${data.changes.new_competitors.join(", ")}` : "Новых компаний нет или недостаточно данных."}</p></> : <p className={empty}>Нет предыдущего сопоставимого отчёта. Показатели выше относятся к выбранному снимку.</p>}</section>
  </>;
}



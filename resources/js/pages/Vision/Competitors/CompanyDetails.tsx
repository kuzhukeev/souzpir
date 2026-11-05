type Question = { text: string; intent: string | null; models: string[]; model_names?: string[]; model_names_unknown?: boolean };
type Point = { date: string; answers: number };

export type CompanyDetail = {
  name: string;
  answers: number;
  delta?: number | null;
  share: number | null;
  position?: { average: number; count: number } | null;
  aliases?: string[];
  models?: string[];
  model_names?: string[];
  model_names_unknown?: boolean;
  model_positions?: {provider:string;model:string|null;position:{average:number;count:number}}[];
  intents?: string[];
  sources?: string[];
  source_ranking?: {domain: string; answers: number}[];
  quotes?: { text: string; provider: string }[];
  question_count?: number;
  question_total?: number;
  questions_without_brand?: Question[];
  history_series?: Point[];
};

const intents: Record<string, string> = {
  choose: 'Выбор и сравнение',
  price: 'Цена',
  buy: 'Покупка',
  information: 'Информация',
};

export default function CompanyDetails({
  company,
  own,
  site,
  onClose,
}: {
  company: CompanyDetail;
  own?: CompanyDetail;
  site?: string | null;
  onClose: () => void;
}) {
  const series = company.history_series?.length
    ? company.history_series
    : company.delta != null && company.answers - company.delta >= 0
      ? [
          { date: '', answers: company.answers - company.delta },
          { date: '', answers: company.answers },
        ]
      : [];
  const ownSeries = own?.history_series?.length
    ? own.history_series
    : own?.delta != null && own.answers - own.delta >= 0
      ? [
          { date: '', answers: own.answers - own.delta },
          { date: '', answers: own.answers },
        ]
      : [];
  const all = [...series, ...ownSeries];
  const max = Math.max(1, ...all.map((point) => point.answers));
  const points = (items: Point[]) =>
    items
      .map(
        (point, index) =>
          `${20 + (index * 220) / Math.max(1, items.length - 1)},${90 - (point.answers / max) * 70}`,
      )
      .join(' ');

  return (
    <div
      onKeyDown={(event) => {
        if (event.key === 'Escape') onClose();
      }}
      role="dialog"
      aria-label={`Карточка компании: ${company.name}`}
      className="fixed inset-y-0 right-0 z-50 w-full max-w-xl space-y-4 overflow-y-auto border-l border-(--color-border) bg-(--color-surface) p-6 text-sm font-normal shadow-2xl"
    >
      <div className="flex items-start justify-between gap-3">
        <h2 className="text-xl font-bold">{company.name}</h2>
        <button
          type="button"
          aria-label="Закрыть карточку"
          className="min-h-11 cursor-pointer rounded-lg border border-(--color-border) px-3"
          onClick={onClose}
        >
          Закрыть
        </button>
      </div>
      <div className="flex flex-wrap gap-2">
        {site && (
          <a
            href={site}
            target="_blank"
            rel="noopener noreferrer"
            className="cursor-pointer rounded-full bg-(--color-surface-muted) px-3 py-1 text-(--color-link)"
          >
            Сайт ↗
          </a>
        )}
        {(company.aliases ?? []).map((alias) => (
          <span key={alias} className="rounded-full bg-(--color-surface-muted) px-3 py-1">
            {alias}
          </span>
        ))}
      </div>
      <div className="grid gap-2 sm:grid-cols-2">
        <div className="rounded-lg bg-(--color-surface-muted) p-3">
          Упоминаний: <strong>{company.answers}</strong>
        </div>
        <div className="rounded-lg bg-(--color-surface-muted) p-3">
          Доля голоса:{' '}
          <strong>
            {company.share === null ? 'Нет данных' : `${company.share.toLocaleString('ru-RU')} %`}
          </strong>
        </div>
        <div className="rounded-lg bg-(--color-surface-muted) p-3">
          Средняя позиция: <strong>{company.position ? company.position.average.toLocaleString('ru-RU') : 'Нет данных'}</strong>
          {company.position && <p className="mt-1">Подтверждений: {company.position.count}</p>}
        </div>
        <div className="rounded-lg bg-(--color-surface-muted) p-3">
          В вопросах:{' '}
          <strong>
            {company.question_total
              ? `${company.question_count ?? 0} из ${company.question_total}`
              : 'Нет данных'}
          </strong>
        </div>
      </div>
      <p>Модели: {company.model_names?.join(', ') || 'Нет данных'}{company.model_names_unknown ? ' · часть моделей не определена' : ''}</p>{!!company.model_positions?.length && <ul className="mt-2 space-y-1 text-sm">{company.model_positions.map(item=><li key={item.provider}>{item.model ?? 'Модель не определена'} ({item.provider}): позиция {item.position.average} · подтверждений {item.position.count}</li>)}</ul>}
      <p>
        Намерения:{' '}
        {company.intents?.map((value) => intents[value] ?? value).join(', ') || 'Нет данных'}
      </p>
      <h3 className="font-semibold">Динамика по замерам</h3>
      <p>
        {company.delta == null
          ? 'Динамика пока недоступна.'
          : `Изменение числа ответов: ${company.delta > 0 ? '+' : ''}${company.delta}`}
      </p>
      {series.length >= 2 ? (
        <figure
          aria-label={`Динамика числа ответов: ${company.name} по ${series.length} сопоставимым замерам`}
          className="rounded-lg border border-(--color-border) p-3"
        >
          <svg
            viewBox="0 0 260 105"
            role="img"
            aria-label={`Динамика числа ответов по ${series.length} сопоставимым замерам`}
            className="h-32 w-full"
          >
            <line x1="20" y1="90" x2="240" y2="90" stroke="currentColor" opacity=".3" />
            <polyline
              points={points(series)}
              fill="none"
              stroke="var(--color-link)"
              strokeWidth="3"
            />
            {own && ownSeries.length === series.length && (
              <polyline
                points={points(ownSeries)}
                fill="none"
                stroke="var(--color-brand-primary)"
                strokeWidth="3"
                strokeDasharray="6 3"
              />
            )}
          </svg>
          <figcaption>
            {company.name}: {series.map((point) => point.answers).join(' → ')} ответов
            {own &&
              ownSeries.length === series.length &&
              ` · Ваш бренд: ${ownSeries.map((point) => point.answers).join(' → ')}`}
          </figcaption>
        </figure>
      ) : (
        <p>Сопоставимых замеров пока нет.</p>
      )}
      <h3 className="font-semibold">Вопросы, где его называют, а вас нет</h3>
      {company.questions_without_brand?.length ? (
        <ul className="space-y-2">
          {company.questions_without_brand.map((question, index) => (
            <li
              key={`${question.text}-${index}`}
              className="rounded-lg bg-(--color-surface-muted) p-3"
            >
              {question.text}
              <span className="mt-1 block text-(--color-ink-muted)">
                {question.intent
                  ? (intents[question.intent] ?? question.intent)
                  : 'Намерение не указано'}{' '}
                · {question.models.join(', ') || 'Модель не указана'}
              </span>
            </li>
          ))}
        </ul>
      ) : (
        <p>Подтверждённых вопросов без вашего бренда пока нет.</p>
      )}
      <h3 className="font-semibold">Что о компании говорят</h3>
      {company.quotes?.length ? (
        <ul className="space-y-2">
          {company.quotes.map((quote, index) => (
            <li key={index} className="rounded-lg bg-(--color-surface-muted) p-3">
              «{quote.text}» · {quote.provider || 'Модель не указана'}
            </li>
          ))}
        </ul>
      ) : (
        <p>Подтверждённые цитаты пока недоступны.</p>
      )}
      <h3 className="font-semibold">Источники</h3>
      {company.source_ranking?.length ? <div className="overflow-x-auto"><table className="w-full text-left text-sm"><thead><tr><th className="p-2">Источник</th><th className="p-2">Ответов</th></tr></thead><tbody>{company.source_ranking.map(source => <tr key={source.domain} className="border-t border-(--color-border)"><td className="p-2">{source.domain}</td><td className="p-2">{source.answers}</td></tr>)}</tbody></table></div> : <p>Нет данных об источниках.</p>}
    </div>
  );
}

import { useId, useState, type FormEvent } from 'react';
import { Plus, Trash2 } from 'lucide-react';
import type { Questionnaire, SiteHint } from '@/types/Vision/Profile';

const input =
  'w-full rounded-lg border border-(--color-border) bg-(--color-surface) px-3 py-2.5 text-sm text-(--color-ink) focus-visible:outline-2 focus-visible:outline-(--color-focus)';
const button =
  'inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-lg border border-(--color-border) px-3 py-2 text-sm text-(--color-link) hover:bg-(--color-surface-muted) focus-visible:outline-2 focus-visible:outline-(--color-focus) disabled:cursor-not-allowed disabled:opacity-50';
const label = 'block space-y-2 text-sm font-semibold text-(--color-ink)';

function TextField({
  title,
  value,
  onChange,
  type = 'text',
  placeholder,
  error,
}: {
  title: string;
  value: string;
  onChange: (value: string) => void;
  type?: string;
  placeholder?: string;
  error?: string;
}) {
  const errorId = useId();
  return (
    <div>
      <label className={label}>
        <span>{title}</span>
        <input
          className={input}
          type={type}
          aria-invalid={error ? true : undefined}
          aria-describedby={error ? errorId : undefined}
          value={value}
          placeholder={placeholder}
          onChange={(event) => onChange(event.target.value)}
        />
      </label>
      {error && (
        <span id={errorId} className="block text-sm text-red-800">
          {error}
        </span>
      )}
    </div>
  );
}
function ListField({
  title,
  values,
  onChange,
  maximum,
  hints = [],
  error,
}: {
  title: string;
  values: string[];
  onChange: (values: string[]) => void;
  maximum?: number;
  hints?: SiteHint[];
  error?: string;
}) {
  const errorId = useId();
  const [draft, setDraft] = useState('');
  const add = (value: string) => {
    const next = value.trim();
    if (next && !values.includes(next) && (maximum === undefined || values.length < maximum)) {
      onChange([...values, next]);
      setDraft('');
    }
  };
  const submit = (event: FormEvent) => {
    event.preventDefault();
    event.stopPropagation();
    add(draft);
  };
  return (
    <section aria-label={title} className="space-y-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h3 className="text-sm font-semibold">{title}</h3>
        {maximum !== undefined && (
          <span className="text-sm text-(--color-ink-muted)">
            {values.length} из {maximum}
          </span>
        )}
      </div>
      <ul className="flex flex-wrap gap-2">
        {values.map((value, index) => (
          <li
            key={`${index}-${value}`}
            className="flex items-center gap-1 rounded-lg bg-(--color-surface-muted) px-3 py-1 text-sm"
          >
            {value}
            <button
              type="button"
              aria-label={`Удалить: ${value}`}
              className="min-h-11 min-w-11 cursor-pointer text-(--color-link)"
              onClick={() => onChange(values.filter((_, i) => i !== index))}
            >
              ×
            </button>
          </li>
        ))}
      </ul>
      <div className="flex gap-2">
        <input
          aria-label={`Добавить: ${title}`}
          aria-invalid={error ? true : undefined}
          aria-describedby={error ? errorId : undefined}
          className={input}
          value={draft}
          onChange={(event) => setDraft(event.target.value)}
          onKeyDown={(event) => {
            if (event.key === 'Enter') submit(event as unknown as FormEvent);
          }}
        />
        <button
          type="button"
          aria-label={`Добавить в ${title}`}
          className={button}
          disabled={!draft.trim() || (maximum !== undefined && values.length >= maximum)}
          onClick={() => add(draft)}
        >
          <Plus size={18} />
          Добавить
        </button>
      </div>
      {error && (
        <p id={errorId} className="text-sm text-red-800">
          {error}
        </p>
      )}
      {hints.length > 0 && (
        <div className="space-y-2">
          <p className="text-sm text-(--color-ink-muted)">Подсказки с сайта</p>
          <div className="flex flex-wrap gap-2">
            {hints.map((hint, i) => (
              <button
                key={`${hint.value}-${i}`}
                type="button"
                className={button}
                onClick={() => add(hint.value)}
                disabled={
                  values.includes(hint.value) || (maximum !== undefined && values.length >= maximum)
                }
              >
                {hint.label}: {hint.value} <Plus size={16} />
              </button>
            ))}
          </div>
        </div>
      )}
    </section>
  );
}
const nameFields = [
  ['official_names', 'Официальные названия'],
  ['abbreviations', 'Сокращения'],
  ['transliterations', 'Транслитерация'],
  ['misspellings', 'Частые опечатки'],
  ['former_names', 'Бывшие названия'],
] as const;
const platformNames = [
  'Яндекс Карты',
  '2ГИС',
  'Google Business',
  'Авито',
  'Маркетплейсы',
  'Отраслевые каталоги',
  'Соцсети',
  'YouTube / Дзен',
  'Отзовики',
];

export function QuestionnaireFields({
  step,
  data,
  onChange,
  hints = [],
  errors = {},
  limits,
}: {
  step: number;
  data: Questionnaire;
  onChange: (data: Questionnaire) => void;
  hints?: SiteHint[];
  errors?: Record<string, string>;
  limits: { competitors?: number; facts?: number; questions?: number };
}) {
  const [editingFact, setEditingFact] = useState<number | null>(null);
  const audienceErrorId = useId();
  const priceErrorId = useId();
  const set = <K extends keyof Questionnaire>(key: K, value: Questionnaire[K]) =>
    onChange({ ...data, [key]: value });
  const text = (
    key: 'website' | 'brand_name' | 'legal_name' | 'founded_year',
    title: string,
    type?: string,
  ) => (
    <TextField
      title={title}
      type={type}
      value={data[key]}
      error={errors[key]}
      onChange={(value) => set(key, value)}
    />
  );
  return (
    <div className="space-y-6">
      {Object.keys(errors).length > 0 && (
        <div role="alert" className="rounded-lg bg-red-50 p-4 text-sm text-red-800">
          <p className="font-semibold">Проверьте данные</p>
          <ul className="list-inside list-disc">
            {Object.entries(errors).map(([key, value]) => (
              <li key={key}>{value}</li>
            ))}
          </ul>
        </div>
      )}
      {step === 1 && (
        <>
          {text('website', 'Сайт', 'url')}
          {text('brand_name', 'Название бренда')}
          {text('legal_name', 'Юридическое имя')}
          {text('founded_year', 'Год основания', 'number')}
          <ListField
            title="Города и регионы работы"
            values={data.regions}
            error={errors.regions}
            onChange={(v) => set('regions', v)}
          />
          <TextField
            title="Язык ответов"
            value={data.response_language}
            error={errors.response_language}
            onChange={(v) => set('response_language', v)}
          />
        </>
      )}
      {step === 2 && (
        <p className="text-sm text-(--color-ink-muted)">
          Добавьте минимум два варианта названия, включая домен вашего сайта без https://.
        </p>
      )}
      {step === 2 &&
        nameFields.map(([key, title]) => (
          <ListField
            key={key}
            title={title}
            values={data[key]}
            error={errors[key]}
            onChange={(v) => set(key, v)}
            hints={key === 'official_names' ? hints : []}
          />
        ))}
      {step === 3 && (
        <>
          <ListField
            title="Категории товаров и услуг"
            values={data.categories}
            error={errors.categories}
            onChange={(v) => set('categories', v)}
            hints={hints}
          />
          <ListField
            title="Главные продукты"
            values={data.products}
            error={errors.products}
            onChange={(v) => set('products', v)}
            maximum={3}
          />
          <fieldset>
            <legend className="mb-2 text-sm font-semibold">Тип предложения</legend>
            <div className="flex flex-wrap gap-3">
              {[
                ['goods', 'Товары'],
                ['services', 'Услуги'],
                ['software', 'Программное обеспечение'],
              ].map(([value, title]) => (
                <label key={value} className="flex cursor-pointer items-center gap-2 text-sm">
                  <input
                    type="radio"
                    name="offering"
                    checked={data.offering === value}
                    onChange={() => set('offering', value)}
                  />
                  {title}
                </label>
              ))}
            </div>
          </fieldset>
          <TextField
            title="Как покупатель описывает потребность"
            value={data.buyer_phrase}
            error={errors.buyer_phrase}
            onChange={(v) => set('buyer_phrase', v)}
            placeholder="Например, купить салют для праздника (без названия бренда)"
          />
          <TextField
            title="Категория: именительный падеж"
            value={data.category_nominative}
            error={errors['category_forms.nominative']}
            onChange={(v) => set('category_nominative', v)}
          />
          <TextField
            title="Категория: родительный падеж, множественное число"
            value={data.category_genitive_plural}
            error={errors['category_forms.genitive_plural']}
            onChange={(v) => set('category_genitive_plural', v)}
          />
          <fieldset>
            <legend className="mb-2 text-sm font-semibold">Кто покупает</legend>
            <div className="flex flex-wrap gap-3">
              {[
                ['b2b', 'B2B'],
                ['b2c', 'B2C'],
                ['both', 'Оба'],
              ].map(([value, title]) => (
                <label key={value} className="flex cursor-pointer items-center gap-2 text-sm">
                  <input
                    type="radio"
                    name="audience"
                    aria-invalid={errors.audience ? true : undefined}
                    aria-describedby={errors.audience ? audienceErrorId : undefined}
                    checked={data.audience === value}
                    onChange={() => set('audience', value)}
                  />
                  {title}
                </label>
              ))}
            </div>
          </fieldset>
          {errors.audience && (
            <p id={audienceErrorId} className="text-sm text-red-800">
              {errors.audience}
            </p>
          )}
          <ListField
            title="Типы покупателей"
            values={data.customer_types}
            error={errors.customer_types}
            onChange={(v) => set('customer_types', v)}
          />
          <fieldset>
            <legend className="mb-2 text-sm font-semibold">Ценовой сегмент</legend>
            <div className="flex flex-wrap gap-3">
              {[
                ['economy', 'Эконом'],
                ['middle', 'Средний'],
                ['premium', 'Премиум'],
              ].map(([value, title]) => (
                <label key={value} className="flex cursor-pointer items-center gap-2 text-sm">
                  <input
                    type="radio"
                    name="price_segment"
                    aria-invalid={errors.price_segment ? true : undefined}
                    aria-describedby={errors.price_segment ? priceErrorId : undefined}
                    checked={data.price_segment === value}
                    onChange={() => set('price_segment', value)}
                  />
                  {title}
                </label>
              ))}
            </div>
          </fieldset>
          {errors.price_segment && (
            <p id={priceErrorId} className="text-sm text-red-800">
              {errors.price_segment}
            </p>
          )}
          <ListField
            title="Чем отличаетесь"
            values={data.differentiators}
            error={errors.differentiators}
            onChange={(v) => set('differentiators', v)}
          />
        </>
      )}
      {step === 4 && (
        <section className="space-y-4">
          <div className="flex justify-between">
            <h3 className="font-semibold">Конкуренты</h3>
            <span className="text-sm text-(--color-ink-muted)">
              {data.competitors.length}
              {limits.competitors !== undefined ? ` из ${limits.competitors}` : ''}
            </span>
          </div>
          {hints.length > 0 && <div className="flex flex-wrap gap-2">{hints.map((hint, index) => <button key={`${hint.value}-${index}`} type="button" className={button} disabled={data.competitors.some(item => item.name === hint.value) || (limits.competitors !== undefined && data.competitors.length >= limits.competitors)} onClick={() => set('competitors', [...data.competitors, { name: hint.value, website: '', reason: '' }])}>Добавить из аудита: {hint.value}</button>)}</div>}
          {data.competitors.map((competitor, index) => (
            <div key={index} className="space-y-3 rounded-lg border border-(--color-border) p-4">
              <div className="flex justify-between">
                <span className="font-semibold">Конкурент {index + 1}</span>
                <button
                  type="button"
                  className={button}
                  aria-label={`Удалить конкурента ${index + 1}`}
                  onClick={() =>
                    set(
                      'competitors',
                      data.competitors.filter((_, i) => i !== index),
                    )
                  }
                >
                  <Trash2 size={18} />
                </button>
              </div>
              {(
                [
                  ['name', 'Название'],
                  ['website', 'Сайт (необязательно)'],
                  ['reason', 'Почему их выбирают'],
                ] as const
              ).map(([key, title]) => (
                <TextField
                  key={key}
                  title={title}
                  value={competitor[key]}
                  error={errors[`competitors.${index}.${key}`]}
                  onChange={(value) =>
                    set(
                      'competitors',
                      data.competitors.map((item, i) =>
                        i === index ? { ...item, [key]: value } : item,
                      ),
                    )
                  }
                />
              ))}
            </div>
          ))}
          {errors.competitors && (
            <p role="alert" className="text-sm text-red-800">
              {errors.competitors}
            </p>
          )}
          <button
            type="button"
            className={button}
            disabled={
              limits.competitors !== undefined && data.competitors.length >= limits.competitors
            }
            onClick={() =>
              set('competitors', [...data.competitors, { name: '', website: '', reason: '' }])
            }
          >
            <Plus size={18} />
            Добавить конкурента
          </button>
        </section>
      )}
      {step === 5 && (
        <>
          <section className="space-y-3">
            <h3 className="font-semibold">Данные с сайта</h3>
            {hints.length > 0 && <div className="flex flex-wrap gap-2">{hints.map((hint, index) => <button key={`${hint.value}-${index}`} type="button" className={button} disabled={data.site_facts.some(item => item.value === hint.value)} onClick={() => set('site_facts', [...data.site_facts, { label: hint.label, value: hint.value, decision: 'pending' }])}>Добавить факт: {hint.value}</button>)}</div>}
            {data.site_facts.length === 0 && (
              <p className="text-sm text-(--color-ink-muted)">
                Подсказок с сайта пока нет. Добавьте факты самостоятельно.
              </p>
            )}
            {data.site_facts.map((fact, index) => (
              <div key={index} className="space-y-2 rounded-lg border border-(--color-border) p-3">
                {editingFact === index ? <><TextField
                  title={fact.label}
                  value={fact.value}
                  error={errors[`site_facts.${index}.value`]}
                  onChange={(value) => set('site_facts', data.site_facts.map((item, i) => i === index ? { ...item, value } : item))}
                /><button type="button" className={button} onClick={() => setEditingFact(null)}>Готово</button></> : <><p className="text-sm font-semibold">{fact.label}</p><p className="text-sm">{fact.value}</p><button type="button" className={button} onClick={() => setEditingFact(index)}>Исправить</button></>}
                <div className="flex flex-wrap gap-2">
                  {(
                    [
                      ['confirmed', 'Верно'],
                      ['rejected', 'Не про нас'],
                    ] as const
                  ).map(([decision, title]) => (
                    <button
                      key={decision}
                      type="button"
                      aria-pressed={fact.decision === decision}
                      className={button}
                      onClick={() =>
                        set(
                          'site_facts',
                          data.site_facts.map((item, i) =>
                            i === index ? { ...item, decision } : item,
                          ),
                        )
                      }
                    >
                      {title}
                    </button>
                  ))}
                </div>
              </div>
            ))}
          </section>
          <ListField
            title="Дополнительные факты"
            values={data.facts}
            error={errors.facts}
            onChange={(v) => set('facts', v)}
            maximum={limits.facts}
          />
        </>
      )}
      {step === 6 && (
        <section className="space-y-3">
          <p className="text-sm text-(--color-ink-muted)">
            Укажите ссылки на активные профили вашей компании. Если площадки нет, оставьте поле
            пустым.
          </p>
          {platformNames.map((name) => {
            const existing = data.platforms.find((item) => item.name === name);
            return (
              <TextField
                key={name}
                title={name}
                type="url"
                value={existing?.url ?? ''}
                error={
                  errors[`platforms.${data.platforms.findIndex((item) => item.name === name)}.url`]
                }
                onChange={(url) =>
                  set(
                    'platforms',
                    url
                      ? [...data.platforms.filter((item) => item.name !== name), { name, url }]
                      : data.platforms.filter((item) => item.name !== name),
                  )
                }
              />
            );
          })}
          <h3 className="font-semibold">Другие площадки</h3>
          {data.platforms.map(
            (platform, index) =>
              !platformNames.includes(platform.name) && (
                <div
                  key={index}
                  className="space-y-3 rounded-lg border border-(--color-border) p-3"
                >
                  <TextField
                    title={`Название площадки ${index + 1}`}
                    value={platform.name}
                    error={errors[`platforms.${index}.name`]}
                    onChange={(name) =>
                      set(
                        'platforms',
                        data.platforms.map((item, i) => (i === index ? { ...item, name } : item)),
                      )
                    }
                  />
                  <TextField
                    title={`Ссылка площадки ${index + 1}`}
                    type="url"
                    value={platform.url}
                    error={errors[`platforms.${index}.url`]}
                    onChange={(url) =>
                      set(
                        'platforms',
                        data.platforms.map((item, i) => (i === index ? { ...item, url } : item)),
                      )
                    }
                  />
                  <button
                    type="button"
                    className={button}
                    onClick={() =>
                      set(
                        'platforms',
                        data.platforms.filter((_, i) => i !== index),
                      )
                    }
                  >
                    Удалить площадку {index + 1}
                  </button>
                </div>
              ),
          )}
          <button
            type="button"
            className={button}
            onClick={() => set('platforms', [...data.platforms, { name: '', url: '' }])}
          >
            <Plus size={18} />
            Добавить другую площадку
          </button>
        </section>
      )}
      {step === 7 && (
        <ListField
          title="Вопросы клиентов"
          values={data.questions}
          error={errors.questions}
          onChange={(v) => set('questions', v)}
          maximum={limits.questions}
          hints={hints}
        />
      )}
    </div>
  );
}

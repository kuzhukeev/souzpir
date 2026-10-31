import type { Questionnaire } from '@/types/Vision/Profile';
type Obj = Record<string, unknown>;
export type Answers = Record<string, Obj>;
const arr = (v: unknown): string[] =>
  Array.isArray(v) ? v.filter((x): x is string => typeof x === 'string') : [];
const str = (v: unknown): string => (typeof v === 'string' ? v : '');
const obj = (v: unknown): Obj =>
  v && typeof v === 'object' && !Array.isArray(v) ? (v as Obj) : {};
export function readAnswers(
  answers: Answers,
  brand: { name: string; website?: string | null },
): Questionnaire {
  const a = obj(answers.step1),
    b = obj(answers.step2),
    c = obj(answers.step3),
    d = obj(answers.step4),
    e = obj(answers.step5),
    f = obj(answers.step6),
    g = obj(answers.step7);
  const named = [
    'official_names',
    'abbreviations',
    'transliterations',
    'misspellings',
    'former_names',
  ].some((k) => Array.isArray(b[k]));
  return {
    website: str(a.site),
    brand_name: str(a.brand_name) || brand.name,
    legal_name: str(a.legal_name),
    founded_year: str(a.founded_year),
    regions: arr(a.regions),
    response_language: str(a.response_language) || 'Русский',
    official_names: named ? arr(b.official_names) : arr(b.aliases),
    abbreviations: arr(b.abbreviations),
    transliterations: arr(b.transliterations),
    misspellings: arr(b.misspellings),
    former_names: arr(b.former_names),
    categories: Array.isArray(c.categories)
      ? arr(c.categories)
      : str(c.category)
        ? [str(c.category)]
        : [],
    products: arr(c.products),
    audience: str(c.audience),
    offering: str(c.offering),
    buyer_phrase: str(c.buyer_phrase),
    category_nominative: str(obj(c.category_forms).nominative),
    category_genitive_plural: str(obj(c.category_forms).genitive_plural),
    customer_types: arr(c.customer_types),
    price_segment: str(c.price_segment),
    differentiators: arr(c.differentiators),
    competitors: Array.isArray(d.competitors)
      ? d.competitors.map((x) => {
          const r = obj(x);
          return { name: str(r.name), website: str(r.site), reason: str(r.reason) };
        })
      : [],
    facts: arr(e.facts),
    site_facts: Array.isArray(e.site_facts)
      ? e.site_facts.map((x) => {
          const r = obj(x);
          const decision =
            r.decision === 'confirmed' || r.decision === 'rejected' ? r.decision : 'pending';
          return { label: str(r.label), value: str(r.value), decision };
        })
      : [],
    platforms: Array.isArray(f.presence)
      ? f.presence.map((x) => {
          const r = obj(x);
          return { name: str(r.name), url: str(r.url) };
        })
      : [],
    questions: arr(g.questions),
  };
}
export function writeStep(step: number, d: Questionnaire): Obj {
  switch (step) {
    case 1:
      return {
        site: d.website || null,
        brand_name: d.brand_name,
        legal_name: d.legal_name,
        founded_year: d.founded_year,
        regions: d.regions,
        response_language: d.response_language,
      };
    case 2:
      return {
        aliases: [
          ...new Set([
            ...d.official_names,
            ...d.abbreviations,
            ...d.transliterations,
            ...d.misspellings,
            ...d.former_names,
          ]),
        ],
        official_names: d.official_names,
        abbreviations: d.abbreviations,
        transliterations: d.transliterations,
        misspellings: d.misspellings,
        former_names: d.former_names,
      };
    case 3:
      return {
        category: d.categories[0] || null,
        categories: d.categories,
        products: d.products,
        audience: d.audience,
        offering: d.offering,
        buyer_phrase: d.buyer_phrase,
        category_forms: {
          nominative: d.category_nominative,
          genitive_plural: d.category_genitive_plural,
        },
        customer_types: d.customer_types,
        price_segment: d.price_segment,
        differentiators: d.differentiators,
      };
    case 4:
      return {
        competitors: d.competitors.map(({ name, website, reason }) => ({
          name,
          site: website || null,
          reason,
        })),
      };
    case 5:
      return { facts: d.facts, site_facts: d.site_facts };
    case 6:
      return { presence: d.platforms };
    case 7:
      return { questions: d.questions };
    default:
      return {};
  }
}
export const labels = [
  'Сайт и бренд',
  'Как вас называют',
  'Что продаёте и кому',
  'Конкуренты',
  'Факты о компании',
  'Где вы представлены',
  'Вопросы клиентов',
  'Проверка и отправка',
];
const names: Record<string, string> = {
  site: 'Сайт',
  brand_name: 'Название бренда',
  legal_name: 'Юридическое имя',
  founded_year: 'Год основания',
  regions: 'Регионы',
  response_language: 'Язык',
  aliases: 'Варианты названия',
  official_names: 'Официальные названия',
  abbreviations: 'Сокращения',
  transliterations: 'Транслитерация',
  misspellings: 'Опечатки',
  former_names: 'Бывшие названия',
  category: 'Категория',
  categories: 'Категории',
  products: 'Продукты',
  offering: 'Тип предложения',
  audience: 'Аудитория',
  buyer_phrase: 'Фраза покупателя',
  category_forms: 'Формы категории',
  nominative: 'Именительный падеж',
  genitive_plural: 'Родительный падеж',
  customer_types: 'Типы покупателей',
  price_segment: 'Ценовой сегмент',
  differentiators: 'Отличия',
  competitors: 'Конкуренты',
  facts: 'Факты',
  site_facts: 'Факты с сайта',
  presence: 'Площадки',
  questions: 'Вопросы',
  name: 'Название',
  reason: 'Причина выбора',
  url: 'Ссылка',
  label: 'Описание',
  value: 'Значение',
  decision: 'Статус',
};
const display: Record<string, string> = {
  goods: 'Товары',
  services: 'Услуги',
  software: 'Программное обеспечение',
  b2b: 'B2B',
  b2c: 'B2C',
  both: 'B2B и B2C',
  pending: 'Не проверено',
  confirmed: 'Верно',
  rejected: 'Не про нас',
};
export function answerText(value: unknown): string {
  if (value === null || value === undefined || value === '') return 'Не указано';
  if (Array.isArray(value))
    return value.length ? value.map((item) => answerText(item)).join('; ') : 'Не указано';
  if (typeof value === 'object')
    return (
      Object.entries(value)
        .filter(([key]) => key in names)
        .map(([key, v]) => `${names[key]}: ${answerText(v)}`)
        .join('\n') || 'Не указано'
    );
  return display[String(value)] ?? String(value);
}

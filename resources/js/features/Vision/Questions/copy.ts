export const intents = {
  choose: 'Выбор и сравнение',
  price: 'Цена и бюджет',
  terms: 'Сроки и условия',
  trust: 'Доверие и отзывы',
  after: 'После покупки',
} as const;
export function dateUtc(value: string): string {
  const parsed = new Date(value.replace(' ', 'T').replace(/([+-]\d{2})$/, '$1:00'));
  return Number.isNaN(parsed.getTime())
    ? 'Дата недоступна'
    : new Intl.DateTimeFormat('ru-RU', {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: 'UTC',
      }).format(parsed) + ' UTC';
}
export function limit(value: string | null): string {
  return value === null ? 'не задан' : value;
}

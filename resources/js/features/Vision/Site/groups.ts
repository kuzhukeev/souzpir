import type { Criterion } from '@/types/Vision/Site';

// Группы и идентификаторы соответствуют фактическому составу SiteInspector.
const groups = [
  'Техническая доступность',
  'Структура предложения',
  'Коммерческие условия',
  'Доверие',
  'Понимание ИИ',
];
export function criterionGroup(item: Criterion): string {
  const id = typeof item.id === 'number' ? item.id : Number(item.id);
  return (
    (Number.isInteger(id) && id >= 1 && id <= 20 ? groups[Math.floor((id - 1) / 4)] : undefined) ||
    item.group ||
    'Другие критерии'
  );
}

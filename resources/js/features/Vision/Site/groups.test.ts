import { describe, expect, it } from 'vitest';
import { criterionGroup } from './groups';
describe('Группы проверок сайта', () => {
  it('относит проверки к группам по содержанию и сохраняет неизвестные группы', () => {
    expect(
      [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20].map((id) =>
        criterionGroup({ id, title: 'Проверка' }),
      ),
    ).toEqual([
      ...Array(4).fill('Техническая доступность'),
      ...Array(4).fill('Структура предложения'),
      ...Array(4).fill('Коммерческие условия'),
      ...Array(4).fill('Доверие'),
      ...Array(4).fill('Понимание ИИ'),
    ]);
    expect(criterionGroup({ id: 3, group: 'Старая группа' })).toBe('Техническая доступность');
    expect(criterionGroup({ id: 99, group: 'Старая группа' })).toBe('Старая группа');
    expect(criterionGroup({})).toBe('Другие критерии');
  });
});

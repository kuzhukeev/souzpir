import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, expect, it } from 'vitest';
import { SharedBrandModules } from './SharedBrandModules';

afterEach(cleanup);

it('показывает включённые модули отдельно для каждого общего бренда', () => {
  render(<SharedBrandModules brands={[
    { id: 'a', name: 'Первый бренд', modules: ['seller', 'vision'] },
    { id: 'b', name: 'Второй бренд', modules: [] },
    { id: 'c', name: 'Старый ответ' },
  ]} />);
  expect(screen.getByText('Продавец, Видение')).toBeTruthy();
  expect(screen.getByText('Нет включённых модулей')).toBeTruthy();
  expect(screen.getByText('Сведения недоступны')).toBeTruthy();
});

it('не делает выводов о чужих брендах без общих доступных брендов', () => {
  render(<SharedBrandModules brands={[]} />);
  expect(screen.getByText('Нет общих брендов для показа модулей')).toBeTruthy();
});

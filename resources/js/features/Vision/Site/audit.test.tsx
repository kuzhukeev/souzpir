import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { Groups, Score } from '@/pages/Vision/Site/Index';

afterEach(cleanup);

describe('Представление аудита сайта', () => {
  it('показывает интервал при неизвестных проверках и полноту данных', () => {
    render(
      <Score
        audit={{ url: 'https://example.test', readiness: { lower: 82, upper: 100, coverage: 82 } }}
      />,
    );
    expect(screen.getByText('82–100')).toBeTruthy();
    expect(screen.getByText('Проверено критериев: 82%')).toBeTruthy();
  });

  it('считает фактические группы по шкале из 20 с диапазоном неизвестных', () => {
    render(<Groups criteria={[
      { id: 1, title: 'Доступ', status: 'pass', score: 1 },
      { id: 2, title: 'Страницы', status: 'partial', score: 0.5 },
      { id: 3, title: 'Роботы', status: 'unknown', score: null },
      { id: 4, title: 'Текст', status: 'fail', score: 0 },
      { id: 17, title: 'Понимание', status: 'unknown', pending: true },
    ]} />);
    expect(screen.getByText('7.5–12.5 / 20 · проверено 3 из 4')).toBeTruthy();
    expect(screen.getByText('Скоро')).toBeTruthy();
  });

  it('группирует критерии по данным инспектора и не считает отложенные проверенными', () => {
    render(
      <Groups
        criteria={[
          { group: 'Техническая доступность', status: 'pass' },
          { group: 'Техническая доступность', status: 'unknown' },
          { group: 'Понимание ИИ', status: 'unknown', pending: true },
        ]}
      />,
    );
    expect(screen.getByText(/проверено 1 из 2/i)).toBeTruthy();
    expect(screen.getByText('Понимание ИИ')).toBeTruthy();
    expect(screen.getByText('Скоро')).toBeTruthy();
  });
});

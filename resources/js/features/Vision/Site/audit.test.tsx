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
    expect(screen.getByText('Проверено 1 из 2')).toBeTruthy();
    expect(screen.getByText('Понимание ИИ')).toBeTruthy();
    expect(screen.getByText('Скоро')).toBeTruthy();
  });
});

import { render, screen, cleanup } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import ConfirmationProgress from './ConfirmationProgress';

afterEach(cleanup);

describe('подтверждение снимка', () => {
  it('не называет готовым снимок после завершения замера', () => {
    render(
      <ConfirmationProgress
        status="completed"
        answers={[{ status: 'ok' }, { status: 'failed' }]}
      />,
    );
    expect(screen.getByText('Завершён')).toBeTruthy();
    expect(screen.getByText('Получено 1 из 2')).toBeTruthy();
    expect(screen.getByText('Пока недоступно')).toBeTruthy();
    expect(screen.getByText(/Снимок не подтверждён/)).toBeTruthy();
  });

  it('показывает отсутствие ответов у незавершённого замера', () => {
    render(<ConfirmationProgress status="pending" answers={[]} />);
    expect(screen.getByText('Не завершён')).toBeTruthy();
    expect(screen.getByText('Ответов пока нет')).toBeTruthy();
    expect(screen.getByText('Пока недоступно')).toBeTruthy();
  });
});

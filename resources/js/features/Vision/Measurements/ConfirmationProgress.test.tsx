import { render, screen, cleanup, fireEvent } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
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
    expect(screen.getByText('Не подтверждён')).toBeTruthy();
    expect(screen.getByText(/Снимок не подтверждён/)).toBeTruthy();
  });

  it('показывает отсутствие ответов у незавершённого замера', () => {
    render(<ConfirmationProgress status="pending" answers={[]} />);
    expect(screen.getByText('Не завершён')).toBeTruthy();
    expect(screen.getByText('Ответов пока нет')).toBeTruthy();
    expect(screen.getByText('Не подтверждён')).toBeTruthy();
  });

  it('позволяет подтвердить завершённый замер и показывает сохранённое состояние', () => {
    const confirm = vi.fn();
    const view = render(
      <ConfirmationProgress
        status="completed"
        answers={[{ status: 'ok' }]}
        canConfirm
        onConfirm={confirm}
      />,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Подтвердить снимок' }));
    expect(confirm).toHaveBeenCalledOnce();
    view.rerender(
      <ConfirmationProgress
        status="completed"
        answers={[{ status: 'ok' }]}
        confirmed
        canConfirm
        onConfirm={confirm}
      />,
    );
    expect(screen.getByText('Подтверждён')).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Подтвердить снимок' })).toBeNull();
  });
});

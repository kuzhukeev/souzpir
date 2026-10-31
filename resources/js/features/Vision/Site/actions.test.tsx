import { router } from '@inertiajs/react';
import { cleanup, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { Actions } from './Actions';

afterEach(() => {
  cleanup();
  vi.restoreAllMocks();
});

describe('Действия Видения', () => {
  const action = { id: '01ABC', title: 'Проверить сайт', status: 'open' };
  it('завершает действие только через форму с датой и результатом', async () => {
    const patch = vi.spyOn(router, 'patch').mockImplementation(() => {});
    const user = userEvent.setup();
    render(<Actions actions={[action]} canManage history={false} members={[]} />);
    await user.click(screen.getByRole('button', { name: 'Отметить выполненным' }));
    expect(patch).not.toHaveBeenCalled();
    const dialog = screen.getByRole('dialog', { name: 'Отметить выполненным' });
    const date = screen.getByLabelText('Дата выполнения');
    await user.type(date, '2026-10-31');
    await user.type(screen.getByLabelText('Что сделано'), 'Обновили страницу');
    await user.click(within(dialog).getByRole('button', { name: 'Отметить выполненным' }));
    expect(patch).toHaveBeenCalledWith(
      '/vision/actions/01ABC',
      expect.objectContaining({
        status: 'awaiting_measurement',
        completed_at: '2026-10-31',
        comment: 'Обновили страницу',
        evidence_url: '',
      }),
      expect.anything(),
    );
  });

  it('не показывает операции изменения без права управления', () => {
    render(<Actions actions={[action]} canManage={false} history={false} members={[]} />);
    expect(screen.queryByRole('button', { name: 'Отметить выполненным' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Удалить' })).toBeNull();
  });
  it('не выдаёт неподтверждённую хронологию или временное наблюдение за достижение', () => {
    render(
      <Actions
        actions={[]}
        canManage={false}
        history
        members={[]}
        achievements={[
          {
            code: 'confirmed_mention',
            title: 'Подтверждённое упоминание',
            earned_at: '2026-10-31',
          },
        ]}
        comparison={{
          status: 'paired',
          before: 25,
          after: 40,
          delta: 15,
          coverage: 80,
          paired_slots: 4,
        }}
      />,
    );
    expect(screen.getByText('Подтверждённое упоминание')).toBeTruthy();
    expect(screen.getByText('Наблюдения по проверенному отчёту')).toBeTruthy();
    expect(
      screen.getByText(/Порядок замеров пока не подтверждён/),
    ).toBeTruthy();
    expect(screen.queryByText(/последн|текущ/i)).toBeNull();
    expect(screen.queryByText('25% → 40%')).toBeNull();
    expect(screen.queryByText('31.10.2026')).toBeNull();
  });
  it('не подменяет отсутствие сопоставимых отчётов числовым эффектом', () => {
    render(
      <Actions
        actions={[]}
        canManage={false}
        history
        members={[]}
        comparison={{
          status: 'too_early',
          before: null,
          after: null,
          delta: null,
          coverage: null,
          paired_slots: 0,
        }}
      />,
    );
    expect(screen.getByText(/Рано судить: порядок и сопоставимость/)).toBeTruthy();
    expect(screen.queryByText(/0% → 0%/)).toBeNull();
  });
});

import { cleanup, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, expect, it, vi } from 'vitest';
import AttentionPreview from '../../../resources/js/features/Preview/AttentionPreview';

afterEach(cleanup);
const setup = () =>
  render(<AttentionPreview state="full" onStateChange={vi.fn()} theme="pyro" onNotice={vi.fn()} />);

it('filters the six demonstration events by module and text, then resets', async () => {
  const user = userEvent.setup();
  setup();
  expect(within(screen.getByRole('table')).getAllByTestId('attention-row')).toHaveLength(6);
  await user.click(screen.getByRole('combobox', { name: 'Модуль' }));
  await user.click(screen.getByRole('option', { name: 'Видение' }));
  expect(within(screen.getByRole('table')).getAllByTestId('attention-row')).toHaveLength(1);
  expect(within(screen.getByRole('table')).getByText('Замер готов к уточнениям')).toBeTruthy();
  await user.type(screen.getByLabelText('Поиск по событиям'), 'нет такого события');
  expect(screen.getByRole('heading', { name: 'Ничего не найдено' })).toBeTruthy();
  await user.click(screen.getByRole('button', { name: 'Сбросить фильтры' }));
  expect(within(screen.getByRole('table')).getAllByTestId('attention-row')).toHaveLength(6);
});

it('snoozes an event locally without sending a request', async () => {
  const user = userEvent.setup();
  const requests = vi.spyOn(globalThis, 'fetch');
  setup();
  const table = within(screen.getByRole('table'));
  await user.click(table.getByRole('button', { name: 'Действия: Диалоги ждут менеджера' }));
  await user.click(table.getByRole('button', { name: 'Отложить на 7 дней' }));
  expect(table.queryByText('Диалоги ждут менеджера')).toBeNull();
  expect(table.getAllByTestId('attention-row')).toHaveLength(5);
  expect(requests).not.toHaveBeenCalled();
});

it('offers a truthful retry for the explicit error state', async () => {
  const onStateChange = vi.fn();
  render(
    <AttentionPreview
      state="error"
      onStateChange={onStateChange}
      theme="neutral"
      onNotice={vi.fn()}
    />,
  );
  await userEvent.click(screen.getByRole('button', { name: 'Повторить' }));
  expect(onStateChange).toHaveBeenCalledWith('full');
});

it('renders a reduced-motion-aware loading skeleton', () => {
  render(
    <AttentionPreview state="loading" onStateChange={vi.fn()} theme="neutral" onNotice={vi.fn()} />,
  );
  expect(screen.getByRole('status').getAttribute('aria-busy')).toBe('true');
  expect(screen.getByLabelText('Модуль').hasAttribute('disabled')).toBe(true);
});

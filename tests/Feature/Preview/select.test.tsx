import { useState } from 'react';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, expect, it, vi } from 'vitest';
import { axe } from 'jest-axe';
import PreviewSelect from '../../../resources/js/features/Preview/PreviewSelect';

const options = [
  { value: 'all', label: 'Все модули' },
  { value: 'vision', label: 'Видение' },
  { value: 'seller', label: 'Продавец' },
  { value: 'workspace', label: 'Кабинет' },
];
afterEach(cleanup);

function Example({ onChange = vi.fn() }: { onChange?: (value: string) => void }) {
  const [value, setValue] = useState('all');
  return (
    <>
      <PreviewSelect
        label="Модуль"
        options={options}
        value={value}
        onChange={(next) => {
          setValue(next);
          onChange(next);
        }}
      />
      <button>Следующее поле</button>
    </>
  );
}

it('opens a portal listbox and chooses a labelled option without losing trigger focus', async () => {
  const user = userEvent.setup();
  const change = vi.fn();
  const { container } = render(<Example onChange={change} />);
  const trigger = screen.getByRole('combobox', { name: 'Модуль' });
  expect(trigger.getAttribute('aria-expanded')).toBe('false');
  await user.click(trigger);
  const list = screen.getByRole('listbox', { name: 'Модуль' });
  expect(container.contains(list)).toBe(false);
  expect(trigger.getAttribute('aria-controls')).toBe(list.id);
  expect(screen.getByRole('option', { name: 'Все модули' }).getAttribute('aria-selected')).toBe(
    'true',
  );
  await user.click(screen.getByRole('option', { name: 'Видение' }));
  expect(change).toHaveBeenCalledWith('vision');
  expect(trigger.textContent).toBe('Видение');
  expect(document.activeElement).toBe(trigger);
  expect(screen.queryByRole('listbox')).toBeNull();
});

it('supports arrows, Home/End and Enter/Space; Escape cancels the tentative choice', async () => {
  const user = userEvent.setup();
  const change = vi.fn();
  render(<Example onChange={change} />);
  const trigger = screen.getByRole('combobox');
  trigger.focus();
  await user.keyboard('{ArrowDown}{End}{ArrowUp}{Enter}');
  expect(change).toHaveBeenLastCalledWith('seller');
  await user.keyboard(' {Home}{ArrowDown}{Escape}');
  expect(change).toHaveBeenCalledTimes(1);
  expect(trigger.textContent).toBe('Продавец');
  expect(document.activeElement).toBe(trigger);
  await user.keyboard('{ArrowUp}{Home} ');
  expect(change).toHaveBeenLastCalledWith('all');
});

it('supports Russian typeahead while open and closed, including repeated-letter cycling', async () => {
  const user = userEvent.setup();
  render(<Example />);
  const trigger = screen.getByRole('combobox');
  trigger.focus();
  await user.keyboard('к');
  expect(trigger.textContent).toBe('Кабинет');
  await user.keyboard(' ');
  await user.keyboard('ви');
  expect(document.getElementById(trigger.getAttribute('aria-activedescendant')!)?.textContent).toBe(
    'Видение',
  );
  await user.keyboard('{Enter}');
  expect(trigger.textContent).toBe('Видение');
  await user.keyboard('в');
  expect(trigger.textContent).toBe('Все модули');
  await user.keyboard('в');
  expect(trigger.textContent).toBe('Видение');
});

it('Tab and outside interaction close without stealing the next control focus', async () => {
  const user = userEvent.setup();
  render(<Example />);
  const trigger = screen.getByRole('combobox');
  await user.click(trigger);
  await user.tab();
  expect(screen.queryByRole('listbox')).toBeNull();
  expect(document.activeElement).toBe(screen.getByRole('button', { name: 'Следующее поле' }));
  await user.click(trigger);
  await user.click(screen.getByRole('button', { name: 'Следующее поле' }));
  expect(screen.queryByRole('listbox')).toBeNull();
  expect(document.activeElement).toBe(screen.getByRole('button', { name: 'Следующее поле' }));
});

it('does not choose while disabled or empty, closes on disable and stays closed when re-enabled', async () => {
  const user = userEvent.setup();
  const change = vi.fn();
  const props = { label: 'Модуль', options, value: 'all', onChange: change };
  const { rerender } = render(<PreviewSelect {...props} />);
  await user.click(screen.getByRole('combobox'));
  rerender(<PreviewSelect {...props} disabled />);
  expect(screen.queryByRole('listbox')).toBeNull();
  await user.click(screen.getByRole('combobox'));
  expect(change).not.toHaveBeenCalled();
  rerender(<PreviewSelect {...props} />);
  expect(screen.queryByRole('listbox')).toBeNull();
  rerender(<PreviewSelect {...props} options={[]} />);
  expect((screen.getByRole('combobox') as HTMLButtonElement).disabled).toBe(true);
});

it('flips upward near the viewport edge and clamps horizontal placement', async () => {
  const user = userEvent.setup();
  render(<Example />);
  const trigger = screen.getByRole('combobox');
  vi.spyOn(trigger, 'getBoundingClientRect').mockReturnValue({
    x: 1000,
    y: 700,
    left: 1000,
    top: 700,
    right: 1100,
    bottom: 744,
    width: 100,
    height: 44,
    toJSON: () => ({}),
  });
  const size = vi.spyOn(HTMLElement.prototype, 'scrollHeight', 'get').mockReturnValue(200);
  await user.click(trigger);
  const list = screen.getByRole('listbox');
  expect(parseFloat(list.style.left) + parseFloat(list.style.width)).toBeLessThanOrEqual(
    window.innerWidth - 8,
  );
  expect(parseFloat(list.style.top)).toBeLessThan(700);
  expect(parseFloat(list.style.maxHeight)).toBeGreaterThan(200);
  fireEvent.scroll(window);
  size.mockRestore();
});

it('has no WCAG axe violations with the popup open', async () => {
  const user = userEvent.setup();
  render(<Example />);
  await user.click(screen.getByRole('combobox'));
  const result = await axe(document.body, {
    runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21aa'] },
  });
  expect(result.violations).toEqual([]);
});

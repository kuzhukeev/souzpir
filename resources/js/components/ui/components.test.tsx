import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import {
  Avatar,
  Badge,
  Breadcrumbs,
  Button,
  Card,
  Checkbox,
  Chip,
  CodeBlock,
  Combobox,
  DataTable,
  DatePicker,
  Drawer,
  EmptyState,
  FileDrop,
  FilterBar,
  Input,
  KpiCard,
  Modal,
  Pagination,
  ProgressBar,
  Radio,
  SegmentedControl,
  Select,
  Skeleton,
  StepProgress,
  Switch,
  Tabs,
  Textarea,
  Timeline,
  TimePicker,
  Toast,
  Tooltip,
} from './index';

afterEach(cleanup);
describe('form primitives', () => {
  it('Button exposes loading and disables actions', () => {
    render(<Button loading>Сохранение</Button>);
    expect(screen.getByRole('button').getAttribute('aria-busy')).toBe('true');
    expect((screen.getByRole('button') as HTMLButtonElement).disabled).toBe(true);
  });
  it('Input associates an error with its label', () => {
    render(<Input label="Название" error="Обязательно" />);
    const input = screen.getByLabelText('Название');
    expect(input.getAttribute('aria-invalid')).toBe('true');
    expect(input.getAttribute('aria-describedby')).toBe(screen.getByText('Обязательно').id);
  });
  it('Textarea exposes hint', () => {
    render(<Textarea label="Описание" hint="Подсказка" />);
    expect(screen.getByLabelText('Описание').getAttribute('aria-describedby')).toBe(
      screen.getByText('Подсказка').id,
    );
  });
  it('Select provides an accessible trigger and value', () => {
    render(
      <Select label="Статус" defaultValue="new" options={[{ value: 'new', label: 'Новый' }]} />,
    );
    expect(screen.getByRole('combobox', { name: 'Статус' })).toBeTruthy();
  });
  it('Combobox exposes its label', () => {
    render(<Combobox label="Город" options={['Москва']} />);
    expect(screen.getByRole('combobox', { name: 'Город' }).getAttribute('aria-expanded')).toBe(
      'false',
    );
  });
  it('DatePicker accepts dates', () => {
    render(<DatePicker label="Дата" />);
    expect(screen.getByLabelText('Дата').getAttribute('type')).toBe('date');
  });
  it('TimePicker accepts time', () => {
    render(<TimePicker label="Время" />);
    expect(screen.getByLabelText('Время').getAttribute('type')).toBe('time');
  });
  it('Checkbox toggles', async () => {
    render(<Checkbox label="Согласен" />);
    await userEvent.click(screen.getByLabelText('Согласен'));
    expect((screen.getByRole('checkbox') as HTMLInputElement).checked).toBe(true);
  });
  it('Radio follows native grouping', async () => {
    render(
      <>
        <Radio name="r" label="Один" />
        <Radio name="r" label="Два" />
      </>,
    );
    await userEvent.click(screen.getByLabelText('Два'));
    expect((screen.getByLabelText('Два') as HTMLInputElement).checked).toBe(true);
  });
  it('Switch reports change', async () => {
    const change = vi.fn();
    render(<Switch label="Включить" checked={false} onChange={change} />);
    await userEvent.click(screen.getByRole('switch'));
    expect(change).toHaveBeenCalledWith(true);
  });
  it('Chip exposes pressed state', () => {
    render(<Chip selected>Тег</Chip>);
    expect(screen.getByRole('button').getAttribute('aria-pressed')).toBe('true');
  });
});
describe('navigation primitives', () => {
  it('Tabs change panel with keyboard', async () => {
    function Example() {
      const [value, setValue] = useState('a');
      return (
        <Tabs
          label="Разделы"
          value={value}
          onChange={setValue}
          tabs={[
            { id: 'a', label: 'Один', content: 'Первый' },
            { id: 'b', label: 'Два', content: 'Второй' },
          ]}
        />
      );
    }
    render(<Example />);
    const first = screen.getByRole('tab', { name: 'Один' });
    first.focus();
    await userEvent.keyboard('{ArrowRight}');
    expect(screen.getByRole('tab', { name: 'Два' }).getAttribute('aria-selected')).toBe('true');
    expect(screen.getByRole('tabpanel').textContent).toBe('Второй');
  });
  it('SegmentedControl changes value', async () => {
    const change = vi.fn();
    render(
      <SegmentedControl
        label="Режим"
        value="a"
        onChange={change}
        options={[
          { value: 'a', label: 'А' },
          { value: 'b', label: 'Б' },
        ]}
      />,
    );
    await userEvent.click(screen.getByLabelText('Б'));
    expect(change).toHaveBeenCalledWith('b');
  });
  it('StepProgress marks current step', () => {
    render(<StepProgress steps={['А', 'Б']} current={1} />);
    expect(screen.getByText('Б').closest('li')?.getAttribute('aria-current')).toBe('step');
  });
  it('Breadcrumbs mark current page', () => {
    render(<Breadcrumbs items={[{ label: 'Главная', href: '/' }, { label: 'Текущая' }]} />);
    expect(screen.getByText('Текущая').getAttribute('aria-current')).toBe('page');
  });
  it('Pagination prevents leaving range', () => {
    render(<Pagination page={1} pages={2} onChange={() => {}} />);
    expect((screen.getByRole('button', { name: 'Назад' }) as HTMLButtonElement).disabled).toBe(
      true,
    );
  });
});
describe('content primitives', () => {
  it('Card renders content', () => {
    render(<Card>Карточка</Card>);
    expect(screen.getByText('Карточка')).toBeTruthy();
  });
  it('KpiCard displays label and value', () => {
    render(<KpiCard label="Всего" value={12} delta="За неделю" />);
    expect(screen.getByText('12')).toBeTruthy();
  });
  it('Badge displays status', () => {
    render(<Badge tone="danger">Ошибка</Badge>);
    expect(screen.getByText('Ошибка')).toBeTruthy();
  });
  it('Skeleton announces loading', () => {
    render(<Skeleton />);
    expect(screen.getByRole('status').getAttribute('aria-label')).toBe('Загрузка');
  });
  it('EmptyState exposes title and action', () => {
    render(<EmptyState title="Нет данных" action={<button>Добавить</button>} />);
    expect(screen.getByRole('heading', { name: 'Нет данных' })).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Добавить' })).toBeTruthy();
  });
  it('ProgressBar clamps values', () => {
    render(<ProgressBar label="Готовность" value={120} max={100} />);
    expect((screen.getByRole('progressbar') as HTMLProgressElement).value).toBe(100);
  });
  it('Avatar exposes the full name', () => {
    render(<Avatar name="Иван Петров" />);
    expect(screen.getByRole('img', { name: 'Иван Петров' }).textContent).toBe('ИП');
  });
  it('Timeline preserves event order', () => {
    render(
      <Timeline
        entries={[
          { id: '1', title: 'Первый', time: 'Сегодня' },
          { id: '2', title: 'Второй', time: 'Завтра' },
        ]}
      />,
    );
    expect(
      within(screen.getByRole('list', { name: 'Хронология' }))
        .getAllByRole('listitem')
        .map((x) => x.textContent),
    ).toEqual(['ПервыйСегодня', 'ВторойЗавтра']);
  });
  it('CodeBlock copies requested code', async () => {
    const user = userEvent.setup();
    const write = vi.spyOn(navigator.clipboard, 'writeText').mockResolvedValue();
    render(<CodeBlock code="example" />);
    await user.click(screen.getByRole('button', { name: 'Копировать' }));
    expect(write).toHaveBeenCalledWith('example');
    expect(screen.getByRole('status').textContent).toBe('Скопировано');
  });
  it('Tooltip exposes its trigger', () => {
    render(<Tooltip label="Информация">Подробнее</Tooltip>);
    expect(screen.getByRole('button', { name: 'Подробнее' })).toBeTruthy();
  });
  it('DataTable renders data and sorts', async () => {
    render(
      <DataTable
        label="Таблица"
        rows={[
          { id: '1', name: 'Б' },
          { id: '2', name: 'А' },
        ]}
        columns={[{ key: 'name', label: 'Название', sortable: true }]}
      />,
    );
    await userEvent.click(screen.getByRole('button', { name: /Название/ }));
    const rows = within(screen.getByRole('table')).getAllByRole('row');
    expect(rows[1].textContent).toBe('А');
  });
  it('FilterBar names its group', () => {
    render(
      <FilterBar>
        <button>Сброс</button>
      </FilterBar>,
    );
    expect(screen.getByRole('group', { name: 'Фильтры' })).toBeTruthy();
  });
  it('FileDrop returns selected files', () => {
    const change = vi.fn();
    render(<FileDrop label="Файл" onFiles={change} />);
    const file = new File(['hello'], 'test.txt', { type: 'text/plain' });
    fireEvent.change(screen.getByLabelText('Файл'), { target: { files: [file] } });
    expect(change).toHaveBeenCalledWith([file]);
  });
  it('Toast announces text and exposes close', async () => {
    const close = vi.fn();
    render(<Toast message="Сохранено" onClose={close} />);
    expect(screen.getByRole('status').textContent).toContain('Сохранено');
    await userEvent.click(screen.getByRole('button', { name: 'Закрыть уведомление' }));
    expect(close).toHaveBeenCalled();
  });
  it.each([
    ['Modal', Modal],
    ['Drawer', Drawer],
  ] as const)('%s shows an accessible dialog', (_name, Component) => {
    render(
      <Component open title="Заголовок" onClose={() => {}}>
        Содержимое
      </Component>,
    );
    expect(screen.getByRole('dialog', { name: 'Заголовок' })).toBeTruthy();
  });
});

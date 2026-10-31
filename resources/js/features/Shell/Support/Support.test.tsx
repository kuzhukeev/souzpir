import { act, cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import type { ReactNode } from 'react';
import { router } from '@inertiajs/react';
import Help from '@/pages/Shell/Support/Help';
import Status from '@/pages/Shell/Support/Status';
import Tickets from '@/pages/Shell/Support/Tickets';
import { ErrorSummary } from './Shared';
import type { HelpData, StatusData, TicketsData } from '@/types/Shell/Support';
vi.mock('@inertiajs/react', () => ({
  usePage: () => ({ props: { translations: {} } }),
  Head: () => null,
  Link: ({ href, children }: { href: string; children: ReactNode }) => (
    <a href={href}>{children}</a>
  ),
  router: { get: vi.fn(), reload: vi.fn() },
}));
vi.mock('@/layouts/CabinetLayout', () => ({
  default: ({ children }: { children: ReactNode }) => <main>{children}</main>,
}));
afterEach(cleanup);
const help: HelpData = {
  configured: false,
  knowledge_base_url: null,
  sections: [{ id: 'start', title: 'Начало работы', description: '', url: null }],
  articles: [],
};
it('keeps unconfigured help tiles honest and disables search without published materials', () => {
  render(<Help help={help} />);
  expect((screen.getByLabelText('Поиск по разделам и статьям') as HTMLInputElement).disabled).toBe(
    true,
  );
  expect(screen.queryByRole('link', { name: /Начало работы/ })).toBeNull();
  expect(screen.getByText('Статьи пока не опубликованы')).toBeTruthy();
});
it('searches configured titles and preserves a protected external link', () => {
  render(
    <Help
      help={{
        ...help,
        configured: true,
        sections: [{ ...help.sections[0], url: 'https://docs.example.test/start' }],
        articles: [
          {
            id: 'widget',
            title: 'Настройка виджета',
            section_id: 'start',
            url: 'https://docs.example.test/widget',
          },
        ],
      }}
    />,
  );
  const link = screen.getByRole('link', { name: /Настройка виджета/ });
  expect(link.getAttribute('rel')).toContain('noopener');
  expect(link.getAttribute('target')).toBe('_blank');
  fireEvent.change(screen.getByLabelText('Поиск по разделам и статьям'), {
    target: { value: 'несуществующий' },
  });
  expect(screen.getByRole('heading', { name: 'Ничего не найдено' })).toBeTruthy();
  expect(screen.queryByRole('link', { name: /Настройка виджета/ })).toBeNull();
});
const status: StatusData = {
  checked_at: '2026-10-31T12:00:00Z',
  health_url: '/up',
  components: [],
  providers: {
    available: false,
    items: [
      {
        id: 'configured',
        name: 'Настроенный провайдер',
        status: 'planned',
        cooldown_until: null,
        checked_at: null,
        source: 'configuration',
      },
    ],
    message: 'Настройка не подтверждает доступность.',
  },
  incidents: { available: false, data: [] },
};
it('does not equate a configured provider or absent incident history with a healthy service', () => {
  render(<Status status={status} />);
  expect(screen.getByText('Запланирован')).toBeTruthy();
  expect(screen.queryByText('Доступно')).toBeNull();
  expect(screen.getByText('История инцидентов пока недоступна')).toBeTruthy();
  expect(screen.queryByText('Инцидентов нет')).toBeNull();
});
it('shows an actual provider cooldown deadline when the backend supplies one', () => {
  render(
    <Status
      status={{
        ...status,
        providers: {
          ...status.providers,
          available: true,
          items: [
            {
              id: 'limited',
              name: 'Провайдер с паузой',
              status: 'cooldown',
              cooldown_until: '2026-11-01T09:00:00Z',
              checked_at: null,
              source: 'runtime',
            },
          ],
        },
      }}
    />,
  );
  expect(screen.getByText('Временная пауза')).toBeTruthy();
  expect(document.querySelector('time[datetime="2026-11-01T09:00:00Z"]')).toBeTruthy();
});
it('reveals a new error but does not steal focus on an unrelated render', () => {
  const previous = HTMLElement.prototype.scrollIntoView;
  const scroll = vi.fn();
  HTMLElement.prototype.scrollIntoView = scroll;
  try {
    const failure = { message: 'Ошибка отправки' };
    const view = render(
      <>
        <input aria-label="Поле" />
        <ErrorSummary failure={failure} focusOnChange />
      </>,
    );
    expect(document.activeElement).toBe(screen.getByRole('alert'));
    expect(scroll).toHaveBeenCalledWith({ block: 'nearest' });
    screen.getByLabelText('Поле').focus();
    view.rerender(
      <>
        <input aria-label="Поле" />
        <ErrorSummary failure={failure} focusOnChange />
      </>,
    );
    expect(document.activeElement).toBe(screen.getByLabelText('Поле'));
    expect(scroll).toHaveBeenCalledTimes(1);
  } finally {
    HTMLElement.prototype.scrollIntoView = previous;
  }
});
it('offers no forbidden submission action in readonly support mode', () => {
  const support: TicketsData = {
    can_submit: false,
    submit_unavailable_reason: null,
    tickets: { data: [], current_page: 1, last_page: 1, per_page: 20, total: 0 },
    topics: [{ value: 'question', label: 'Вопрос' }],
    modules: [{ value: 'cabinet', label: 'Кабинет' }],
    upload: { max_files: 3, max_file_kb: 5120, accept: ['image/png'] },
    access: { duration_hours: 72, entry_available: false },
    delivery: { configured: false, enabled: false },
  };
  render(<Tickets support={support} />);
  expect(screen.queryByRole('button', { name: 'Отправить обращение' })).toBeNull();
  expect((screen.getByLabelText('Описание') as HTMLTextAreaElement).disabled).toBe(true);
  expect(screen.getByText(/Создание обращений временно недоступно/)).toBeTruthy();
});

it('keeps an expanded ticket and keyboard focus while refreshing its revoked consent', () => {
  const support: TicketsData = {
    can_submit: true,
    submit_unavailable_reason: null,
    tickets: {
      data: [
        {
          id: 'ticket-refresh',
          subject: 'question',
          subject_label: 'Вопрос',
          module: 'cabinet',
          module_label: 'Кабинет',
          body: 'Проверка доступа к кабинету',
          status: 'open',
          created_at: '2026-10-31T12:00:00Z',
          delivery_status: 'disabled',
          attachments: [],
          access: {
            id: 'consent-refresh',
            granted_at: '2026-10-31T12:00:00Z',
            expires_at: '2026-11-03T12:00:00Z',
            revoked_at: null,
            status: 'active',
            can_revoke: true,
          },
        },
      ],
      current_page: 1,
      last_page: 1,
      per_page: 20,
      total: 1,
    },
    topics: [{ value: 'question', label: 'Вопрос' }],
    modules: [{ value: 'cabinet', label: 'Кабинет' }],
    upload: { max_files: 3, max_file_kb: 5120, accept: ['image/png'] },
    access: { duration_hours: 72, entry_available: false },
    delivery: { configured: false, enabled: false },
  };
  const view = render(<Tickets support={support} />);
  const card = document.querySelector<HTMLDetailsElement>('[data-ticket-id="ticket-refresh"]')!;
  const summary = card.querySelector('summary')!;
  fireEvent.click(summary);
  summary.focus();
  expect(card.open).toBe(true);
  fireEvent.click(screen.getByRole('button', { name: 'Обновить' }));
  const callbacks = vi.mocked(router.get).mock.calls.at(-1)?.[2];
  act(() => callbacks?.onStart?.({} as never));
  expect(document.querySelector('[data-ticket-id="ticket-refresh"]')).toBe(card);
  expect(card.open).toBe(true);
  expect(document.activeElement).toBe(summary);
  expect(
    (screen.getByRole('button', { name: 'Отозвать согласие' }) as HTMLButtonElement).disabled,
  ).toBe(true);
  const ticket = support.tickets.data[0];
  view.rerender(
    <Tickets
      support={{
        ...support,
        tickets: {
          ...support.tickets,
          data: [
            {
              ...ticket,
              access: {
                ...ticket.access!,
                status: 'revoked',
                can_revoke: false,
                revoked_at: '2026-10-31T12:05:00Z',
              },
            },
          ],
        },
      }}
    />,
  );
  act(() => callbacks?.onFinish?.({} as never));
  expect(card.open).toBe(true);
  expect(document.activeElement).toBe(summary);
  expect(screen.getByText('Отозвано', { selector: 'p' })).toBeTruthy();
  expect(screen.queryByRole('button', { name: 'Отозвать согласие' })).toBeNull();
});

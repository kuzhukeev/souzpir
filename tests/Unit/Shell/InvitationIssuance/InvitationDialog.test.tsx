import { act, cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import InvitationDialog, {
  type InvitationDialogProps,
} from '@/features/Shell/InvitationIssuance/InvitationDialog';

const state = vi.hoisted(() => ({
  listener: null as null | (() => void),
  stable: true,
  version: 'fixture-inertia-version',
  navigation: null as null | (() => void),
}));
vi.mock('@/features/Shell/Profile/contextFence', () => ({
  profileContextIsStable: () => state.stable,
  subscribeProfileContext: (listener: () => void) => {
    state.listener = listener;
    return () => {
      state.listener = null;
    };
  },
}));
vi.mock('@inertiajs/react', () => ({
  usePage: () => ({ version: state.version }),
  router: {
    on: (_event: string, listener: () => void) => {
      state.navigation = listener;
      return () => {
        state.navigation = null;
      };
    },
  },
}));

const brand = '01J00000000000000000000001';
const revision = 'a'.repeat(64);
const token = 'A'.repeat(64);
const link = `http://souzpir.local/invitations/open#${token}`;
const props: InvitationDialogProps = {
  open: true,
  onClose: vi.fn(),
  canIssue: true,
  brandId: brand,
  revision,
  brands: [{ id: brand, name: 'Тестовый бренд' }],
};
const checked = (permission: unknown = true, manage: boolean = true) =>
  new Response(
    JSON.stringify({
      version: state.version,
      props: {
        teamBrandRevision: revision,
        canViewRoles: manage,
        ...(permission === null ? {} : { canIssueInvitations: permission }),
        brand: { id: brand },
        filterBrands: [{ id: brand }],
      },
    }),
    { status: 200, headers: { 'Content-Type': 'application/json' } },
  );
const issued = () =>
  new Response(JSON.stringify({ link }), {
    status: 201,
    headers: { 'Content-Type': 'application/json' },
  });

beforeEach(() => {
  state.stable = true;
  state.version = 'fixture-inertia-version';
  document.cookie = 'XSRF-TOKEN=test%2Bcsrf; path=/';
});
afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

function prepare() {
  fireEvent.change(screen.getByLabelText('Электронная почта'), {
    target: { value: 'person@example.test' },
  });
  fireEvent.click(screen.getByRole('button', { name: 'Продолжить' }));
  fireEvent.click(screen.getByRole('button', { name: 'Подтвердить выпуск' }));
}

it('issues once with CSRF and revision, never places the bearer in history or storage, and offers manual copy', async () => {
  const fetcher = vi.fn().mockResolvedValueOnce(issued()).mockResolvedValueOnce(checked());
  vi.stubGlobal('fetch', fetcher);
  const historyBefore = JSON.stringify(history.state);
  const storageBefore = JSON.stringify(localStorage);
  render(<InvitationDialog {...props} />);
  prepare();
  const field = (await screen.findByLabelText('Ссылка приглашения')) as HTMLTextAreaElement;
  expect(field.value).toBe(link);
  const [path, request] = fetcher.mock.calls[0];
  expect(path).toBe('/team/invitations');
  expect(request.headers['X-Brand-Revision']).toBe(revision);
  expect(request.headers['X-XSRF-TOKEN']).toBe('test+csrf');
  expect(fetcher.mock.calls[1][1].headers['X-Inertia-Version']).toBe(state.version);
  expect(JSON.parse(request.body)).toMatchObject({
    email: 'person@example.test',
    role: 'viewer',
    brand_ids: [brand],
    sales_manager: false,
  });
  expect(JSON.parse(request.body).attempt_key).toMatch(/^[a-f0-9]{64}$/);
  expect(JSON.stringify(history.state)).toBe(historyBefore);
  expect(JSON.stringify(localStorage)).toBe(storageBefore);
  fireEvent.click(screen.getByRole('button', { name: 'Копировать ссылку' }));
  expect(await screen.findByText('Выделите ссылку и скопируйте вручную.')).toBeTruthy();
  expect(field.selectionStart).toBe(0);
  expect(field.selectionEnd).toBe(link.length);
});

it('does not retry an unknown result without acknowledgement and a new confirmation', async () => {
  const fetcher = vi.fn().mockRejectedValue(new TypeError('network'));
  vi.stubGlobal('fetch', fetcher);
  render(<InvitationDialog {...props} />);
  prepare();
  expect(await screen.findByText(/Результат неизвестен/)).toBeTruthy();
  expect(fetcher).toHaveBeenCalledTimes(1);
  fireEvent.click(screen.getByRole('button', { name: 'Понятно, создать ещё' }));
  expect(fetcher).toHaveBeenCalledTimes(1);
  fireEvent.click(screen.getByRole('button', { name: 'Продолжить' }));
  expect(fetcher).toHaveBeenCalledTimes(1);
  fireEvent.click(screen.getByRole('button', { name: 'Подтвердить выпуск' }));
  await waitFor(() => expect(fetcher).toHaveBeenCalledTimes(2));
  expect(JSON.parse(fetcher.mock.calls[0][1].body).attempt_key).not.toBe(
    JSON.parse(fetcher.mock.calls[1][1].body).attempt_key,
  );
});

it('erases the bearer when the context is revoked or the dialog is closed', async () => {
  vi.stubGlobal('fetch', vi.fn().mockResolvedValueOnce(issued()).mockResolvedValueOnce(checked()));
  const { rerender } = render(<InvitationDialog {...props} />);
  prepare();
  const field = (await screen.findByLabelText('Ссылка приглашения')) as HTMLTextAreaElement;
  act(() => state.listener?.());
  expect(screen.queryByLabelText('Ссылка приглашения')).toBeNull();
  expect(field.value).toBe('');
  rerender(<InvitationDialog {...props} open={false} />);
  expect(screen.queryByText(token)).toBeNull();
});

it('clears the detached input on pagehide', async () => {
  vi.stubGlobal('fetch', vi.fn().mockResolvedValueOnce(issued()).mockResolvedValueOnce(checked()));
  render(<InvitationDialog {...props} />);
  prepare();
  const field = (await screen.findByLabelText('Ссылка приглашения')) as HTMLTextAreaElement;
  act(() => window.dispatchEvent(new Event('pagehide')));
  expect(field.value).toBe('');
  expect(screen.queryByLabelText('Ссылка приглашения')).toBeNull();
});

it('copies only on an explicit click when the browser clipboard is available', async () => {
  vi.stubGlobal('fetch', vi.fn().mockResolvedValueOnce(issued()).mockResolvedValueOnce(checked()));
  const writeText = vi.fn().mockResolvedValue(undefined);
  const previous = Object.getOwnPropertyDescriptor(navigator, 'clipboard');
  Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText } });
  try {
    render(<InvitationDialog {...props} />);
    prepare();
    await screen.findByLabelText('Ссылка приглашения');
    expect(writeText).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole('button', { name: 'Копировать ссылку' }));
    await screen.findByText('Ссылка скопирована.');
    expect(writeText).toHaveBeenCalledOnce();
    expect(writeText).toHaveBeenCalledWith(link);
  } finally {
    if (previous) Object.defineProperty(navigator, 'clipboard', previous);
    else Reflect.deleteProperty(navigator, 'clipboard');
  }
});

it('ignores a late success after brand context changes', async () => {
  let resolve!: (value: Response) => void;
  const fetcher = vi.fn().mockImplementation(
    () =>
      new Promise<Response>((done) => {
        resolve = done;
      }),
  );
  vi.stubGlobal('fetch', fetcher);
  const { rerender } = render(<InvitationDialog {...props} />);
  prepare();
  rerender(<InvitationDialog {...props} revision={'b'.repeat(64)} />);
  await act(async () => {
    resolve(issued());
  });
  expect(screen.queryByLabelText('Ссылка приглашения')).toBeNull();
  expect(screen.getByText(/Результат неизвестен/)).toBeTruthy();
  expect(fetcher).toHaveBeenCalledTimes(1);
});

it('does not reveal a link if server-side context revalidation fails', async () => {
  vi.stubGlobal(
    'fetch',
    vi
      .fn()
      .mockResolvedValueOnce(issued())
      .mockResolvedValueOnce(new Response('', { status: 403 })),
  );
  render(<InvitationDialog {...props} />);
  prepare();
  expect(await screen.findByText(/Результат неизвестен/)).toBeTruthy();
  expect(screen.queryByLabelText('Ссылка приглашения')).toBeNull();
});

it('shows a link with issuance permission even without role-management permission', async () => {
  vi.stubGlobal('fetch', vi.fn().mockResolvedValueOnce(issued()).mockResolvedValueOnce(checked(true, false)));
  render(<InvitationDialog {...props} />);
  prepare();
  expect((await screen.findByLabelText('Ссылка приглашения') as HTMLTextAreaElement).value).toBe(link);
});

it.each([
  ['revoked even when role-management permission remains', false],
  ['missing from the server response', null],
  ['not a boolean', 'true'],
])('does not show a link when issuance permission is %s', async (_label, permission) => {
  vi.stubGlobal('fetch', vi.fn().mockResolvedValueOnce(issued()).mockResolvedValueOnce(checked(permission, true)));
  render(<InvitationDialog {...props} />);
  prepare();
  expect(await screen.findByText(/Результат неизвестен/)).toBeTruthy();
  expect(screen.queryByLabelText('Ссылка приглашения')).toBeNull();
});

it('erases a visible link when issuance permission is revoked during revalidation', async () => {
  vi.stubGlobal('fetch', vi.fn().mockResolvedValueOnce(issued()).mockResolvedValueOnce(checked(true, true)).mockResolvedValueOnce(checked(false, true)));
  render(<InvitationDialog {...props} />);
  vi.useFakeTimers();
  try {
    prepare();
    await act(async () => { await Promise.resolve(); await Promise.resolve(); await Promise.resolve(); });
    const field = screen.getByLabelText('Ссылка приглашения') as HTMLTextAreaElement;
    await act(async () => { await vi.advanceTimersByTimeAsync(5_001); });
    expect(screen.queryByLabelText('Ссылка приглашения')).toBeNull();
    expect(field.value).toBe('');
  } finally {
    vi.useRealTimers();
  }
});

it.each([
  () => new Response('', { status: 409 }),
  () => new Response(JSON.stringify({ version: 'different', props: { teamBrandRevision: revision, canIssueInvitations: true, brand: { id: brand }, filterBrands: [{ id: brand }] } }), { status: 200 }),
])('fails closed if Inertia versions do not match', async (mismatch) => {
  vi.stubGlobal('fetch', vi.fn().mockResolvedValueOnce(issued()).mockResolvedValueOnce(mismatch()));
  render(<InvitationDialog {...props} />);
  prepare();
  expect(await screen.findByText(/Результат неизвестен/)).toBeTruthy();
  expect(screen.queryByLabelText('Ссылка приглашения')).toBeNull();
});

it('clears an already displayed link when the Inertia version changes', async () => {
  vi.stubGlobal('fetch', vi.fn().mockResolvedValueOnce(issued()).mockResolvedValueOnce(checked()));
  const { rerender } = render(<InvitationDialog {...props} />);
  prepare();
  const field = (await screen.findByLabelText('Ссылка приглашения')) as HTMLTextAreaElement;
  state.version = 'new-version';
  rerender(<InvitationDialog {...props} />);
  expect(screen.queryByLabelText('Ссылка приглашения')).toBeNull();
  expect(field.value).toBe('');
});

it('marks a hung issuance unknown even when fetch ignores abort', async () => {
  vi.stubGlobal(
    'fetch',
    vi.fn().mockImplementation(() => new Promise(() => {})),
  );
  render(<InvitationDialog {...props} />);
  vi.useFakeTimers();
  try {
    prepare();
    await act(async () => {
      await vi.advanceTimersByTimeAsync(15_001);
    });
    expect(screen.getByText(/Результат неизвестен/)).toBeTruthy();
    expect(screen.queryByLabelText('Ссылка приглашения')).toBeNull();
  } finally {
    vi.useRealTimers();
  }
});

it('warns on reopening when the dialog was closed during an in-flight attempt', () => {
  const fetcher = vi.fn().mockImplementation(() => new Promise(() => {}));
  vi.stubGlobal('fetch', fetcher);
  const { rerender } = render(<InvitationDialog {...props} />);
  prepare();
  fireEvent.click(screen.getByRole('button', { name: 'Закрыть' }));
  rerender(<InvitationDialog {...props} open={false} />);
  rerender(<InvitationDialog {...props} open />);
  expect(screen.getByText(/Результат неизвестен/)).toBeTruthy();
  expect(screen.queryByLabelText('Ссылка приглашения')).toBeNull();
  expect(fetcher).toHaveBeenCalledTimes(1);
});

it('erases a visible link when revalidation hangs despite abort', async () => {
  vi.stubGlobal(
    'fetch',
    vi
      .fn()
      .mockResolvedValueOnce(issued())
      .mockResolvedValueOnce(checked())
      .mockImplementation(() => new Promise(() => {})),
  );
  render(<InvitationDialog {...props} />);
  vi.useFakeTimers();
  try {
    prepare();
    await act(async () => {
      await Promise.resolve();
      await Promise.resolve();
      await Promise.resolve();
    });
    expect(screen.getByLabelText('Ссылка приглашения')).toBeTruthy();
    await act(async () => {
      await vi.advanceTimersByTimeAsync(10_001);
    });
    expect(screen.queryByLabelText('Ссылка приглашения')).toBeNull();
  } finally {
    vi.useRealTimers();
  }
});

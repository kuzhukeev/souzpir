import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import type { ReactNode } from 'react';
import Profile from './Profile';
import { beginProfileContextChange, finishProfileContextChange } from '@/features/Shell/Profile/contextFence';

const mocks = vi.hoisted(() => ({ put: vi.fn() }));
vi.mock('@inertiajs/react', () => ({
  usePage: () => ({ version: 'test-assets-version', props: { contextLeaseTag: 'a'.repeat(64) } }),
  useForm: (data: Record<string, unknown>) => ({ data, errors: {}, processing: false, put: mocks.put, post: vi.fn(), delete: vi.fn(), reset: vi.fn(), clearErrors: vi.fn(), transform: vi.fn(), setData: vi.fn() }),
}));
vi.mock('@/features/Shell/Profile/hooks', () => ({
  useProfileText: () => (key: string) => key,
  useFeedback: () => ({ notice: '', transport: '', clear: vi.fn(), setNotice: vi.fn(), failure: vi.fn() }),
}));
vi.mock('@/features/Shell/Profile/shared', () => ({
  ProfilePage: ({ children }: { children: ReactNode }) => <main data-testid="private-profile-page">{children}</main>,
  Notice: () => null, Errors: () => null, panel: 'panel',
}));
vi.mock('@/components/ui', () => ({
  Badge: ({ children }: { children: ReactNode }) => <span>{children}</span>,
  Button: ({ children, type, onClick }: { children: ReactNode; type?: 'button' | 'submit'; onClick?: () => void }) => <button type={type ?? 'button'} onClick={onClick}>{children}</button>,
  Input: ({ value }: { value?: string }) => <input readOnly value={value ?? ''} />,
  Combobox: () => null, Select: () => null, Modal: () => null,
}));

const props = {
  profile: {
    name: 'Синтетический пользователь', email: 'profile@example.test', phone: null,
    timezone: null, effective_timezone: 'UTC', brand_timezone: 'UTC', language: 'ru' as const,
    avatar_url: null, role: 'viewer', organization_name: 'Синтетическая компания', pending_email: null,
    email_change_available: false, email_confirmation_secure: false,
  },
  timezones: ['UTC'], profileBrandRevision: 'a'.repeat(64),
};
function response(revision: string) {
  return { ok: true, headers: { get: () => 'true' }, json: async () => ({ props: { profileBrandRevision: revision } }) };
}

beforeEach(() => { localStorage.clear(); });
afterEach(() => { cleanup(); localStorage.clear(); vi.unstubAllGlobals(); });

describe('profile response revision', () => {
  it('renders a viewer profile after a fresh matching response and sends the exact revision on save', async () => {
    const fetchMock = vi.fn().mockResolvedValue(response(props.profileBrandRevision));
    vi.stubGlobal('fetch', fetchMock);
    render(<Profile {...props} />);
    expect(screen.queryByDisplayValue(props.profile.name)).toBeNull();
    expect(screen.queryByTestId('private-profile-page')).toBeNull();
    expect(await screen.findByDisplayValue(props.profile.name)).toBeTruthy();
    expect(screen.getByTestId('private-profile-page')).toBeTruthy();
    expect(fetchMock).toHaveBeenCalledWith('/profile', expect.objectContaining({ headers: expect.objectContaining({ 'X-Inertia-Version': 'test-assets-version' }) }));
    fireEvent.click(screen.getByRole('button', { name: 'save' }));
    expect(mocks.put).toHaveBeenCalledWith('/profile', expect.objectContaining({ headers: { 'X-Brand-Revision': props.profileBrandRevision } }));
  });

  it('never renders a late response for session A after login to session B even when their counters both start at zero', async () => {
    // Both opaque values correspond to revision 0, but are bound to different leases.
    const fetchMock = vi.fn().mockResolvedValue(response('b'.repeat(64)));
    vi.stubGlobal('fetch', fetchMock);
    render(<Profile {...props} />);
    await waitFor(() => expect(fetchMock).toHaveBeenCalled());
    expect(screen.queryByDisplayValue(props.profile.name)).toBeNull();
    expect(screen.queryByTestId('private-profile-page')).toBeNull();
    await waitFor(() => expect(screen.getByRole('status').textContent).toContain('Не удалось подтвердить доступ'));
  });

  it('rejects malformed and legacy numeric tokens before checking the server', async () => {
    const fetchMock = vi.fn();
    vi.stubGlobal('fetch', fetchMock);
    render(<Profile {...props} profileBrandRevision="0" />);
    expect(await screen.findByRole('status')).toBeTruthy();
    expect(fetchMock).not.toHaveBeenCalled();
    expect(screen.queryByDisplayValue(props.profile.name)).toBeNull();
  });

  it('hides visible private content synchronously when a switch starts', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(response(props.profileBrandRevision)));
    render(<Profile {...props} />);
    const name = await screen.findByDisplayValue(props.profile.name);
    const id = beginProfileContextChange();
    expect(name.closest('[data-private-profile]')?.hasAttribute('hidden')).toBe(true);
    finishProfileContextChange(id);
  });
});

import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import type { ChangeEvent, ReactNode } from 'react';
import { TimingDraftForm } from './TimingDraftForm';

const mocks = vi.hoisted(() => ({ submit: vi.fn() }));
vi.mock('@inertiajs/react', async () => {
  const { useState } = await import('react');
  return {
    useForm: (initial: Record<string, string | null>) => {
      const [data, setData] = useState(initial);
      return {
        data,
        errors: {},
        processing: false,
        setData: (name: string, value: string | null) => setData((previous) => ({ ...previous, [name]: value })),
        put: (url: string, options: unknown) => mocks.submit(url, options, data),
      };
    },
  };
});
vi.mock('@/features/Shell/Profile/hooks', async () => {
  const { profileCopy } = await import('./copy');
  return {
    useProfileText: () => (key: keyof typeof profileCopy) => profileCopy[key],
    useFeedback: () => ({ notice: '', transport: '', clear: vi.fn(), setNotice: vi.fn(), failure: vi.fn() }),
  };
});
vi.mock('@/features/Shell/Profile/shared', () => ({
  Notice: () => null,
  Errors: () => null,
}));
vi.mock('@/components/ui', () => ({
  Button: ({ children, type }: { children: ReactNode; type?: 'button' | 'submit' }) => <button type={type}>{children}</button>,
  TimePicker: ({ label, value, onChange }: { label: string; value: string; onChange: (event: ChangeEvent<HTMLInputElement>) => void }) => <label>{label}<input type="time" value={value} onChange={onChange} /></label>,
}));

afterEach(() => { cleanup(); mocks.submit.mockClear(); });

describe('notification timing draft', () => {
  it('shows saved values, edits them and submits the session-bound brand revision', () => {
    const revision = 'a'.repeat(64);
    render(<TimingDraftForm draft={{ quiet_start: '22:00', quiet_end: '07:00', digest_time: null }} revision={revision} />);
    const start = screen.getByLabelText('Начало тихих часов') as HTMLInputElement;
    const end = screen.getByLabelText('Окончание тихих часов') as HTMLInputElement;
    const digest = screen.getByLabelText('Время ежедневной сводки') as HTMLInputElement;
    expect(start.value).toBe('22:00');
    expect(end.value).toBe('07:00');
    expect(digest.value).toBe('');
    fireEvent.change(start, { target: { value: '23:00' } });
    fireEvent.change(digest, { target: { value: '08:30' } });
    fireEvent.click(screen.getByRole('button', { name: 'Сохранить черновик' }));
    expect(mocks.submit).toHaveBeenCalledWith('/profile/notifications/timing', expect.objectContaining({ headers: { 'X-Brand-Revision': revision } }), { quiet_start: '23:00', quiet_end: '07:00', digest_time: '08:30' });
  });

  it('clears a value explicitly instead of inventing a default time', () => {
    render(<TimingDraftForm draft={{ quiet_start: '22:00', quiet_end: '07:00', digest_time: '08:30' }} revision={'b'.repeat(64)} />);
    fireEvent.change(screen.getByLabelText('Время ежедневной сводки'), { target: { value: '' } });
    fireEvent.click(screen.getByRole('button', { name: 'Сохранить черновик' }));
    expect(mocks.submit).toHaveBeenCalledWith(expect.any(String), expect.anything(), expect.objectContaining({ digest_time: null }));
  });
});

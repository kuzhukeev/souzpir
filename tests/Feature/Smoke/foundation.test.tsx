import { cleanup, render, screen } from '@testing-library/react';
import { axe } from 'jest-axe';
import type { ComponentType } from 'react';
import { afterEach, expect, it, vi } from 'vitest';

const captured = vi.hoisted(() => ({
  resolve: undefined as undefined | ((name: string) => Promise<ComponentType>),
}));
vi.mock('@inertiajs/react', () => ({
  createInertiaApp: (options: { resolve: (name: string) => Promise<ComponentType> }) => {
    captured.resolve = options.resolve;
  },
}));
vi.mock('react-dom/client', () => ({ createRoot: vi.fn() }));
await import('../../../resources/js/app');
afterEach(cleanup);

it('renders an accessible Russian foundation preview without external integrations', async () => {
  const Preview = await captured.resolve!('Foundation/Smoke');
  const { container } = render(<Preview />);
  expect(screen.getByRole('heading', { level: 1 }).textContent).toContain('Союз Пир');
  expect(screen.getByRole('main')).toBeTruthy();
  expect((await axe(container)).violations).toEqual([]);
});

it('fails clearly when an undiscovered page is requested', async () => {
  await expect(captured.resolve!('Missing/Page')).rejects.toThrow('Inertia page is not installed');
});

import { renderHook } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { usePermissions } from './usePermissions';

const state = vi.hoisted(() => ({ can: undefined as Record<string, boolean> | undefined }));
vi.mock('@inertiajs/react', () => ({ usePage: () => ({ props: { can: state.can } }) }));

describe('usePermissions', () => {
  beforeEach(() => {
    state.can = undefined;
  });

  it('fails closed when props or abilities are absent', () => {
    const { result } = renderHook(usePermissions);
    expect(result.current.can('team.write')).toBe(false);
    expect(result.current.permissions).toEqual({});
  });

  it('uses only an explicit true value', () => {
    state.can = { 'team.write': false, 'seller.dialogs.write': true };
    const { result } = renderHook(usePermissions);
    expect(result.current.can('seller.dialogs.write')).toBe(true);
    expect(result.current.can('team.write')).toBe(false);
    expect(result.current.can('unknown')).toBe(false);
  });
});

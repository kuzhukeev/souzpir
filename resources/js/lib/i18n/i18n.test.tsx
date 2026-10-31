import { renderHook } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { createTranslator, useTranslation } from './index';
import { usePermissions } from '@/hooks/usePermissions';
import type { PermissionAbility } from '@/types/generated/permissions';
import type { SharedPropsV1 } from '@/types/generated/data';
import { route } from 'ziggy-js';

const mocks = vi.hoisted(() => ({ props: {} as Record<string, unknown> }));
vi.mock('@inertiajs/react', () => ({ usePage: () => ({ props: mocks.props }) }));

describe('typed translations', () => {
  const key = 'Tenancy/Access/messages.invalid_role';
  it('interpolates text values without treating them as HTML', () => {
    const t = createTranslator({ [key]: 'Привет, :name! :name — :count' });
    expect(t(key, { name: '<img>', count: 2 })).toBe('Привет, <img>! <img> — 2');
    expect(t(key)).toBe('Привет, :name! :name — :count');
  });
  it('shows missing keys and reads only current Inertia props', () => {
    mocks.props = { translations: { [key]: 'Значение' } };
    const { result, rerender } = renderHook(useTranslation);
    expect(result.current(key)).toBe('Значение');
    mocks.props = { translations: {} };
    rerender();
    expect(result.current(key)).toBe(key);
  });
});

describe('public permission hook', () => {
  it('is false for absent grants, true only for an explicit true', () => {
    mocks.props = { can: { 'team.write': true, 'seller.dialogs.write': false } };
    const { result, rerender } = renderHook(usePermissions);
    expect(result.current.can('team.write')).toBe(true);
    expect(result.current.can('seller.dialogs.write')).toBe(false);
    expect(result.current.can('unexpected' as PermissionAbility)).toBe(false);
    mocks.props = {};
    rerender();
    expect(result.current.can('team.write')).toBe(false);
  });
});

it('generated Ziggy props can be passed directly to the typed route helper', () => {
  const ziggy: SharedPropsV1['ziggy'] = {
    url: 'https://example.test',
    port: null,
    defaults: {},
    routes: { home: { uri: '/', methods: ['GET', 'HEAD'] } },
    location: { host: 'example.test', pathname: '/', search: '' },
  };
  expect(route('home', undefined, true, ziggy)).toBe('https://example.test');
  const checkUnknownRoute = () => {
    // @ts-expect-error Route names are generated and unknown names must fail typechecking.
    route('unknown.route');
  };
  expect(checkUnknownRoute).toBeTypeOf('function');
});

import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, expect, it, vi } from 'vitest';
import Roles from '@/pages/Shell/Team/Roles';
import { parseMatrix, roleKeys, sectionAccess, sections, type RoleKey } from './roleMatrix';

vi.mock('@inertiajs/react', () => ({ Head: () => null }));
vi.mock('@/layouts/CabinetLayout', () => ({
  default: ({ children }: { children: ReactNode }) => <main>{children}</main>,
}));
afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

const labels = ['Владелец', 'Администратор', 'Редактор', 'Менеджер', 'Наблюдатель'];
const roles = roleKeys.map((key, index) => ({ key, label: labels[index] }));
const flags = { custom_roles_available: false, per_module_grants_available: false };
const ability = (name: string, allowed: RoleKey[]) => ({
  ability: name,
  roles: Object.fromEntries(roleKeys.map((key) => [key, allowed.includes(key)])),
});
const payload = {
  roles,
  limitations: flags,
  abilities: [
    ability('team.read', ['owner', 'admin', 'viewer']),
    ability('team.write', ['owner', 'admin']),
    ability('team.manage', ['owner', 'admin']),
    ability('vision.report.read', ['owner', 'manager']),
    ability('vision.measure.read', ['owner']),
    ability('vision.settings.read', ['owner']),
    ability('reports.read', ['owner', 'manager']),
    ability('vision.measure.run', ['owner']),
    ability('vision.actions.write', ['owner']),
    ability('vision.quiz.write', ['owner']),
    ability('vision.settings.write', ['owner']),
  ],
};

it('выводит частичный доступ и не принимает отсутствие действий за запрет', () => {
  const matrix = parseMatrix(payload);
  expect(sectionAccess(matrix, 'owner', sections[0])).toBe('Полный');
  expect(sectionAccess(matrix, 'viewer', sections[0])).toBe('Чтение');
  expect(sectionAccess(matrix, 'editor', sections[0])).toBe('Нет');
  expect(sectionAccess(matrix, 'manager', sections[2])).toBe('Частично');
  expect(sectionAccess(matrix, 'owner', sections[1])).toBe('Нет данных');
  const extended = parseMatrix({ ...payload, abilities: [...payload.abilities, ability('team.future', ['owner'])] });
  expect(sectionAccess(extended, 'owner', sections[0])).toBe('Нет данных');
});

it('отвергает повреждённые и неоднозначные данные вместо выдачи прав', () => {
  expect(() => parseMatrix({ ...payload, roles: [...roles, roles[0]] })).toThrow();
  expect(() =>
    parseMatrix({ ...payload, abilities: [...payload.abilities, payload.abilities[0]] }),
  ).toThrow();
  expect(() =>
    parseMatrix({ ...payload, abilities: [{ ability: 'team.read', roles: { owner: 'true' } }] }),
  ).toThrow();
  expect(() => parseMatrix({ ...payload, limitations: {} })).toThrow();
});

it('показывает загрузку и данные реального ответа без ложного полного доступа', async () => {
  let resolve: (response: unknown) => void = () => {};
  vi.stubGlobal(
    'fetch',
    vi.fn(
      () =>
        new Promise((done) => {
          resolve = done;
        }),
    ),
  );
  render(<Roles />);
  expect(screen.getByRole('status').textContent).toContain('Загружаем');
  resolve({ ok: true, json: async () => payload });
  const table = await screen.findByRole('region', { name: 'Таблица матрицы ролей' });
  expect(
    within(table).getByRole('rowheader', { name: 'Менеджер' }).parentElement?.textContent,
  ).toContain('Частично');
  expect(screen.getByText('Показать права по действиям')).toBeTruthy();
  expect(screen.getByText('Создание собственных ролей пока недоступно.')).toBeTruthy();
});

it('показывает ошибку и повторяет запрос по кнопке', async () => {
  const request = vi
    .fn()
    .mockResolvedValueOnce({ ok: false })
    .mockResolvedValueOnce({ ok: true, json: async () => payload });
  vi.stubGlobal('fetch', request);
  render(<Roles />);
  expect((await screen.findByRole('alert')).textContent).toContain('Не удалось загрузить');
  fireEvent.click(screen.getByRole('button', { name: 'Повторить' }));
  expect(await screen.findByRole('region', { name: 'Таблица матрицы ролей' })).toBeTruthy();
  expect(request).toHaveBeenCalledTimes(2);
});

it('не показывает фиктивные права для пустого ответа', async () => {
  vi.stubGlobal(
    'fetch',
    vi
      .fn()
      .mockResolvedValue({
        ok: true,
        json: async () => ({ roles: [], abilities: [], limitations: flags }),
      }),
  );
  render(<Roles />);
  expect(
    await screen.findByText('Матрица ролей пока недоступна. Попробуйте обновить страницу позже.'),
  ).toBeTruthy();
});

it('предупреждает о новом неизвестном действии и не раскрывает его технический ключ', async () => {
  const extended = { ...payload, abilities: [...payload.abilities, ability('team.future', ['owner'])] };
  vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: true, json: async () => extended }));
  render(<Roles />);
  expect(await screen.findByText('Часть действий пока не отображается в таблице.')).toBeTruthy();
  expect(screen.queryByText('team.future')).toBeNull();
  const table = screen.getByRole('region', { name: 'Таблица матрицы ролей' });
  expect(within(table).getByRole('rowheader', { name: 'Владелец' }).parentElement?.textContent).toContain('Нет данных');
});

export const roleKeys = ['owner', 'admin', 'editor', 'manager', 'viewer'] as const;
export type RoleKey = (typeof roleKeys)[number];
export const roleDescriptions: Record<RoleKey, string> = {
  owner: 'Управляет компанией и доступом участников.',
  admin: 'Настраивает доступные бренды и команду.',
  editor: 'Работает с содержимым и настройками в рамках доступных прав.',
  manager: 'Работает с диалогами и переданными задачами.',
  viewer: 'Просматривает доступные данные без изменения настроек.',
};
export type MatrixRole = { key: RoleKey; label: string };
export type MatrixAbility = { ability: string; roles: Record<RoleKey, boolean> };
export type Matrix = {
  roles: MatrixRole[];
  abilities: MatrixAbility[];
  limitations: { customRolesAvailable: boolean; perModuleGrantsAvailable: boolean };
};

type Section = { key: string; label: string; read: string[]; write: string[]; prefixes: string[] };
export const sections: Section[] = [
  { key: 'team', label: 'Команда', read: ['team.read'], write: ['team.write', 'team.manage'], prefixes: ['team.'] },
  {
    key: 'billing',
    label: 'Тариф',
    read: ['billing.read'],
    write: ['billing.manage', 'org.billing'],
    prefixes: ['billing.'],
  },
  {
    key: 'vision',
    label: 'Видение',
    read: ['vision.report.read', 'vision.measure.read', 'vision.settings.read', 'reports.read'],
    write: [
      'vision.measure.run',
      'vision.actions.write',
      'vision.quiz.write',
      'vision.settings.write',
    ],
    prefixes: ['vision.'],
  },
  {
    key: 'dialogs',
    label: 'Диалоги',
    read: ['seller.dialogs.read'],
    write: ['seller.dialogs.write', 'seller.dialogues.operate'],
    prefixes: ['seller.dialogs.', 'seller.dialogues.'],
  },
  {
    key: 'knowledge',
    label: 'Знания',
    read: ['seller.knowledge.read'],
    write: ['seller.knowledge.write'],
    prefixes: ['seller.knowledge.'],
  },
];

// These labels describe individual existing Gates; unknown keys are not shown as
// technical text and never treated as a granted capability.
export const abilityLabels: Record<string, string> = {
  'org.read': 'Просмотр компании',
  'org.settings.write': 'Изменение настроек компании',
  'organization.manage': 'Управление компанией',
  'org.owner.transfer': 'Передача владения',
  'org.billing': 'Управление тарифом компании',
  'billing.manage': 'Управление тарифом',
  'billing.read': 'Просмотр тарифа',
  'team.read': 'Просмотр команды',
  'team.write': 'Изменение команды',
  'team.manage': 'Управление командой',
  'brand.read': 'Просмотр брендов',
  'brand.write': 'Изменение брендов',
  'integrations.read': 'Просмотр интеграций',
  'integrations.write': 'Изменение интеграций',
  'seller.dialogs.read': 'Просмотр диалогов',
  'seller.dialogs.write': 'Изменение диалогов',
  'seller.dialogues.operate': 'Работа с диалогами',
  'seller.customers.read': 'Просмотр клиентов',
  'seller.customers.write': 'Изменение клиентов',
  'seller.suggestions.read': 'Просмотр подсказок',
  'seller.suggestions.write': 'Работа с подсказками',
  'seller.knowledge.read': 'Просмотр знаний продавца',
  'seller.knowledge.write': 'Изменение знаний продавца',
  'seller.settings.read': 'Просмотр настроек продавца',
  'seller.settings.write': 'Изменение настроек продавца',
  'vision.report.read': 'Просмотр отчёта Видения',
  'vision.measure.read': 'Просмотр замеров',
  'vision.measure.run': 'Запуск замеров',
  'vision.actions.write': 'Изменение действий Видения',
  'vision.quiz.write': 'Изменение анкеты Видения',
  'vision.settings.read': 'Просмотр настроек Видения',
  'vision.settings.write': 'Изменение настроек Видения',
  'reports.read': 'Просмотр отчётов',
  'data.export': 'Экспорт данных',
  'journal.read': 'Просмотр журнала',
  'journal.notifications.read': 'Просмотр журнала уведомлений',
  'journal.retry': 'Повтор уведомлений',
  'profile.own': 'Настройки собственного профиля',
};

function record(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

export function parseMatrix(value: unknown): Matrix {
  if (
    !record(value) ||
    !Array.isArray(value.roles) ||
    !Array.isArray(value.abilities) ||
    !record(value.limitations) ||
    typeof value.limitations.custom_roles_available !== 'boolean' ||
    typeof value.limitations.per_module_grants_available !== 'boolean'
  )
    throw new Error('Invalid matrix');
  const roles: MatrixRole[] = [];
  const seenRoles = new Set<string>();
  for (const role of value.roles) {
    if (
      !record(role) ||
      typeof role.key !== 'string' ||
      !roleKeys.includes(role.key as RoleKey) ||
      typeof role.label !== 'string' ||
      !role.label.trim() ||
      seenRoles.has(role.key)
    )
      throw new Error('Invalid role');
    seenRoles.add(role.key);
    roles.push({ key: role.key as RoleKey, label: role.label });
  }
  if (
    roles.length !== 0 &&
    (roles.length !== roleKeys.length || roleKeys.some((key) => !seenRoles.has(key)))
  )
    throw new Error('Incomplete roles');
  const abilities: MatrixAbility[] = [];
  const seenAbilities = new Set<string>();
  for (const item of value.abilities) {
    if (
      !record(item) ||
      typeof item.ability !== 'string' ||
      !item.ability ||
      !record(item.roles) ||
      seenAbilities.has(item.ability)
    )
      throw new Error('Invalid ability');
    const values = item.roles;
    if (roleKeys.some((key) => typeof values[key] !== 'boolean'))
      throw new Error('Invalid permissions');
    seenAbilities.add(item.ability);
    abilities.push({
      ability: item.ability,
      roles: Object.fromEntries(roleKeys.map((key) => [key, values[key]])) as Record<
        RoleKey,
        boolean
      >,
    });
  }
  return {
    roles,
    abilities,
    limitations: {
      customRolesAvailable: value.limitations.custom_roles_available,
      perModuleGrantsAvailable: value.limitations.per_module_grants_available,
    },
  };
}

export type Access = 'Полный' | 'Чтение' | 'Нет' | 'Частично' | 'Нет данных';
export function sectionAccess(matrix: Matrix, role: RoleKey, section: Section): Access {
  const values = new Map(matrix.abilities.map((item) => [item.ability, item.roles[role]]));
  const included = new Set([...section.read, ...section.write]);
  if (matrix.abilities.some((item) => section.prefixes.some((prefix) => item.ability.startsWith(prefix)) && !included.has(item.ability))) return 'Нет данных';
  const read = section.read.map((key) => values.get(key));
  const write = section.write.map((key) => values.get(key));
  if ([...read, ...write].some((value) => value === undefined)) return 'Нет данных';
  if ([...read, ...write].every(Boolean)) return 'Полный';
  if (read.every(Boolean) && write.every((value) => value === false)) return 'Чтение';
  if ([...read, ...write].every((value) => value === false)) return 'Нет';
  return 'Частично';
}

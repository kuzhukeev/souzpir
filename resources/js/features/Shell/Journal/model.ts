import { router } from '@inertiajs/react';

export type Filters = {
  from?: string;
  to?: string;
  actor?: string;
  module?: string;
  action?: string;
  channel?: string;
  status?: string;
};
export type Page<T> = { data: T[]; current_page: number; last_page: number; total: number };
export type Option = { value: string; label: string };
export const labels: Record<string, string> = {
  'knowledge.draft.created': 'Создан черновик знаний',
  'knowledge.draft.updated': 'Изменён черновик знаний',
  'knowledge.fact.created': 'Добавлен факт',
  'knowledge.fact.updated': 'Изменён факт',
  'knowledge.fact.deleted': 'Удалён факт',
  'knowledge.fact.moved': 'Перемещён факт',
  'knowledge.release.published': 'Опубликована версия знаний',
  'knowledge.release.activated': 'Восстановлена версия знаний',
  'notification.settings.updated': 'Изменены настройки уведомлений',
  'notification.delivery.retry_requested': 'Запрошена повторная доставка',
  'account.owner_registered': 'Создан аккаунт владельца',
  'account.invitation_registered': 'Создан аккаунт по приглашению',
  'account.invitation_accepted': 'Принято приглашение',
  'account.email.verified': 'Подтверждена почта',
  'account.password.reset': 'Обновлён пароль',
  'account.mfa.enabled': 'Включена двухфакторная проверка',
  'account.mfa.disabled': 'Отключена двухфакторная проверка',
};
export function actionLabel(value: string) {
  return labels[value] ?? 'Действие: ' + value;
}
export function formatDate(value: string) {
  const date = new Date(value);
  return Number.isNaN(date.valueOf())
    ? value
    : new Intl.DateTimeFormat('ru-RU', {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: 'Europe/Moscow',
      }).format(date);
}
export function compact(filters: Filters): Record<string, string> {
  return Object.fromEntries(
    Object.entries(filters).filter(([, value]) => value && value !== 'all'),
  ) as Record<string, string>;
}
export function go(path: string, filters: Filters, page?: number) {
  router.get(
    path,
    { ...compact(filters), ...(page ? { page } : {}) },
    { preserveState: true, preserveScroll: true, replace: true },
  );
}
